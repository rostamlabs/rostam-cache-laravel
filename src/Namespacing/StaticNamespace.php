<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Namespacing;

use Rostam\Cache\Contracts\CacheNamespace;
use Rostam\Exceptions\RostamException;

/**
 * Bare keys, and no flush at all.
 *
 * For stores that would rather have the shortest possible keys and no
 * generation lookup than a `Cache::flush()` that only pretends. Asking it to
 * flush throws, loudly, instead of returning a false success.
 */
final class StaticNamespace implements CacheNamespace
{
    public function __construct(private readonly string $prefix = '') {}

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

    public function flush(): void
    {
        throw new RostamException(
            'flush() is disabled for this store. Rostam has no KEYS or SCAN, so clearing only '
            ."this store's keys needs the generation-number strategy: set 'flush' => 'epoch'. It does "
            .'have a flush op since v0.6.0, but it wipes the WHOLE server - every other store, the '
            ."sessions and any accepted queued jobs - so 'flush' => 'server' is only for an instance "
            .'that belongs to this cache alone.'
        );
    }

    public function supportsFlush(): bool
    {
        return false;
    }

    public function reset(): void
    {
        //
    }

    public function withPrefix(string $prefix): static
    {
        return new self($prefix);
    }
}
