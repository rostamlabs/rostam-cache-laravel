<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Namespacing;

use Rostam\Cache\Contracts\CacheNamespace;
use Rostam\Cache\Support\Generation;
use Rostam\Cache\Support\GenerationRegistry;

/**
 * Keys carry a generation number, and flushing bumps it:
 *
 *     laravel_cache:0:user:1
 *                  ^ generation
 *
 * Reads stop seeing the old data the moment the counter moves; the bytes are
 * reclaimed by their TTL or by Rostam's ring-buffer eviction. It is the only
 * way to offer `Cache::flush()` on an engine with no KEYS or FLUSHDB, and the
 * cost is that data is abandoned rather than deleted.
 */
final class GenerationalNamespace implements CacheNamespace
{
    private readonly Generation $generation;

    public function __construct(
        private readonly GenerationRegistry $registry,
        private readonly string $prefix,
        private readonly string $marker = '#epoch',
    ) {
        $this->generation = $registry->generation($prefix.$marker);
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function resolve(): string
    {
        return $this->prefix.$this->generation->current().':';
    }

    public function qualify(string $key): string
    {
        return $this->resolve().$key;
    }

    public function flush(): void
    {
        $this->generation->bump();
    }

    /** Only this namespace goes: the generation moves, the keys stay put. */
    public function flushWipesTheServer(): bool
    {
        return false;
    }

    public function supportsFlush(): bool
    {
        return true;
    }

    public function reset(): void
    {
        $this->generation->forget();
    }

    public function withPrefix(string $prefix): static
    {
        return new self($this->registry, $prefix, $this->marker);
    }

    public function generation(): Generation
    {
        return $this->generation;
    }
}
