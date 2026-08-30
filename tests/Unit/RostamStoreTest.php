<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rostam\Cache\RostamStore;
use Rostam\Exceptions\RostamException;
use Rostam\Testing\ArrayKvClient;

class RostamStoreTest extends TestCase
{
    private ArrayKvClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new ArrayKvClient;
    }

    private function store(array $options = [], string $prefix = 'app:'): RostamStore
    {
        return RostamStore::make($this->client, $prefix, $options);
    }

    public function test_it_round_trips_values(): void
    {
        $store = $this->store();

        $this->assertTrue($store->put('user', ['id' => 7], 60));
        $this->assertSame(['id' => 7], $store->get('user'));
        $this->assertNull($store->get('missing'));
    }

    public function test_keys_carry_the_prefix_and_the_generation_number(): void
    {
        $store = $this->store();

        $store->put('user', 1, 60);

        $this->assertSame(['app:0:user'], array_keys(array_diff_key($this->client->all(), ['app:#epoch' => true])));
    }

    public function test_it_applies_a_ttl_in_milliseconds(): void
    {
        $store = $this->store();
        $store->put('short', 'v', 2);

        $expires = $this->client->expiresAt('app:0:short');

        $this->assertNotNull($expires);
        $this->assertEqualsWithDelta(microtime(true) + 2, $expires, 0.5);
    }

    public function test_forever_writes_without_an_expiry(): void
    {
        $store = $this->store();
        $store->forever('permanent', 'v');

        $this->assertNull($this->client->expiresAt('app:0:permanent'));
        $this->assertSame('v', $store->get('permanent'));
    }

    public function test_expired_values_read_back_as_null(): void
    {
        $store = $this->store();
        $store->put('gone', 'v', 60);

        $this->client->ageOut('app:0:gone');

        $this->assertNull($store->get('gone'));
    }

    public function test_many_and_put_many_use_one_round_trip_each(): void
    {
        $store = $this->store();

        $store->get('warm');          // pay for the generation lookup up front
        $this->client->ops = [];

        $store->putMany(['a' => 1, 'b' => 'two'], 60);

        $this->assertSame(['a' => 1, 'b' => 'two', 'c' => null], $store->many(['a', 'b', 'c']));

        // Two batch calls and nothing else: no per-key round trip, and no
        // per-key generation lookup either.
        $this->assertSame(['putMany', 'getMany'], $this->client->ops);
    }

    public function test_both_generations_load_in_one_round_trip(): void
    {
        $store = $this->store();

        // Whichever is asked for first, the other rides along - so an app that
        // takes a lock does not pay two round trips before its first cache read.
        $store->lock('deploy', 10);

        $this->assertSame(['getMany'], $this->client->ops);

        $store->get('anything');

        $this->assertSame(['getMany', 'get'], $this->client->ops);
    }

    public function test_add_is_a_single_atomic_conditional_write(): void
    {
        $store = $this->store();

        $this->assertTrue($store->add('once', 'first', 60));
        $this->assertFalse($store->add('once', 'second', 60));
        $this->assertSame('first', $store->get('once'));

        $this->assertContains('setNx', $this->client->ops);
    }

    public function test_add_treats_an_expired_key_as_absent(): void
    {
        $store = $this->store();
        $store->add('once', 'first', 60);

        $this->client->ageOut('app:0:once');

        $this->assertTrue($store->add('once', 'second', 60));
    }

    /**
     * putMany builds its own [key, value, ttl] triples rather than going through
     * put(), so it is the one write path that can disagree with the others about
     * what a TTL means. The old test wrote and read back in the same breath,
     * which cannot tell 60 seconds from 60 milliseconds - both are still there.
     * Assert the deadline itself.
     */
    public function test_put_many_stores_its_ttl_in_seconds_like_every_other_write(): void
    {
        $store = $this->store();

        $store->putMany(['a' => 1, 'b' => 2], 60);

        foreach (['a', 'b'] as $key) {
            $this->assertEqualsWithDelta(
                60,
                $store->ttlOf($key),
                1,
                "putMany gave [$key] the wrong window"
            );
        }

        // And the single-key path it must agree with.
        $store->put('c', 3, 60);
        $this->assertEqualsWithDelta($store->ttlOf('c'), $store->ttlOf('a'), 1);
    }

    public function test_increment_and_decrement_run_server_side(): void
    {
        $store = $this->store();

        $this->assertSame(1, $store->increment('hits'));
        $this->assertSame(4, $store->increment('hits', 3));
        $this->assertSame(2, $store->decrement('hits', 2));
        $this->assertSame(2, $store->get('hits'));
    }

    public function test_increment_returns_false_for_a_value_that_is_not_a_counter(): void
    {
        $store = $this->store();
        $store->put('name', 'keivan', 60);

        $this->assertFalse($store->increment('name'));
    }

    public function test_increment_keeps_the_window_the_counter_was_written_with(): void
    {
        $store = $this->store();
        $store->put('hits', 0, 60);

        $before = $this->client->expiresAt('app:0:hits');

        $store->increment('hits');

        $this->assertEqualsWithDelta($before, $this->client->expiresAt('app:0:hits'), 0.001);
    }

    public function test_a_counter_stored_forever_stays_forever_across_increments(): void
    {
        $store = $this->store();
        $store->forever('hits', 0);

        $store->increment('hits');

        $this->assertNull($this->client->expiresAt('app:0:hits'));
        $this->assertSame(1, $store->get('hits'));
    }

    public function test_a_counter_created_by_increment_alone_has_no_window(): void
    {
        // Same as Redis: INCR on a missing key creates it without a TTL.
        $store = $this->store();

        $store->increment('fresh');

        $this->assertNull($this->client->expiresAt('app:0:fresh'));
    }

    public function test_increment_within_opens_a_fixed_window_that_does_not_slide(): void
    {
        $store = $this->store();

        $this->assertSame(1, $store->incrementWithin('hits', 60));

        $opened = $this->client->expiresAt('app:0:hits');
        $this->assertNotNull($opened);

        $this->assertSame(2, $store->incrementWithin('hits', 60));

        $this->assertEqualsWithDelta($opened, $this->client->expiresAt('app:0:hits'), 0.001);
    }

    public function test_pull_reads_and_deletes_atomically(): void
    {
        $store = $this->store();
        $store->put('once', 'value', 60);

        $this->assertSame('value', $store->pull('once'));
        $this->assertNull($store->get('once'));
        $this->assertNull($store->pull('once'));
    }

    public function test_ttl_of_reports_the_remaining_seconds(): void
    {
        $store = $this->store();

        $this->assertSame(-2, $store->ttlOf('absent'));

        $store->forever('permanent', 'v');
        $this->assertSame(-1, $store->ttlOf('permanent'));

        $store->put('timed', 'v', 60);
        $this->assertEqualsWithDelta(60, $store->ttlOf('timed'), 1);
    }

    public function test_expire_and_touch_move_the_window(): void
    {
        $store = $this->store();
        $store->forever('hits', 1);

        $this->assertTrue($store->touch('hits', 30));
        $this->assertNotNull($this->client->expiresAt('app:0:hits'));

        // expire(0) drops the expiry entirely, via the server's persist op.
        $this->assertTrue($store->expire('hits', 0));
        $this->assertNull($this->client->expiresAt('app:0:hits'));
    }

    public function test_forget_reports_whether_the_key_existed(): void
    {
        $store = $this->store();
        $store->put('a', 1, 60);

        $this->assertTrue($store->forget('a'));
        $this->assertFalse($store->forget('a'));
    }

    public function test_flush_bumps_the_generation_and_hides_everything(): void
    {
        $store = $this->store(['epoch_refresh' => 0]);
        $store->put('a', 1, 60);

        $this->assertTrue($store->flush());

        $this->assertSame('app:1:a', $store->key('a'));
        $this->assertNull($store->get('a'));

        $store->put('a', 2, 60);
        $this->assertSame(2, $store->get('a'));
        $this->assertArrayHasKey('app:1:a', $this->client->all());
    }

    public function test_a_flush_elsewhere_is_picked_up_once_the_generation_is_re_read(): void
    {
        $reader = $this->store(['epoch_refresh' => -1]);
        $writer = $this->store(['epoch_refresh' => 0]);

        $reader->put('a', 1, 60);
        $this->assertSame(1, $reader->get('a'));

        $writer->flush();

        // The reader is pinned to the generation it already read.
        $this->assertSame(1, $reader->get('a'));

        $reader->refreshGenerations();

        $this->assertNull($reader->get('a'));
    }

    public function test_flush_can_be_turned_off(): void
    {
        $store = $this->store(['flush' => 'unsupported']);

        $store->put('a', 1, 60);
        $this->assertArrayHasKey('app:a', $this->client->all());

        $this->expectException(RostamException::class);

        $store->flush();
    }

    public function test_flush_locks_releases_every_lock(): void
    {
        $store = $this->store(['epoch_refresh' => 0]);

        $this->assertTrue($store->lock('deploy', 60)->acquire());
        $this->assertFalse($store->lock('deploy', 60)->acquire());

        $this->assertTrue($store->flushLocks());

        $this->assertTrue($store->lock('deploy', 60)->acquire());
    }

    public function test_flushing_the_cache_does_not_flush_locks_and_the_reverse(): void
    {
        $store = $this->store(['epoch_refresh' => 0]);
        $store->put('a', 1, 60);
        $store->lock('deploy', 60)->acquire();

        $store->flush();
        $this->assertFalse($store->lock('deploy', 60)->acquire());

        $store->put('b', 2, 60);
        $store->flushLocks();
        $this->assertSame(2, $store->get('b'));
    }

    public function test_it_exposes_the_prefix(): void
    {
        $this->assertSame('app:', $this->store()->getPrefix());
    }
}
