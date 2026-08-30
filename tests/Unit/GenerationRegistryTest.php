<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rostam\Cache\Support\GenerationRegistry;
use Rostam\Testing\ArrayKvClient;

/**
 * A generation counter is an ordinary entry in the cache it governs, so Rostam's
 * default ring-buffer policy can evict it - and it is written once per flush and
 * only read afterwards, so it is exactly the kind of entry that ages out. Every
 * test here is about what happens when it does: the generation must never move
 * backwards, because everything written under the generations in between is
 * still on the server and would become reachable again.
 *
 * `forget()` on the client stands in for the eviction: from the registry's side
 * the two are the same event - the key is simply not there any more.
 */
class GenerationRegistryTest extends TestCase
{
    private ArrayKvClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new ArrayKvClient;
    }

    private function registry(float $refresh = 0.0): GenerationRegistry
    {
        return new GenerationRegistry($this->client, $refresh);
    }

    public function test_it_starts_at_zero_and_bumps(): void
    {
        $generation = $this->registry()->generation('app:#epoch');

        $this->assertSame(0, $generation->current());
        $this->assertSame(1, $generation->bump());
        $this->assertSame(2, $generation->bump());
        $this->assertSame(2, $generation->current());
    }

    public function test_a_refresh_does_not_fall_back_to_zero_when_the_counter_is_evicted(): void
    {
        $generation = $this->registry()->generation('app:#epoch');

        $generation->bump();
        $generation->bump();
        $this->assertSame(2, $generation->current());

        $this->client->del('app:#epoch');

        // Reading nothing must not read as generation zero: everything written
        // under generations 0 and 1 is still on the server.
        $this->assertSame(2, $generation->current());
    }

    public function test_an_evicted_counter_is_restored_on_the_server_not_just_in_memory(): void
    {
        $generation = $this->registry()->generation('app:#epoch');

        $generation->bump();
        $generation->bump();
        $this->client->del('app:#epoch');

        $generation->current();

        // A second process, with no memory of its own, must see the restored
        // value rather than starting again from zero.
        $fresh = $this->registry()->generation('app:#epoch');

        $this->assertSame(2, $fresh->current());
    }

    public function test_a_flush_after_an_eviction_still_moves_forward(): void
    {
        $generation = $this->registry()->generation('app:#epoch');

        $generation->bump();
        $generation->bump();
        $this->client->del('app:#epoch');

        // increment on a missing key would recreate the counter at 1, which is
        // BEHIND where this process already was - a flush that re-exposes the
        // generations it was supposed to abandon.
        $this->assertSame(3, $generation->bump());
        $this->assertSame(3, $this->registry()->generation('app:#epoch')->current());
    }

    public function test_it_accepts_a_higher_value_written_by_another_process(): void
    {
        $registry = $this->registry();
        $generation = $registry->generation('app:#epoch');

        $generation->bump();
        $this->assertSame(1, $generation->current());

        // Another process flushes twice.
        $this->client->increment('app:#epoch', 2);

        $this->assertSame(3, $generation->current());
    }

    public function test_a_healthy_refresh_costs_no_extra_round_trip(): void
    {
        $generation = $this->registry()->generation('app:#epoch');
        $generation->bump();

        $this->client->ops = [];
        $generation->current();

        $this->assertSame(['get'], $this->client->ops);
    }

    public function test_several_counters_refresh_in_one_round_trip(): void
    {
        // A refresh window wide enough that the reload the first read triggers is
        // still fresh for the second - otherwise every read is stale by
        // definition and the batching has nothing to save.
        $registry = $this->registry(refresh: 60);
        $cache = $registry->generation('app:#epoch');
        $locks = $registry->generation('app:#lock-epoch');

        $cache->bump();
        $locks->bump();
        $registry->forgetAll();

        $this->client->ops = [];
        $cache->current();
        $locks->current();

        $this->assertSame(['getMany'], $this->client->ops);
    }

    public function test_a_cached_generation_is_reused_until_it_goes_stale(): void
    {
        $generation = $this->registry(refresh: -1)->generation('app:#epoch');

        $generation->current();
        $this->client->ops = [];

        $generation->current();
        $generation->current();

        $this->assertSame([], $this->client->ops);
    }
}
