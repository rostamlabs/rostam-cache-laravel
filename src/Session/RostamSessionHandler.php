<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Rostam\Cache\Session;

use InvalidArgumentException;
use Rostam\Contracts\KvClient;
use SessionHandlerInterface;

/**
 * Sessions on Rostam, deliberately kept out of the cache's reach.
 *
 * Laravel can already run sessions on any cache store, and pointing
 * `session.store` at the Rostam one would appear to work. It would also log
 * every user out the first time anything flushed that store - an unqualified
 * `php artisan cache:clear` if it is the default one, `cache:clear rostam` if it
 * is a named one that outlived a few `cache:clear` runs first.
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
 * generation in it, which puts them out of reach of any store's flush.
 *
 * One exception, and it is a configuration rather than a bug: a store set to
 * `'flush' => 'server'` uses rostam v0.6.0's `flush` op, which has no unit
 * smaller than the whole keyspace and takes these sessions with it. Nothing
 * here can prevent that - the op does not accept a scope - so the two belong on
 * different servers if you want both.
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
    ) {
        // A lifetime of zero or less has no honest reading here. Clamping it to
        // a minute invents a policy nobody configured; storing it with no TTL
        // would keep every session that was ever created, for ever. Laravel's
        // own cache-backed handler quietly stores nothing at all, which looks
        // like sessions simply not working. Saying so is the only option that
        // does not surprise somebody later.
        if ($minutes < 1) {
            throw new InvalidArgumentException(sprintf(
                'session lifetime must be at least one minute, got %d. A server-side session '
                .'store needs a window to keep the session in; if you want the session to end '
                .'when the browser closes, that is session.expire_on_close, which is a cookie '
                .'setting and leaves this lifetime alone.',
                $minutes,
            ));
        }
    }

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
        $this->client->put($this->prefix.$id, $data, $this->minutes * 60);

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
