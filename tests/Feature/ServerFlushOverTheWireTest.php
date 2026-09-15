<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Tests\Feature;

use Illuminate\Cache\Lock;
use PHPUnit\Framework\TestCase;
use Rostam\Cache\RostamStore;
use Rostam\Kv\TcpClient;
use Rostam\Testing\FakeServer;

/**
 * The flush mode with the largest blast radius, over a real socket.
 *
 * `'flush' => 'server'` sends rostam's own `flush`, which has no unit smaller
 * than the whole keyspace. Every other test of it runs against an in-memory
 * client, which is exactly where a wrong idea of what the op does would go
 * unnoticed - so this one asks a server, and asserts the part nobody wants to
 * discover in production: a key this store never wrote goes too.
 */
class ServerFlushOverTheWireTest extends TestCase
{
    private ?FakeServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();

        parent::tearDown();
    }

    public function test_the_server_flush_takes_everything_on_the_server(): void
    {
        if (! FakeServer::isDisposable()) {
            $this->markTestSkipped(
                'this test wipes the whole server. Set ROSTAM_TEST_SERVER_IS_DISPOSABLE=1 if it is a scratch one.'
            );
        }

        if (! FakeServer::supports('0.6.0')) {
            $this->markTestSkipped('the flush op arrived in rostam v0.6.0');
        }

        $this->server = FakeServer::start();
        $client = TcpClient::fromArray($this->server->connectionConfig());
        $store = RostamStore::make($client, 'flush-test:', ['flush' => 'server', 'epoch_refresh' => 0]);

        $store->put('mine', 'a value', 60);

        // Somebody else's key, under nobody's prefix but its own.
        $client->put('a-neighbour:session', 'not this store\'s');

        $lockKey = static fn () => (new \ReflectionProperty(Lock::class, 'name'))
            ->getValue($store->lock('shared', 10));

        $before = $lockKey();

        $this->assertTrue($store->flush());

        $this->assertNull($store->get('mine'), 'the store kept its own key');
        $this->assertNull(
            $client->get('a-neighbour:session'),
            'the server flush spared a key outside the store - it is supposed to take the whole keyspace'
        );

        // The lock counter went with it, and a counter that reads back as zero
        // would put new locks in a namespace live holders are still using.
        $this->assertNotSame($before, $lockKey(), 'the lock generation did not move past the wipe');
    }
}
