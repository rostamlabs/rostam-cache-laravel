<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Tests\Feature;

use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;
use Rostam\Cache\RostamStore;
use Rostam\Kv\TcpClient;
use Rostam\Testing\FakeServer;

/**
 * The store as Laravel actually uses it: through a cache Repository, over a
 * real socket.
 */
class CacheRepositoryTest extends TestCase
{
    private FakeServer $server;

    private Repository $cache;

    private RostamStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = FakeServer::start();
        $this->store = RostamStore::make(
            TcpClient::fromArray($this->server->connectionConfig()),
            'laravel_cache:',
            ['epoch_refresh' => 0]
        );
        $this->cache = new Repository($this->store);

        // The fake is a new process per test, so each test starts empty without
        // asking. A real server is shared and remembers - including a lock a
        // previous test acquired and never released, which then fails the next
        // test's acquire for the length of its lease. The store's own flush is
        // exactly the reset needed, and locks carry their own generation so
        // they need their own bump.
        if (FakeServer::isExternal()) {
            $this->store->flush();
            $this->store->flushLocks();
        }
    }

    protected function tearDown(): void
    {
        $this->server->stop();

        parent::tearDown();
    }

    public function test_the_usual_repository_surface_works(): void
    {
        $this->assertTrue($this->cache->put('user', ['id' => 1], 60));
        $this->assertSame(['id' => 1], $this->cache->get('user'));
        $this->assertTrue($this->cache->has('user'));
        $this->assertSame('fallback', $this->cache->get('absent', 'fallback'));

        $this->assertTrue($this->cache->forget('user'));
        $this->assertFalse($this->cache->has('user'));
    }

    public function test_remember_only_calls_the_resolver_once(): void
    {
        $calls = 0;

        $resolver = function () use (&$calls) {
            $calls++;

            return 'computed';
        };

        $this->assertSame('computed', $this->cache->remember('slow', 60, $resolver));
        $this->assertSame('computed', $this->cache->remember('slow', 60, $resolver));
        $this->assertSame(1, $calls);
    }

    public function test_many_and_put_many(): void
    {
        $this->cache->putMany(['a' => 1, 'b' => 'two'], 60);

        $this->assertSame(['a' => 1, 'b' => 'two', 'c' => null], $this->cache->many(['a', 'b', 'c']));
    }

    public function test_add_only_succeeds_once(): void
    {
        $this->assertTrue($this->cache->add('once', 'first', 60));
        $this->assertFalse($this->cache->add('once', 'second', 60));

        $this->assertSame('first', $this->cache->get('once'));
    }

    public function test_counters(): void
    {
        $this->cache->forever('hits', 0);

        $this->assertSame(1, $this->cache->increment('hits'));
        $this->assertSame(6, $this->cache->increment('hits', 5));
        $this->assertSame(5, $this->cache->decrement('hits'));
    }

    public function test_flush_clears_everything(): void
    {
        $this->cache->forever('a', 1);
        $this->cache->forever('b', 2);

        $this->assertTrue($this->cache->clear());

        $this->assertNull($this->cache->get('a'));
        $this->assertNull($this->cache->get('b'));

        $this->cache->forever('a', 3);
        $this->assertSame(3, $this->cache->get('a'));
    }

    public function test_tagged_caching(): void
    {
        $this->cache->tags(['people', 'artists'])->put('anne', 'Anne', 60);
        $this->cache->tags(['people', 'authors'])->put('john', 'John', 60);

        $this->assertSame('Anne', $this->cache->tags(['people', 'artists'])->get('anne'));
        $this->assertNull($this->cache->tags(['people', 'artists'])->get('john'));

        $this->cache->tags(['authors'])->flush();

        $this->assertSame('Anne', $this->cache->tags(['people', 'artists'])->get('anne'));
        $this->assertNull($this->cache->tags(['people', 'authors'])->get('john'));
    }

    public function test_locks_work_through_the_repository(): void
    {
        $lock = $this->cache->lock('deploy', 10);

        $this->assertTrue($lock->get());
        $this->assertFalse($this->cache->lock('deploy', 10)->get());

        $lock->release();

        $this->assertTrue($this->cache->lock('deploy', 10)->get());
    }

    public function test_a_lock_releases_itself_once_its_lease_lapses(): void
    {
        $this->assertTrue($this->cache->lock('deploy', 1)->get());

        // Nothing runs release(): the server's own expiry has to free it, which
        // is only true because acquire() stores the owner and the TTL together.
        usleep(1_300_000);

        $this->assertTrue($this->cache->lock('deploy', 10)->get());
    }
}
