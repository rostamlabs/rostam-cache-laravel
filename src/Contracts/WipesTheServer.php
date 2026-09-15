<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Rostam\Cache\Contracts;

use Rostam\Cache\RostamStore;

/**
 * A {@see CacheNamespace} whose flush() takes the whole server with it.
 *
 * Carried by its own interface rather than a method on CacheNamespace, because
 * that interface is published and somebody may already implement it: PHP
 * resolves an implemented interface eagerly, so a method added to it is not a
 * deprecation but a fatal error the next time their class is autoloaded. The
 * same reasoning is spelled out at length in src/Compat/can_flush_locks.php,
 * where this package was on the receiving end of it.
 *
 * There is nothing to declare beyond the fact itself, so there is no method
 * here. A namespace that does not carry this simply does not wipe the server -
 * which is the answer for every implementation that existed before v0.6.0 gave
 * rostam a flush op, and the safe one to assume for any that arrives later.
 *
 * {@see RostamStore::flush()} is what reads it, and it reads it
 * because locks live outside the namespace and carry a counter of their own: a
 * server-wide wipe deletes that counter, and a counter that comes back as zero
 * is a second lock namespace rather than a cleared one.
 */
interface WipesTheServer {}
