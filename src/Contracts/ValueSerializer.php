<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Contracts;

use Rostam\Cache\Serialization\CounterAwareSerializer;

/**
 * Turns PHP cache values into the bytes Rostam stores, and back.
 *
 * Implementations are free to choose their payload encoding, but they all owe
 * the store one guarantee: **an integer must serialize to exactly eight bytes,
 * big-endian**, and nothing else may. That is not a style choice - it is what
 * the server's `incr_ex` accepts, and therefore what makes `Cache::increment()`
 * a single atomic operation instead of a read-modify-write race.
 *
 * {@see CounterAwareSerializer} implements that
 * rule once; a new encoding only has to supply `encode()`/`decode()`.
 */
interface ValueSerializer
{
    public function serialize(mixed $value): string;

    public function unserialize(string $raw): mixed;

    /**
     * Can the server increment this value in place?
     */
    public function isCounter(mixed $value): bool;
}
