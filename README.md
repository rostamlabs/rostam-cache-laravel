# Laravel cache driver for Rostam

A Laravel cache store backed by [Rostam](https://github.com/rostamlabs/rostam)'s
key-value engine — the same shape as the Redis driver: `Cache::store('rostam')`,
tags, atomic locks, atomic `add()`, batch reads and writes.

Rostam's KV half is **not on its REST API**. It lives only on the native binary
TCP protocol, because it is built for sub-microsecond operations that an HTTP
round trip would defeat. So this package ships a small PHP client for that
protocol rather than an HTTP wrapper: framing, pooling, pipelining, TLS and
token auth, with no extensions beyond core streams.

## Requirements

- PHP 8.2+ on a 64-bit build
- Laravel 11, 12 or 13
- **Rostam v0.5.0 or newer**, started with a `-tcp` listener

v0.5.0 is where the conditional writes (`set_nx`, `cas`, `cad`, `caex`) and
`incr_ex` landed. They are what make `add()` atomic, locks Redis-grade, and
`increment()` keep its window — this package is built on them, and it says so
plainly (`UnsupportedOperationException`) rather than misbehaving if you point
it at an older server.

```bash
ROSTAM_API_KEY=$(openssl rand -hex 32) rostam-server -tcp 127.0.0.1:7000 -data /var/lib/rostam
```

## Install

```bash
composer require rostamlabs/rostam-cache-laravel
```

Publish the connection config if you want to edit it:

```bash
php artisan vendor:publish --tag=rostam-config
```

Add the store to `config/cache.php`:

```php
'stores' => [

    'rostam' => [
        'driver' => 'rostam',
        'connection' => 'default',   // a key under config/rostam.php's "connections"
        'epoch_refresh' => 10,       // see "Flushing" below
    ],

],
```

Then set `CACHE_STORE=rostam` (or `Cache::store('rostam')` where you want it).

`.env`:

```dotenv
ROSTAM_HOST=127.0.0.1
ROSTAM_PORT=7000
ROSTAM_TOKEN=the-same-value-as-ROSTAM_API_KEY
```

Check the server is reachable:

```bash
php artisan rostam:ping
```

## Usage

Nothing new to learn — it is a cache store:

```php
Cache::put('user:1', $user, now()->addHour());
Cache::get('user:1');
Cache::remember('report', 600, fn () => $this->buildReport());

Cache::add('job:1:claimed', true, 300);     // atomic: exactly one caller wins
Cache::putMany(['a' => 1, 'b' => 2], 60);   // one round trip
Cache::many(['a', 'b', 'c']);               // one round trip

Cache::increment('hits');                   // atomic, server-side, keeps its TTL
Cache::tags(['people'])->flush();

Cache::lock('deploy', 30)->get(fn () => $this->deploy());
```

The raw key-value store is available too, in bytes, for anything that is not
cache-shaped:

```php
use Rostam\Cache\Facades\Rostam;

Rostam::put('session:abc', $blob, 300);            // seconds
Rostam::put('lease', $blob, 250, TimeUnit::Milliseconds);
Rostam::setNx('idempotency:'.$id, $payload, 86_400);
Rostam::cas('config', $new, expected: $old);
Rostam::getdel('one-shot-token');
Rostam::ttl('session:abc');                 // -2 absent, -1 no expiry, else seconds
Rostam::pttl('session:abc');                // the same, in milliseconds
Rostam::connection('analytics')->ping();
```

## How it maps onto Rostam

### Batch operations pipeline

`many()`, `putMany()` and batch deletes write every frame in one go and read the
answers back in request order (Rostam answers a connection strictly FIFO). Fifty
keys cost one round trip, not fifty.

This deliberately does **not** use the server's `mget`: that op is routed to a
single shard by its first key, so on a cluster it would answer "missing" for
every key another shard owns. Pipelined gets route per key and cost the same one
round trip.

### Integers are stored as counters

`incr_ex` requires the stored value to be exactly eight bytes, read as a
big-endian int64. So integer cache values — and only integers — are stored bare
in that form; everything else is `serialize()`d behind a one-byte header. That
is what makes `Cache::increment()` a single atomic server-side operation rather
than a read-modify-write race, and it is why `Cache::increment()` on a string
returns `false` instead of silently corrupting the value.

The window behaves exactly like Redis's `INCRBY`, because `incr_ex` rewrites the
value against the key's stored absolute deadline:

```php
Cache::put('throttle:'.$ip, 0, 60);
Cache::increment('throttle:'.$ip);   // still expires 60s after the put
Cache::forever('hits', 0);
Cache::increment('hits');            // still permanent
Cache::increment('brand-new');       // created with no TTL
```

For a fixed window opened by the first hit, in one round trip, the store also
exposes `incrementWithin()` — the TTL is stamped only when the counter is
created, so later hits inside the window never extend it:

```php
Cache::store('rostam')->getStore()->incrementWithin('rate:'.$ip, 60);
```

### Locks

`Cache::lock()` is one key and three atomic ops, with the same contract as the
Redis driver:

- **acquire** is `set_nx` — the owner token and the TTL are stored together, in
  one operation, only if the key is absent or expired. Two callers racing cannot
  both win, and a lapsed lease re-acquires cleanly.
- **release** is compare-and-delete, so a process can only delete a lock it
  still owns — a lease that expired and was taken by someone else is not
  released out from under the new holder.
- **extend** is compare-and-expire, renewing the lease only while the token
  still matches:

```php
$lock = Cache::lock('import', 60);

if ($lock->get()) {
    foreach ($chunks as $chunk) {
        $this->process($chunk);
        $lock->extend(60);     // only succeeds while we still hold it
    }

    $lock->release();
}
```

Lock keys live outside the cache generation on purpose, so `cache:clear` does
not hand a running mutex to a second process. They carry a generation of their
own instead, which is what `php artisan cache:clear --locks` bumps.

### Flushing

This is the one place Rostam still cannot do what Redis does. There is no
`KEYS`, `SCAN` or `FLUSHDB`, so a literal flush is impossible. Instead every key
is written under a generation number:

```
laravel_cache:0:user:1
             ^ generation
```

`Cache::flush()` (and `php artisan cache:clear`) increments the generation. Reads
stop seeing the old data immediately; the bytes themselves are reclaimed by their
TTL or by Rostam's ring-buffer eviction. Give long-lived entries a TTL if you run
the server under `PolicyRejectWrites`, where nothing is evicted.

The generation is re-read from the server at most every `epoch_refresh` seconds
(default 10). That is the window in which *this* process may still serve data
another process has already flushed — set it to `0` to check on every operation
(one extra round trip each), or `-1` to read it once per process.

Set `'flush' => 'unsupported'` on the store to drop the generation segment
entirely; `flush()` then throws instead of pretending.

## What eviction costs you

Rostam's default `AtCapPolicy` is `PolicyRingbufEvict`: at capacity it overwrites
**the oldest entries in the oldest page**. That is write order, not LRU - reading a
key does not keep it alive. Redis defaults to `noeviction`, so two things that are
theoretical there are real here.

**Tags would invalidate themselves, so this driver replaces the tag set.**
Laravel implements tags by storing one random id per tag and folding it into
every tagged key's name (`Illuminate\Cache\TagSet`). That id is written once
with `forever()` and only ever read afterwards - the exact aging profile eviction
targets first. When it goes, the next read mints a new id and every entry under
that tag becomes unreachable at once, with no error anywhere.

`RefreshingTagSet` carries the write time inside the id, so the read it was
already making also says how old the id is - no extra round trip - and rewrites
it only once it has aged past `tag_refresh` seconds. That is at most one write
per tag per interval across the whole fleet, because the timestamp is shared
rather than remembered per worker. Measured on a single-shard server, one tagged
write followed only by reads while 1.28 GB of unrelated traffic went past:

    tag_refresh = 0 (off)   ->  tagged read: NULL
    tag_refresh = 1s        ->  tagged read: 'Anne'

The same class also reads a whole set in one round trip:
`tags(['a', 'b', 'c'])` costs one exchange rather than Laravel's three sequential ones.

It cannot help a tag nobody touches - a refresh needs something to read the tag -
but that is the harmless half: nothing was using it, so what follows is a cold
miss rather than the surprise loss of data somebody was relying on.

**The same is true of `flush()`'s generation counter**, which is why this driver
refuses to let it move backwards (see `GenerationRegistry`): a counter that is
evicted and read back as zero would make everything you flushed reachable again,
which is worse than a miss. Tags cannot be defended the same way, because their
ids are random rather than monotonic - there is nothing to compare a lost one
against.

## Compatibility

The full `Illuminate\Contracts\Cache\Store` surface is implemented — `get`,
`many`, `put`, `putMany`, `add`, `increment`, `decrement`, `forever`, `touch`,
`forget`, `flush`, `getPrefix` — plus `LockProvider` and `CanFlushLocks`.

| | |
| --- | --- |
| `Cache::remember` / `rememberForever` / `pull` / `flexible` | ✅ |
| `Cache::add` | ✅ atomic (`set_nx`) |
| `Cache::many` / `putMany` / PSR-16 `getMultiple` etc. | ✅ pipelined |
| `Cache::tags(...)` | ✅ generic tag sets |
| `Cache::lock` — `withoutOverlapping`, `ShouldBeUnique`, scheduler mutexes | ✅ same guarantees as the Redis lock |
| `throttle` middleware / `RateLimiter` | ✅ covered by a test that waits out the window |
| `php artisan cache:clear` / `cache:forget` / `cache:clear --locks` | ✅ |
| `SESSION_DRIVER=cache`, cached config/routes, `Cache::memo()` | ✅ |

**One difference from Redis remains**, and it comes from the engine rather than
this package: `flush()` is generational, not a wipe. Old data is abandoned
rather than deleted, and other processes see the flush after at most
`epoch_refresh` seconds. If Rostam ever grows a key-scan op, this goes away too.

Two smaller notes:

- `Cache::pull()` is Laravel's own get-then-forget, which is not atomic. The
  store exposes an atomic `pull()` of its own (the server's `getdel`) if you
  need it: `Cache::store('rostam')->getStore()->pull($key)`.
- Values are written in this driver's own encoding, so another Rostam client
  reading the same keys sees `serialize()` output, not plain strings.

## Design and extension points

`RostamStore` is an adapter and nothing else. Every decision it could have
hard-coded is a collaborator behind an interface, so extending the package means
passing a different object rather than forking one:

| Seam | Interface | Ships with | Swap it to… |
| --- | --- | --- | --- |
| Transport | `Contracts\Client` | `Client\TcpClient` | instrument, fail over, or fake the wire |
| Value encoding | `Contracts\ValueSerializer` | `PhpSerializer`, `IgbinarySerializer` | msgpack, compression, encryption |
| Keys and flushing | `Contracts\CacheNamespace` | `GenerationalNamespace`, `StaticNamespace` | a real key-scan flush, if Rostam grows one |

Registering your own, from any service provider:

```php
// A serializer, usable as 'serializer' => 'msgpack' in config/cache.php
$this->app->make(SerializerFactory::class)
    ->extend('msgpack', fn () => new MsgpackSerializer);

// A whole transport for one named connection
$this->app->make(RostamManager::class)
    ->resolver('analytics', fn (array $config) => new InstrumentedClient(
        TcpClient::fromArray($config)
    ));
```

Or assemble a store by hand — `RostamStore::make()` is only the usual wiring,
and the constructor takes the parts:

```php
new RostamStore($client, $namespace, $serializer, $lockGeneration);
```

### What the hot path costs

- **No per-key overhead.** `many()`, `putMany()` and batch deletes resolve the
  key namespace once and pipeline every frame into a single round trip.
- **Generations load together.** The cache and lock counters are read by one
  `GenerationRegistry`, so whichever is needed first brings the other with it —
  two cold counters cost one round trip, not two, and then nothing at all until
  `epoch_refresh` lapses.
- **Pooled sockets are checked, not trusted.** A connection is only handed a
  request after a non-blocking readability check. That costs a syscall against a
  network round trip, and it closes the one failure mode that reads as data
  corruption rather than an error: a `persistent` socket left holding an
  abandoned response, which would otherwise pair every later request with the
  previous request's answer.
- **Frames are written without copying.** A partial write is the only case that
  pays for a `substr()` of the payload.

## Configuration reference

| Key | Default | Meaning |
| --- | --- | --- |
| `host` / `port` | `127.0.0.1` / `7000` | the server's `-tcp` listener |
| `token` | `''` | matches the server's `-api-key` / `ROSTAM_API_KEY`; sends protocol v2 frames when set |
| `connect_timeout` | `2.0` | seconds for the dial |
| `timeout` | `5.0` | seconds for each read and write |
| `pool_size` | `4` | idle sockets kept per connection |
| `persistent` | `false` | PHP persistent sockets, kept by the worker across requests |
| `retry_on_stale_connection` | `true` | re-send an idempotent op once when a pooled socket turns out to have been closed while idle |
| `tls.enabled` | `false` | wrap the connection in TLS |
| `tls.ca` / `tls.cert` / `tls.key` | `null` | CA bundle and client certificate for mTLS |

Store-level options (in `config/cache.php`):

| Key | Default | Meaning |
| --- | --- | --- |
| `connection` | the default connection | which `rostam.connections.*` entry to use |
| `prefix` | `cache.prefix` | key prefix |
| `flush` | `'epoch'` | `'epoch'` or `'unsupported'` |
| `epoch_refresh` | `10` | seconds between generation re-reads; `0` every op, `-1` once per process |
| `tag_refresh` | `300` | seconds before a tag id is rewritten so eviction does not reach it; `0` disables it |
| `serializer` | `'php'` | `'php'`, `'igbinary'`, a registered name, or a class implementing `ValueSerializer` |

## Testing

```bash
composer install
composer test
```

The suite runs without a Rostam server: `tests/Support/server.php` is a
throwaway PHP implementation of the same wire protocol, started in a child
process. It reproduces the server behaviours the driver leans on — `incr_ex`
refusing non-8-byte values and stamping its TTL on create only, expired keys
reading as absent so `set_nx` re-acquires — and a `--legacy` mode that refuses
the v0.5.0 ops, which is how the version guard is tested.

## License

MIT.
