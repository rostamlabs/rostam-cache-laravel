<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Tags;

use Illuminate\Cache\TagSet;
use Illuminate\Contracts\Cache\Store;
use Rostam\Cache\Contracts\StoresManyForever;

/**
 * A tag set that survives an evicting store, and reads the whole set at once.
 *
 * WHY IT EXISTS. Laravel folds a random per-tag id into every tagged key's name
 * and stores that id with `forever()`. On Redis, whose default policy is
 * `noeviction`, the id stays put. A single-node rostam evicts instead, and its
 * default `PolicyRingbufEvict` overwrites the oldest entries in the oldest page
 * - write order, not LRU, so READING the id does nothing to keep it alive.
 * (`-relocating-eviction -sieve-visited-bit` does spare recently read records,
 * best-effort; this class does not assume the server was started with them.)
 * An id written once at
 * boot and only ever read is therefore first in line, and when it goes the next
 * read mints a replacement: every entry under that tag becomes unreachable at
 * once, silently, while the cache is otherwise healthy and the data is still
 * sitting there.
 *
 * THE FIX, AND WHY IT IS FREE. The id carries the time it was written, so the
 * `get` this class already had to make also answers "how old is it" - no extra
 * round trip on the read path. Only when the id is older than the refresh
 * interval is it written back, which puts it at the young end of the ring
 * buffer again. That is at most one write per tag per interval across the whole
 * fleet, not per process and not per request, because the timestamp is shared
 * state rather than something each worker remembers for itself.
 *
 * Two processes can decide to refresh at the same moment. Both write the SAME
 * id with a fresh stamp, so the race has no loser and nothing to reconcile.
 *
 * AND WHILE WE ARE HERE. TagSet::tagIds() maps tagId() over the names, so
 * `tags(['a', 'b', 'c'])` costs three sequential round trips before the real
 * work starts. The Store contract already has many(), which this driver answers
 * with one pipelined exchange, so the whole set now costs one.
 *
 * The writes go the same way. Every tag of a set can age past the refresh
 * interval together, and a flush resets every tag at once; written one by one,
 * a set of five tags cost five more sequential round trips. On a store that
 * implements {@see StoresManyForever} they are collected and written in one.
 *
 * WHAT IT DOES NOT DO. A refresh happens when something touches the tag, so a
 * tag nobody reads for longer than the cache takes to turn over still loses its
 * id. That is the harmless half of the problem: nothing was using the tag, so
 * what follows is a cold miss rather than the surprise invalidation of data
 * somebody was relying on. Nor does it keep the tagged VALUES alive - they age
 * like any other entry, which is what a cache is for.
 *
 * Measured on a single-shard server, one tagged write followed only by reads
 * while 1.28 GB of unrelated traffic went past:
 *
 *     refresh off  ->  tagged read: NULL
 *     refresh 1s   ->  tagged read: 'Anne'
 *
 * It depends on nothing but Store, so it is not specific to this driver: any
 * cache whose entries can be evicted has this problem.
 */
class RefreshingTagSet extends TagSet
{
    /** Marks a stored id as carrying its own write time. */
    private const STAMPED = 'stamped';

    /**
     * Writes held back while a whole set is being resolved or reset, keyed by
     * tag key; null when each write goes out on its own.
     *
     * @var array<string, array{0: string, 1: string, 2: int}>|null
     */
    private ?array $deferred = null;

    /**
     * @param  int  $refresh  seconds before a tag id is rewritten to keep it
     *                        young; 0 disables refreshing entirely
     */
    public function __construct(
        Store $store,
        array $names = [],
        private readonly int $refresh = 300,
        private $clock = null,
    ) {
        parent::__construct($store, $names);
    }

    /**
     * Every id in the set, in one round trip, refreshing whatever has aged in
     * at most one more round trip.
     *
     * @return array<int, string>
     */
    protected function tagIds()
    {
        if ($this->names === []) {
            return [];
        }

        $keys = array_map($this->tagKey(...), $this->names);
        $stored = $this->store->many($keys);

        $ids = [];

        $this->writingOnce(function () use ($keys, $stored, &$ids): void {
            foreach ($this->names as $index => $name) {
                $ids[] = $this->resolve($name, $stored[$keys[$index]] ?? null);
            }
        });

        return $ids;
    }

    /**
     * Reset every tag in the set, writing the new ids in one round trip.
     *
     * @return void
     */
    public function reset()
    {
        $this->writingOnce(function (): void {
            foreach ($this->names as $name) {
                $this->resetTag($name);
            }
        });
    }

    /**
     * The single-name path, kept consistent with the batched one.
     */
    public function tagId($name)
    {
        return $this->resolve($name, $this->store->get($this->tagKey($name)));
    }

    /**
     * Reset the tag, stamping the new id with the moment it was written.
     */
    public function resetTag($name)
    {
        $id = str_replace('.', '', uniqid('', true));

        $this->write($name, $id);

        return $id;
    }

    /**
     * Turn whatever was stored into a usable id, refreshing or minting as needed.
     *
     * A value that is not stamped came from a plain TagSet - an older release of
     * this package, or the same cache shared with one. It is a perfectly good
     * id, so it is kept and simply rewritten in the stamped form; the tag does
     * not lose its entries just because the bookkeeping changed shape.
     */
    private function resolve(string $name, mixed $stored): string
    {
        if (is_string($stored) && $stored !== '') {
            $this->write($name, $stored);         // unstamped legacy id: adopt and stamp it

            return $stored;
        }

        if (! is_array($stored)
            || ($stored[0] ?? null) !== self::STAMPED
            || ! is_string($stored[1] ?? null)
            || $stored[1] === ''
            || ! is_int($stored[2] ?? null)) {
            return $this->resetTag($name);        // absent, evicted, or unreadable
        }

        [, $id, $writtenAt] = $stored;

        if ($this->refresh > 0 && $this->now() - $writtenAt >= $this->refresh) {
            $this->write($name, $id);
        }

        return $id;
    }

    /**
     * Store an id together with the time it was written.
     *
     * A list rather than a hash: it is the smallest thing the store's serializer
     * can carry that still says what it is, and this value is read on the hot
     * path of every tagged operation.
     */
    private function write(string $name, string $id): void
    {
        $value = [self::STAMPED, $id, $this->now()];

        if ($this->deferred !== null) {
            $this->deferred[$this->tagKey($name)] = $value;

            return;
        }

        $this->store->forever($this->tagKey($name), $value);
    }

    /**
     * Run $work with its writes held back, then send them together: in one
     * round trip on a store that can, one by one on any other. A single
     * write goes out as forever() either way - it is one round trip already.
     */
    private function writingOnce(callable $work): void
    {
        $this->deferred = [];

        try {
            $work();
            $writes = $this->deferred;
        } finally {
            $this->deferred = null;
        }

        if (count($writes) > 1 && $this->store instanceof StoresManyForever) {
            $this->store->foreverMany($writes);

            return;
        }

        foreach ($writes as $key => $value) {
            $this->store->forever($key, $value);
        }
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
