<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Rostam\Cache\Contracts;

use Rostam\Cache\Tags\RefreshingTagSet;

/**
 * A store that can write several values without expiry in one round trip.
 *
 * Laravel's Store contract has putMany(), but it takes a TTL, and a store whose
 * server reads a TTL of zero as "never expires" has to floor it at one second:
 * there is no way to say "forever" through it. Tag ids are exactly the values
 * that must not expire, and {@see RefreshingTagSet} writes several of them at
 * once - every aged tag of a set, or every tag a flush resets.
 *
 * Its own interface rather than a method added to an existing one, for the
 * reason spelled out on {@see WipesTheServer}: a method added to a published
 * interface is a fatal error for whoever already implements it. A store
 * without it is written to one key at a time, as before.
 */
interface StoresManyForever
{
    /**
     * Store every value, keyed by cache key, without expiry.
     *
     * @param  array<string, mixed>  $values
     */
    public function foreverMany(array $values): bool;
}
