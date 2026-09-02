<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache;

use Illuminate\Cache\TaggableStore;
use Illuminate\Cache\TaggedCache;
use Illuminate\Cache\TagSet;
use Illuminate\Contracts\Cache\CanFlushLocks;
use Illuminate\Contracts\Cache\LockProvider;
use InvalidArgumentException;
use Rostam\Cache\Contracts\CacheNamespace;
use Rostam\Cache\Contracts\ValueSerializer;
use Rostam\Cache\Contracts\WipesTheServer;
use Rostam\Cache\Namespacing\GenerationalNamespace;
use Rostam\Cache\Namespacing\ServerFlushNamespace;
use Rostam\Cache\Namespacing\StaticNamespace;
use Rostam\Cache\Serialization\PhpSerializer;
use Rostam\Cache\Support\Generation;
use Rostam\Cache\Support\GenerationRegistry;
use Rostam\Cache\Tags\RefreshingTagSet;
use Rostam\Contracts\KvClient;
use Rostam\Exceptions\ServerException;

/**
 * A Laravel cache store backed by Rostam's key-value engine (v0.5.0+).
 *
 * It is an adapter and nothing more. Every decision it could have hard-coded is
 * a collaborator instead:
 *
 * - the wire is a {@see KvClient}, so a fake transport is a constructor argument;
 * - the value encoding is a {@see ValueSerializer}, so igbinary (or anything
 *   else) is a swap rather than a subclass;
 * - what a key looks like and what "flush" means is a {@see CacheNamespace},
 *   which is why rostam v0.6.0's flush op arrived here as a third
 *   implementation and not as a branch in this class;
 * - lock lifetimes hang off their own {@see Generation}, which is why
 *   `cache:clear` cannot release a running mutex (except in 'server' flush
 *   mode, where the wipe reaches everything) while `cache:clear --locks`
 *   can.
 *
 * {@see self::make()} wires the usual combination; the constructor is there for
 * anyone who wants a different one.
 *
 * The one rule the store itself owns: integers are stored as bare eight-byte
 * counters, because that is what the server's `incr_ex` accepts, and that is
 * what makes `increment()` a single atomic op that keeps its window.
 */
class RostamStore extends TaggableStore implements CanFlushLocks, LockProvider
{
    public function __construct(
        protected KvClient $client,
        protected CacheNamespace $namespace,
        protected ValueSerializer $serializer,
        protected Generation $lockGeneration,
        protected int $tagRefresh = 300,
    ) {}

    /**
     * Begin a tags operation.
     *
     * Overridden so the set is built by newTagSet() rather than hard-wired
     * here: a store that wants different tag behaviour subclasses that one
     * method instead of reimplementing this one.
     *
     * @param  mixed  $names
     */
    public function tags($names): TaggedCache
    {
        return new TaggedCache($this, $this->newTagSet(
            is_array($names) ? $names : func_get_args()
        ));
    }

    /**
     * The tag set this store uses.
     *
     * Laravel's own TagSet stores each tag id once with forever() and never
     * touches it again, which on a store that evicts by write order is the
     * first thing to go - taking every entry under that tag out of reach with
     * it. {@see RefreshingTagSet} keeps the ids young and reads the whole set
     * in one round trip.
     *
     * @param  array<int, string>  $names
     */
    protected function newTagSet(array $names): TagSet
    {
        return new RefreshingTagSet($this, $names, $this->tagRefresh);
    }

    /**
     * Assemble a store the ordinary way.
     *
     * @param  array<string, mixed>  $options  flush: 'epoch'|'server'|'unsupported', epoch_refresh: seconds,
     *                                         tag_refresh: seconds before a tag id is rewritten to stay young
     *
     * @throws InvalidArgumentException on a flush mode that does not exist
     */
    public static function make(
        KvClient $client,
        string $prefix = '',
        array $options = [],
        ?ValueSerializer $serializer = null,
    ): self {
        $registry = new GenerationRegistry($client, (float) ($options['epoch_refresh'] ?? 10));

        return new self(
            $client,
            // Named, not defaulted-through. This used to be "epoch, or else
            // static", so a typo picked the strategy that quietly refuses to
            // flush - and a store configured with 'server' would have been one
            // of those typos.
            match ($mode = (string) ($options['flush'] ?? 'epoch')) {
                'epoch' => new GenerationalNamespace($registry, $prefix),
                'server' => new ServerFlushNamespace($client, $prefix),
                'unsupported' => new StaticNamespace($prefix),
                default => throw new InvalidArgumentException(
                    "unknown flush mode [{$mode}]: expected 'epoch' (a generation number, the default), "
                    ."'server' (Rostam v0.6.0's flush op, which wipes the WHOLE server) or 'unsupported'."
                ),
            },
            $serializer ?? new PhpSerializer,
            $registry->generation($prefix.'#lock-epoch'),
            (int) ($options['tag_refresh'] ?? 300),
        );
    }

