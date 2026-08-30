<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Support;

/**
 * A named handle onto one counter in a {@see GenerationRegistry}.
 *
 * Holding the state in the registry rather than here is what lets several
 * generations be refreshed in a single round trip; this object is just the
 * address of one of them, so collaborators can be handed exactly the counter
 * they are allowed to touch.
 */
final class Generation
{
    public function __construct(
        private readonly GenerationRegistry $registry,
        private readonly string $key,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function current(): int
    {
        return $this->registry->current($this->key);
    }

    /**
     * Move to the next generation, abandoning everything under the current one.
     */
    public function bump(): int
    {
        return $this->registry->bump($this->key);
    }

    /**
     * Drop the cached value so the next read goes back to the server.
     */
    public function forget(): void
    {
        $this->registry->forget($this->key);
    }
}
