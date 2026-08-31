<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Rostam\Cache\Tests\Unit;

use Illuminate\Cache\Repository;
use Illuminate\Session\CacheBasedSessionHandler;
use PHPUnit\Framework\TestCase;
use Rostam\Cache\RostamStore;
use Rostam\Cache\Session\RostamSessionHandler;
use Rostam\Testing\ArrayKvClient;

class RostamSessionHandlerTest extends TestCase
{
    private ArrayKvClient $client;

    private RostamSessionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new ArrayKvClient;
        $this->handler = new RostamSessionHandler($this->client, 'session:', 120);
    }

    public function test_a_session_round_trips(): void
    {
        $this->assertTrue($this->handler->write('abc', 'user=42'));
        $this->assertSame('user=42', $this->handler->read('abc'));
    }

    public function test_an_unknown_session_reads_as_empty(): void
    {
        $this->assertSame('', $this->handler->read('never-existed'));
    }

    public function test_destroying_a_session_ends_it(): void
    {
        $this->handler->write('abc', 'user=42');

        $this->assertTrue($this->handler->destroy('abc'));
        $this->assertSame('', $this->handler->read('abc'));
    }

    public function test_a_session_carries_its_lifetime(): void
    {
        $this->handler->write('abc', 'user=42');

        $this->assertEqualsWithDelta(120 * 60, $this->client->ttl('session:abc'), 2);
    }

    /**
     * Every write pushes the deadline back out, which is what keeps somebody
     * who is actively using the site logged in while an abandoned session
     * lapses on its own.
     */
    public function test_writing_refreshes_the_lifetime(): void
    {
        $handler = new RostamSessionHandler($this->client, 'session:', 1);
        $handler->write('abc', 'first');

        $this->client->expire('session:abc', 5);
        $this->assertEqualsWithDelta(5, $this->client->ttl('session:abc'), 1);

        $handler->write('abc', 'second');

        $this->assertEqualsWithDelta(60, $this->client->ttl('session:abc'), 2);
    }

    /**
     * There is nothing for gc() to do - the engine expires sessions itself -
     * and saying so is different from a stub that quietly does nothing.
     */
    public function test_garbage_collection_is_the_engines_job(): void
    {
        $this->handler->write('abc', 'user=42');

        $this->assertSame(0, $this->handler->gc(3600));
        $this->assertSame('user=42', $this->handler->read('abc'), 'gc removed a live session');
    }

    /**
     * The whole reason this handler exists rather than pointing session.store
     * at the cache store. Clearing the cache bumps a generation number, and
     * anything written under the old one is unreachable from that moment -
     * which for sessions means every user logged out, with no error anywhere.
     */
    public function test_clearing_the_cache_does_not_log_everybody_out(): void
    {
        $store = RostamStore::make($this->client, 'app:', ['epoch_refresh' => 0]);

        $store->put('a-cached-thing', 'value', 600);
        $this->handler->write('abc', 'user=42');

        $store->flush();

        $this->assertNull($store->get('a-cached-thing'), 'the cache was not actually cleared');
        $this->assertSame('user=42', $this->handler->read('abc'), 'cache:clear logged every user out');
    }

    /**
     * The failure this package avoids, asserted against Laravel's own
     * cache-backed handler so the comparison is a fact rather than a claim.
     */
    public function test_a_cache_backed_session_would_be_lost_on_a_flush(): void
    {
        $store = RostamStore::make($this->client, 'app:', ['epoch_refresh' => 0]);
        $viaCache = new CacheBasedSessionHandler(new Repository($store), 120);

        $viaCache->write('abc', 'user=42');
        $this->assertSame('user=42', $viaCache->read('abc'));

        $store->flush();

        $this->assertSame('', $viaCache->read('abc'));
    }
}
