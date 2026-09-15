<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Rostam\Cache\RostamStore;
use Rostam\Cache\Tests\Support\FailingClient;
use Rostam\Exceptions\ServerException;
use Rostam\Kv\Protocol\Status;
use Rostam\Kv\TcpClient;
use Rostam\Testing\ArrayKvClient;
use Rostam\Testing\FakeServer;

/**
 * An increment Laravel cannot carry out returns false. A connection the server
 * will not serve is not that, and says so.
 */
class CounterRefusalTest extends TestCase
{
    private ?FakeServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();

        parent::tearDown();
    }

    public function test_a_refused_token_is_thrown_not_returned_as_false(): void
    {
        if (FakeServer::isExternal()) {
            $this->markTestSkipped('a real server fixes its auth at launch; this needs a per-test token');
        }

        $this->server = FakeServer::start('s3cret');

        // 'unsupported' keeps keys bare, so nothing is read before the
        // increment itself and the refusal is the increment's own.
        $store = RostamStore::make(
            TcpClient::fromArray($this->server->connectionConfig(['token' => 'wrong'])),
            'app:',
            ['flush' => 'unsupported'],
        );

        try {
            $store->increment('hits');
            $this->fail('an increment on a refused connection came back as a value');
        } catch (ServerException $exception) {
            $this->assertTrue($exception->isUnauthorized());
        }
    }

    public function test_a_value_that_is_not_a_counter_is_still_false(): void
    {
        $this->server = FakeServer::start();

        $store = RostamStore::make(
            TcpClient::fromArray($this->server->connectionConfig()),
            'counter-refusal:'.bin2hex(random_bytes(4)).':',
            ['flush' => 'unsupported'],
        );

        $store->put('name', 'keivan', 60);

        $this->assertFalse($store->increment('name'));
        $store->forget('name');
    }

    /**
     * The other half of the same guard, which no server a test can start will
     * produce: only a replica answers NOT_LEADER, and "this write went to the
     * wrong node" is not "that value is not a counter" either.
     */
    public function test_a_replica_that_is_not_the_leader_is_thrown_not_returned_as_false(): void
    {
        $store = RostamStore::make(
            new FailingClient(new ArrayKvClient, 'increment', Status::NOT_LEADER, '10.0.0.2:7000'),
            'app:',
            ['flush' => 'unsupported'],
        );

        try {
            $store->increment('hits');
            $this->fail('an increment routed to a replica came back as a value');
        } catch (ServerException $exception) {
            $this->assertTrue($exception->isNotLeader());
            $this->assertSame('10.0.0.2:7000', $exception->detail);
        }
    }
}
