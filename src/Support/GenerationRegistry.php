<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Support;

use Rostam\Contracts\KvClient;

/**
 * Owns every generation counter a store depends on, and reads them together.
 *
 * A store has two: one for the cache, one for its locks. Whichever is asked for
 * first, the other is very likely wanted before the request is over - so a
 * refresh reloads *every* stale counter in one pipelined round trip. Two cold
 * counters therefore cost what one costs, and an app that only ever caches pays
 * an extra forty bytes on a round trip it was making anyway.
 *
 * The refresh interval is the whole trade: longer means fewer round trips and a
 * longer window in which this process still serves data another process has
 * already flushed.
 */
class GenerationRegistry
{
    /** @var list<string> */
    private array $registered = [];

    /** @var array<string, int> */
    private array $values = [];

    /** @var array<string, float> */
    private array $readAt = [];

    /**
     * @param  float  $refresh  seconds between re-reads; <0 never, 0 always
     */
    public function __construct(
        private readonly KvClient $client,
        private readonly float $refresh,
    ) {}

    /**
     * A named handle onto one counter.
     */
    public function generation(string $key): Generation
    {
        if (! in_array($key, $this->registered, true)) {
            $this->registered[] = $key;
        }

        return new Generation($this, $key);
    }

    public function current(string $key): int
    {
        if (! $this->isFresh($key)) {
            $this->reload();
        }

        return $this->values[$key] ?? 0;
    }

    /**
     * Move a counter on, abandoning everything written under its old value.
     */
    public function bump(string $key): int
    {
        $this->readAt[$key] = microtime(true);

        $known = $this->values[$key] ?? 0;

        return $this->values[$key] = $this->advance($key, $this->client->increment($key, 1), $known + 1);
    }

    public function forget(string $key): void
    {
        unset($this->values[$key], $this->readAt[$key]);
    }

    public function forgetAll(): void
    {
        $this->values = [];
        $this->readAt = [];
    }

    private function isFresh(string $key): bool
    {
        if (! isset($this->values[$key])) {
            return false;
        }

        return $this->refresh < 0 || microtime(true) - ($this->readAt[$key] ?? 0.0) < $this->refresh;
    }

    /**
     * Reload every stale counter at once.
     */
    private function reload(): void
    {
        $stale = array_values(array_filter($this->registered, fn (string $key) => ! $this->isFresh($key)));

        if ($stale === []) {
            return;
        }

        $raw = count($stale) === 1
            ? [$stale[0] => $this->client->get($stale[0])]
            : $this->client->getMany($stale);

        $now = microtime(true);

        foreach ($stale as $key) {
            $bytes = $raw[$key] ?? null;
            $read = ($bytes !== null && strlen($bytes) === 8) ? unpack('J', $bytes)[1] : 0;

            $this->values[$key] = $this->advance($key, $read, $this->values[$key] ?? 0);
            $this->readAt[$key] = $now;
        }
    }

    /**
     * Keep a counter monotonic, restoring it on the server when it has gone
     * backwards.
     *
     * A counter is an ordinary entry in the cache it governs, and Rostam's
     * default `PolicyRingbufEvict` overwrites "the oldest entries in the oldest
     * page" - write order, not LRU, so reading a counter does not keep it alive.
     * A counter is written once per flush and only read afterwards, so it ages
     * until it is evicted. Then `get` returns nothing, which reads as generation
     * zero, and `increment` recreates it at one. Either way the generation moves
     * BACKWARDS, and every key written under the generations in between becomes
     * reachable again - data somebody explicitly flushed, coming back. Worse, it
     * comes back unevenly: a process still holding the old value in memory keeps
     * writing to the high generation while a process that just re-read serves the
     * low one.
     *
     * So a value below what this process has already seen is not believed. It is
     * pushed back up with `increment`, which is atomic and returns the new value,
     * so two processes restoring at once merely overshoot - harmless, since that
     * only orphans one more generation, and both then agree on what they are
     * handed. (`set_nx` would not converge: the loser keeps its own higher value
     * and no later read ever reconciles the two.)
     *
     * The floor is per process, so a freshly started one cannot know the counter
     * was ever higher. Nothing a client can do fixes that; only a real flush op
     * on the server would.
     *
     * @param  int  $read  what the server just reported
     * @param  int  $atLeast  the smallest value this process will accept: the
     *                        generation it already knows for a refresh, one past
     *                        it for a flush, which has to land somewhere new
     */
    private function advance(string $key, int $read, int $atLeast): int
    {
        return $read >= $atLeast ? $read : $this->client->increment($key, $atLeast - $read);
    }
}
