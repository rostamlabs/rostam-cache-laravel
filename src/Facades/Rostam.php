<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Facades;

use Illuminate\Support\Facades\Facade;
use Rostam\Cache\RostamManager;
use Rostam\Contracts\KvClient;
use Rostam\TimeUnit;

/**
 * Direct access to the Rostam key-value store, in raw bytes.
 *
 * The first three are the manager's own; everything after is forwarded to the
 * default connection by {@see RostamManager::__call()}, so this list has to
 * track {@see KvClient} exactly - an annotation for a method that is not there
 * is not a documentation slip, it is a call that fatals at runtime with nothing
 * to catch it beforehand.
 *
 * TTLs are seconds unless a call passes {@see TimeUnit::Milliseconds}.
 *
 * @method static KvClient connection(?string $name = null)
 * @method static string defaultConnection()
 * @method static void purge(?string $name = null)
 * @method static ?string get(string $key)
 * @method static array getMany(array $keys)
 * @method static void put(string $key, string $value, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds)
 * @method static void putMany(array $entries, TimeUnit $unit = TimeUnit::Seconds)
 * @method static bool setNx(string $key, string $value, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds)
 * @method static bool cas(string $key, string $value, ?string $expected, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds)
 * @method static bool cad(string $key, string $expected)
 * @method static bool caex(string $key, string $expected, int $ttl, TimeUnit $unit = TimeUnit::Seconds)
 * @method static ?string getdel(string $key)
 * @method static ?string getset(string $key, string $value, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds)
 * @method static bool exists(string $key)
 * @method static bool del(string $key)
 * @method static array delMany(array $keys)
 * @method static int increment(string $key, int $delta = 1, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds)
 * @method static bool expire(string $key, int $ttl, TimeUnit $unit = TimeUnit::Seconds)
 * @method static bool persist(string $key)
 * @method static int ttl(string $key, TimeUnit $unit = TimeUnit::Seconds)
 * @method static bool ping()
 * @method static void set(string $key, string $value, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds)
 * @method static void setex(string $key, int $seconds, string $value)
 * @method static void psetex(string $key, int $milliseconds, string $value)
 * @method static int incrby(string $key, int $delta = 1, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds)
 * @method static int incr(string $key)
 * @method static int decrby(string $key, int $delta = 1)
 * @method static int decr(string $key)
 * @method static bool pexpire(string $key, int $milliseconds)
 * @method static int pttl(string $key)
 *
 * @see RostamManager
 */
class Rostam extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RostamManager::class;
    }
}
