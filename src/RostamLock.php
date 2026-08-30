<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache;

use Illuminate\Cache\Lock;
use Rostam\Contracts\KvClient;

/**
 * An atomic lock built on Rostam's conditional key-value writes (v0.5.0+).
 *
 * One key, three server-side atomic ops, and no windows to reason about:
 *
 * - **acquire** is `set_nx` - the owner token and the TTL are stored together,
 *   in one operation, only if the key is absent or expired. Two callers racing
 *   cannot both win, and a lock whose TTL has lapsed re-acquires cleanly.
 * - **release** is compare-and-delete, so a process can only ever delete a lock
 *   it still owns - a lease that expired and was taken by someone else is not
 *   released out from under the new holder.
 * - **extend** is compare-and-expire, which refreshes the lease only while the
 *   token still matches.
 *
 * This is the same contract as the Redis lock driver. A lock created with
 * `$seconds = 0` never expires by design, as with every other Laravel driver.
 */
class RostamLock extends Lock
{
    public function __construct(
        protected KvClient $client,
        string $name,
        int $seconds,
        ?string $owner = null,
    ) {
        parent::__construct($name, $seconds, $owner);
    }

    public function acquire()
    {
        return $this->client->setNx($this->name, $this->owner, $this->ttl());
    }

    public function release()
    {
        return $this->client->cad($this->name, $this->owner);
    }

    /**
     * Extend the lease, but only while this process still holds it.
     *
     * A NON-POSITIVE $seconds does not extend anything and returns false. Zero
     * means "never expires" everywhere else in this class, and honouring that
     * here would turn a leased lock into a permanent one - a deploy or job lock
     * that nothing will ever release, that survives a restart on a persistent
     * store, and that only a manual forceRelease() clears. Nobody types
     * extend(0) meaning that; it arrives from a remaining-time calculation that
     * reached zero, which is exactly the moment the lease should be allowed to
     * lapse instead. A caller who really wants a permanent lock builds it with
     * $seconds = 0 and extends with no argument at all.
     *
     * @param  int|null  $seconds  defaults to the lock's own duration
     */
    public function extend(?int $seconds = null): bool
    {
        if ($seconds !== null && $seconds <= 0) {
            return false;
        }

        return $this->client->caex(
            $this->name,
            $this->owner,
            $seconds ?? $this->ttl()
        );
    }

    public function forceRelease(): void
    {
        $this->client->del($this->name);
    }

    protected function getCurrentOwner()
    {
        return $this->client->get($this->name);
    }

    protected function ttl(): int
    {
        return $this->seconds > 0 ? $this->seconds : 0;
    }
}
