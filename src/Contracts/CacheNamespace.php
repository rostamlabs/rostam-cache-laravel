<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Contracts;

use Rostam\Cache\Namespacing\GenerationalNamespace;
use Rostam\Cache\Namespacing\StaticNamespace;
use Rostam\Exceptions\RostamException;

/**
 * How a cache key becomes a server key, and what "flush everything" means.
 *
 * Rostam has no KEYS/SCAN/FLUSHDB, so clearing a store is a policy decision
 * rather than a single call - and policy is exactly the kind of thing that
 * should be a collaborator instead of an `if` inside the store.
 * {@see GenerationalNamespace} folds a generation
 * number into every key and bumps it to flush;
 * {@see StaticNamespace} keeps keys bare and
 * refuses to pretend it can flush. If the engine ever grows a key-scan op, a
 * third implementation is the whole change.
 */
interface CacheNamespace
{
    /**
     * The configured cache prefix, as Laravel understands it.
     */
    public function prefix(): string;

    /**
     * Everything a key is prefixed with right now.
     *
     * Batch operations resolve this once and prepend it themselves, rather than
     * paying for the lookup per key.
     */
    public function resolve(): string;

    /**
     * One key as it is written on the server.
     */
    public function qualify(string $key): string;

    /**
     * Abandon everything in this namespace.
     *
     * @throws RostamException when the strategy cannot
     */
    public function flush(): void;

    public function supportsFlush(): bool;

    /**
     * Drop any cached state, so the next key re-reads it from the server.
     */
    public function reset(): void;

    public function withPrefix(string $prefix): static;
}
