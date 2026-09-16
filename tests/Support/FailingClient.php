<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Tests\Support;

use Rostam\Contracts\KvClient;
use Rostam\Exceptions\ServerException;
use Rostam\TimeUnit;

/**
 * A client that answers one op with a chosen server refusal, and forwards the
 * rest to a real in-memory one.
 *
 * The fake server can be told to refuse a token, but not to answer NOT_LEADER -
 * only a replica can - and the store's one `catch` has to tell those apart from
 * "this value is not a counter". This is how that half is tested at all.
 */
final class FailingClient implements KvClient
{
    public function __construct(
        private readonly KvClient $inner,
        private readonly string $op,
        private readonly int $status,
        private readonly string $detail = 'internal error',
    ) {}

    private function refuse(string $op): void
    {
        if ($op === $this->op) {
            throw new ServerException($this->status, $this->detail, $op);
        }
    }

    public function get(string $key): ?string
    {
        $this->refuse('get');

        return $this->inner->get($key);
    }

    public function getMany(array $keys): array
    {
        $this->refuse('getMany');

        return $this->inner->getMany($keys);
    }

    public function put(string $key, string $value, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): void
    {
        $this->refuse('put');
        $this->inner->put($key, $value, $ttl, $unit);
    }

    public function putMany(array $entries, TimeUnit $unit = TimeUnit::Seconds): void
    {
        $this->refuse('putMany');
        $this->inner->putMany($entries, $unit);
    }

    public function setNx(string $key, string $value, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): bool
    {
        $this->refuse('setNx');

        return $this->inner->setNx($key, $value, $ttl, $unit);
    }

    public function cas(string $key, string $value, ?string $expected, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): bool
    {
        $this->refuse('cas');

        return $this->inner->cas($key, $value, $expected, $ttl, $unit);
    }

    public function cad(string $key, string $expected): bool
    {
        $this->refuse('cad');

        return $this->inner->cad($key, $expected);
    }

    public function caex(string $key, string $expected, int $ttl, TimeUnit $unit = TimeUnit::Seconds): bool
    {
        $this->refuse('caex');

        return $this->inner->caex($key, $expected, $ttl, $unit);
    }

    public function getdel(string $key): ?string
    {
        $this->refuse('getdel');

        return $this->inner->getdel($key);
    }

    public function getset(string $key, string $value, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): ?string
    {
        $this->refuse('getset');

        return $this->inner->getset($key, $value, $ttl, $unit);
    }

    public function exists(string $key): bool
    {
        $this->refuse('exists');

        return $this->inner->exists($key);
    }

    public function del(string $key): bool
    {
        $this->refuse('del');

        return $this->inner->del($key);
    }

    public function delMany(array $keys): array
    {
        $this->refuse('delMany');

        return $this->inner->delMany($keys);
    }

    public function increment(string $key, int $delta = 1, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): int
    {
        $this->refuse('increment');

        return $this->inner->increment($key, $delta, $ttl, $unit);
    }

    public function expire(string $key, int $ttl, TimeUnit $unit = TimeUnit::Seconds): bool
    {
        $this->refuse('expire');

        return $this->inner->expire($key, $ttl, $unit);
    }

    public function persist(string $key): bool
    {
        $this->refuse('persist');

        return $this->inner->persist($key);
    }

    public function ttl(string $key, TimeUnit $unit = TimeUnit::Seconds): int
    {
        $this->refuse('ttl');

        return $this->inner->ttl($key, $unit);
    }

    public function flush(): void
    {
        $this->refuse('flush');
        $this->inner->flush();
    }

    public function ping(): bool
    {
        return $this->inner->ping();
    }

    public function disconnect(): void
    {
        $this->inner->disconnect();
    }
}
