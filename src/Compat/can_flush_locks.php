<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Illuminate\Contracts\Cache;

/*
 * CanFlushLocks arrived in Laravel 13. RostamStore implements it so that
 * `cache:clear --locks` can reach the lock generation, and on Laravel 11 and 12
 * that class could not even be loaded: PHP resolves an implemented interface
 * eagerly, so a missing one is a fatal error at autoload rather than a feature
 * quietly going absent. The manifest claimed all three majors while the code
 * worked on exactly one, which is the kind of promise a matrix run exists to
 * catch - and did.
 *
 * So it is declared here when the framework does not ship it. On 11 and 12 that
 * leaves an inert marker: nothing in those versions looks for it, `cache:clear`
 * has no --locks option to offer, and flushLocks() is still callable directly.
 * On 13 the framework's own interface is found first and this does nothing.
 *
 * Guarded by interface_exists, so a framework that later ships it wins without
 * a collision. Loaded through composer's `files` autoload, which runs before
 * any class is resolved.
 */
if (! interface_exists(CanFlushLocks::class)) {
    interface CanFlushLocks
    {
        /**
         * Flush all locks managed by the store.
         */
        public function flushLocks(): bool;

        /**
         * Determine if the lock store is separate from the cache store.
         */
        public function hasSeparateLockStore(): bool;
    }
}
