<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Rostam\Cache\Session;

use Rostam\Contracts\KvClient;
use SessionHandlerInterface;

/**
 * Sessions on Rostam, deliberately kept out of the cache's reach.
 *
 * Laravel can already run sessions on any cache store, and pointing
 * `session.store` at the Rostam one would appear to work. It would also log
 * every user out the first time somebody ran `php artisan cache:clear`.
 *
 * That is not an accident of this package but a consequence of how flush has to
 * work here: Rostam has no FLUSHDB, so clearing the cache means bumping a
 * generation number that every key is written under, and everything below the
 * new generation becomes unreachable at once. Sessions included. Measured:
 *
 *     session written, reads back:      'user=42'
 *     ... php artisan cache:clear ...
 *     session after the cache flush:    ''
 *
 * No error, no warning - the store simply answers empty, and every logged-in
 * user is suddenly logged out. So sessions live under their own prefix with no
 * generation in it, which puts them somewhere `cache:clear` cannot reach.
 *
 * There is no gc() to write: every session is stored with its lifetime as a
 * TTL, and the engine expires them itself.
 */
class RostamSessionHandler implements SessionHandlerInterface
{
    /**
     * @param  int  $minutes  session lifetime, as Laravel expresses it
     */
    public function __construct(
        protected KvClient $client,
        protected string $prefix = 'session:',
        protected int $minutes = 120,
    ) {}

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        return $this->client->get($this->prefix.$id) ?? '';
    }

    public function write(string $id, string $data): bool
    {
        // Every write refreshes the lifetime, which is what keeps an active
        // session alive and lets an abandoned one lapse on its own.
        $this->client->put($this->prefix.$id, $data, max(1, $this->minutes) * 60);

        return true;
    }

    public function destroy(string $id): bool
    {
        $this->client->del($this->prefix.$id);

        return true;
    }

    /**
     * Nothing to collect.
     *
     * Every session carries a TTL, so the engine removes expired ones without
     * being asked. Returning 0 is not a stub standing in for work not done -
     * there is no work.
     */
    public function gc(int $maxLifetime): int
    {
        return 0;
    }
}