    public function get($key)
    {
        $raw = $this->client->get($this->namespace->qualify((string) $key));

        return $raw === null ? null : $this->serializer->unserialize($raw);
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys)
    {
        $keys = array_values($keys);

        if ($keys === []) {
            return [];
        }

        // Resolve the namespace once for the whole batch, not once per key.
        $namespace = $this->namespace->resolve();
        $prefixed = array_map(static fn ($key) => $namespace.$key, $keys);
        $raw = $this->client->getMany($prefixed);

        $results = [];

        foreach ($keys as $index => $key) {
            $value = $raw[$prefixed[$index]] ?? null;
            $results[$key] = $value === null ? null : $this->serializer->unserialize($value);
        }

        return $results;
    }

    public function put($key, $value, $seconds)
    {
        $this->client->put(
            $this->namespace->qualify((string) $key),
            $this->serializer->serialize($value),
            $this->ttl($seconds)
        );

        return true;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  int  $seconds
     */
    public function putMany(array $values, $seconds)
    {
        if ($values === []) {
            return true;
        }

        $ttl = $this->ttl($seconds);
        $namespace = $this->namespace->resolve();
        $entries = [];

        foreach ($values as $key => $value) {
            $entries[] = [$namespace.$key, $this->serializer->serialize($value), $ttl];
        }

        $this->client->putMany($entries);

        return true;
    }

    /**
     * Store only if the key is not already there.
     *
     * Laravel calls this instead of its own get-then-put whenever the store
     * offers it, and here it is a single atomic `set_nx`: two processes racing
     * on the same key cannot both be told they won.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  int  $seconds
     */
    public function add($key, $value, $seconds)
    {
        return $this->client->setNx(
            $this->namespace->qualify((string) $key),
            $this->serializer->serialize($value),
            $this->ttl($seconds)
        );
    }

    public function forever($key, $value)
    {
        $this->client->put(
            $this->namespace->qualify((string) $key),
            $this->serializer->serialize($value),
            0
        );

        return true;
    }

    /**
     * Read a value and remove it, atomically.
     *
     * Laravel's own `Cache::pull()` is a get followed by a forget and does not
     * reach this; call it on the store when the atomicity matters.
     */
    public function pull(string $key): mixed
    {
        $raw = $this->client->getdel($this->namespace->qualify($key));

        return $raw === null ? null : $this->serializer->unserialize($raw);
    }

    /**
     * Increment a counter server-side, in one atomic op.
     *
     * The key keeps whatever expiry it already had - `incr_ex` rewrites the
     * value against the stored absolute deadline - so a throttle counter's
     * window neither slides nor is lost. A counter this call creates has no
     * expiry, matching Redis.
     *
     * Returns false when the key holds something that is not an integer this
     * driver wrote: the server refuses to increment anything that is not
     * exactly eight bytes.
     */
    public function increment($key, $value = 1)
    {
        return $this->counter((string) $key, (int) $value, 0);
    }

    public function decrement($key, $value = 1)
    {
        return $this->counter((string) $key, -(int) $value, 0);
    }

    /**
     * Increment a counter, opening a fixed window on the first hit.
     *
     * The window is stamped only when this call creates the key, so later hits
     * inside it do not extend it - the fixed-window rate-limit primitive, in
     * one round trip.
     */
    public function incrementWithin(string $key, int $seconds, int $value = 1): int|bool
    {
        return $this->counter($key, $value, max(1, $seconds));
    }

    public function forget($key)
    {
        return $this->client->del($this->namespace->qualify((string) $key));
    }

    /**
     * Remove several keys in one round trip.
     *
     * @param  array<int, string>  $keys
     */
    public function forgetMany(array $keys): bool
    {
        $keys = array_values($keys);

        if ($keys === []) {
            return true;
        }

        $namespace = $this->namespace->resolve();

        $this->client->delMany(array_map(static fn ($key) => $namespace.$key, $keys));

        return true;
    }

    /**
     * Set the expiration of an existing item (the Laravel 13 contract).
     *
     * @param  string  $key
     * @param  int  $seconds
     */
    public function touch($key, $seconds)
    {
        return $this->expire((string) $key, max(1, (int) $seconds));
    }

    /**
     * Give an existing key a new TTL without rewriting its value.
     *
     * @param  int  $seconds  0 clears the expiry entirely
     */
    public function expire(string $key, int $seconds): bool
    {
        $qualified = $this->namespace->qualify($key);

        return $seconds > 0
            ? $this->client->expire($qualified, $seconds)
            : $this->client->persist($qualified);
    }

    /**
     * Remaining lifetime in seconds: -2 when the key is gone, -1 when it has no
     * expiry, otherwise the seconds left (rounded up).
     */
    public function ttlOf(string $key): int
    {
        return $this->client->ttl($this->namespace->qualify($key));
    }

    /**
     * Abandon every key in the store, however the namespace decides to.
     */
    public function flush()
    {
        $this->namespace->flush();

        // A server-wide flush deletes the lock counter along with everything
        // else, and a deleted counter reads back as zero. That is the one
        // direction it must never move: a process that still has the old number
        // cached would keep taking locks under it while a freshly started one
        // took them under zero, and the same mutex would be held twice with
        // neither side able to see the other.
        //
        // Bumping is also simply what happened - the wipe released every lock
        // there was - and it puts the counter back above the value this process
        // had, which every other process then converges up to on its next read
        // because generations only ever advance.
        if ($this->namespace instanceof WipesTheServer) {
            $this->lockGeneration->bump();
        }

        return true;
    }

    /**
     * Release every lock this store holds.
     *
     * This is what `php artisan cache:clear --locks` calls.
     */
    public function flushLocks(): bool
    {
        $this->lockGeneration->bump();

        return true;
    }

    /**
     * Locks live in the same Rostam store as the cache, just under their own
     * generation.
     */
    public function hasSeparateLockStore(): bool
    {
        return false;
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        return new RostamLock($this->client, $this->lockKey((string) $name), (int) $seconds, $owner);
    }

    public function restoreLock($name, $owner)
    {
        return $this->lock($name, 0, $owner);
    }

    public function getPrefix()
    {
        return $this->namespace->prefix();
    }

    public function setPrefix(string $prefix): self
    {
        $this->namespace = $this->namespace->withPrefix($prefix);

        return $this;
    }

    public function getClient(): KvClient
    {
        return $this->client;
    }

    public function getSerializer(): ValueSerializer
    {
        return $this->serializer;
    }

    public function getNamespace(): CacheNamespace
    {
        return $this->namespace;
    }

    /**
     * The full key as it is written on the server.
     */
    public function key(string $key): string
    {
        return $this->namespace->qualify($key);
    }

    /**
     * Forget the cached generation numbers so the next operation re-reads them.
     */
    public function refreshGenerations(): self
    {
        $this->namespace->reset();
        $this->lockGeneration->forget();

        return $this;
    }

    /**
     * Locks live outside the cache generation on purpose: `cache:clear` should
     * not silently hand a running mutex to a second process. They carry their
     * own generation so `cache:clear --locks` still has something to bump.
     *
     * The exception is a store on `'flush' => 'server'`, where a flush is the
     * engine's own and spares nothing: the lock keys go with everything else.
     * {@see flush()} bumps this counter when that happens, because a counter
     * that came back as zero would be a second lock namespace rather than a
     * cleared one.
     */
    protected function lockKey(string $name): string
    {
        return $this->namespace->prefix().'#lock:'.$this->lockGeneration->current().':'.$name;
    }

    /**
     * The one place a counter op is issued, so its failure mode is stated once.
     *
     * Laravel's contract for an increment that cannot happen is false, not an
     * exception, and the ordinary reason is that the value under the key is not
     * an 8-byte counter - somebody cached a string where a tally belongs.
     *
     * THE LIMIT OF THAT, STATED PLAINLY. A real rostam-server answers this with
     * a bare "internal error" carrying nothing to match on, so a server that is
     * genuinely in trouble - a full shard, a revoked key - is indistinguishable
     * from a type mismatch, and both arrive here as false. The package fake
     * returns a specific message and made this look better than it is. Until the
     * server distinguishes them, an increment that returns false is worth
     * looking at rather than assuming.
     *
     * A ProtocolException is NOT swallowed. That one means the framing came back
     * wrong - the stream is out of step with its answers - and reporting it as
     * "this key is not a counter" would hide a broken connection behind a
     * plausible application-level result. It belongs to the caller.
     */
    protected function counter(string $key, int $delta, int $seconds): int|bool
    {
        try {
            return $this->client->increment($this->namespace->qualify($key), $delta, $seconds);
        } catch (ServerException) {
            return false;
        }
    }

    /**
     * Laravel hands out seconds and the client takes seconds, so this only
     * enforces the floor: a sub-second TTL would round to zero, and zero means
     * "no expiry" to every op that takes one - a cache entry that never dies.
     *
     * @param  int|float  $seconds
     */
    protected function ttl($seconds): int
    {
        return max(1, (int) $seconds);
    }
}
