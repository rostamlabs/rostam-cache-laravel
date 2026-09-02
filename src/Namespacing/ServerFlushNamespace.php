<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Rostam\Cache\Namespacing;

use Rostam\Cache\Contracts\CacheNamespace;
use Rostam\Cache\Session\RostamSessionHandler;
use Rostam\Contracts\KvClient;

/**
 * Bare keys, and a flush that really is a flush - of the whole server.
 *
 * Rostam v0.6.0 added a `flush` op, which is what this uses. Read what it does
 * before choosing it: it is not FLUSHDB. Redis clears one numbered database and
 * leaves the rest; Rostam has no databases, so `flush` wipes EVERY key the
 * server holds, whoever wrote it. Measured against v0.6.0:
 *
 *     put app:a, put session:b
 *     flush                       (sent carrying the key `app:`)
 *     app:a      -> not found
 *     session:b  -> not found     <- the argument scoped nothing
 *
 * So on a server shared with anything else, `php artisan cache:clear` on this
 * store also takes the other application's cache, the sessions - including the
 * ones {@see RostamSessionHandler} keeps deliberately
 * out of a generational flush's reach - and any queued jobs that had already
 * been accepted. Vector collections live in a separate keyspace and survive.
 *
 * Choose it when the server belongs to this store and you mean all of it. What
 * it buys over {@see GenerationalNamespace} is real: the shortest possible
 * keys, no generation to look up or keep fresh, and a flush that reclaims the
 * memory instead of leaving the old entries resident but unreachable until
 * their TTL or eviction gets to them.
 */
final class ServerFlushNamespace implements CacheNamespace
{
    public function __construct(
        private readonly KvClient $client,
        private readonly string $prefix = '',
    ) {}

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function resolve(): string
    {
        return $this->prefix;
    }

    public function qualify(string $key): string
    {
        return $this->prefix.$key;
    }

    /**
     * Wipes the server, not this prefix. There is no smaller unit to ask for.
     */
    public function flush(): void
    {
        $this->client->flush();
    }

    public function supportsFlush(): bool
    {
        return true;
    }

    /**
     * Nothing is cached to drop: the prefix is the whole of the state, and it
     * cannot go stale.
     */
    public function reset(): void
    {
        //
    }

    public function withPrefix(string $prefix): static
    {
        return new self($this->client, $prefix);
    }
}
