<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Tests\Unit;

use Illuminate\Contracts\Cache\LockTimeoutException;
use PHPUnit\Framework\TestCase;
use Rostam\Cache\RostamLock;
use Rostam\Cache\RostamStore;
use Rostam\Testing\ArrayKvClient;

class RostamLockTest extends TestCase
{
    private ArrayKvClient $client;

    private RostamStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new ArrayKvClient;
        $this->store = RostamStore::make($this->client, 'app:');
    }

    public function test_only_one_caller_holds_the_lock(): void
    {
        $first = $this->store->lock('deploy', 10);
        $second = $this->store->lock('deploy', 10);

        $this->assertTrue($first->acquire());
        $this->assertFalse($second->acquire());

        $this->assertTrue($first->release());
        $this->assertTrue($second->acquire());
    }

    public function test_acquiring_is_a_single_conditional_write(): void
    {
        // The whole safety argument rests on this: the owner token and the TTL
        // are stored by one atomic op, so there is no window to lose either in.
        $this->store->lock('deploy', 10)->acquire();

        $this->assertSame(['setNx'], array_values(array_filter(
            $this->client->ops,
            static fn (string $op) => ! in_array($op, ['get', 'getMany', 'increment'], true)
        )));
    }

    public function test_a_contender_cannot_release_someone_elses_lock(): void
    {
        $holder = $this->store->lock('deploy', 10, 'holder');
        $other = $this->store->lock('deploy', 10, 'intruder');

        $this->assertTrue($holder->acquire());
        $this->assertFalse($other->release());
        $this->assertSame('holder', $holder->owner());
        $this->assertTrue($holder->isOwnedByCurrentProcess());
    }

    public function test_a_stale_holder_cannot_release_the_new_holder(): void
    {
        $first = $this->store->lock('deploy', 30, 'first');
        $this->assertTrue($first->acquire());

        // The lease lapses and someone else takes it.
        $this->client->ageOut('app:#lock:0:deploy');
        $second = $this->store->lock('deploy', 30, 'second');
        $this->assertTrue($second->acquire());

        // The original holder waking up late must not delete the new lease.
        $this->assertFalse($first->release());
        $this->assertSame('second', $this->client->get('app:#lock:0:deploy'));
    }

    public function test_a_restored_lock_can_release(): void
    {
        $holder = $this->store->lock('deploy', 10, 'holder');
        $this->assertTrue($holder->acquire());

        $restored = $this->store->restoreLock('deploy', 'holder');

        $this->assertTrue($restored->release());
        $this->assertTrue($this->store->lock('deploy', 10)->acquire());
    }

    public function test_force_release_takes_the_lock_away(): void
    {
        $holder = $this->store->lock('deploy', 10, 'holder');
        $holder->acquire();

        $this->store->lock('deploy', 10)->forceRelease();

        $this->assertTrue($this->store->lock('deploy', 10)->acquire());
    }

    public function test_extend_renews_the_lease_only_for_the_holder(): void
    {
        $holder = $this->store->lock('deploy', 5, 'holder');
        $holder->acquire();

        $this->assertTrue($holder->extend(60));
        $this->assertEqualsWithDelta(microtime(true) + 60, $this->client->expiresAt('app:#lock:0:deploy'), 1.0);

        $this->assertFalse($this->store->lock('deploy', 5, 'intruder')->extend(600));
    }

    public function test_lock_keys_live_outside_the_flushable_namespace(): void
    {
        $this->store->lock('deploy', 10)->acquire();

        $this->assertArrayHasKey('app:#lock:0:deploy', $this->client->all());

        $this->store->flush();

        // cache:clear must not silently hand a live mutex to a second process.
        $this->assertFalse($this->store->lock('deploy', 10)->acquire());
    }

    public function test_the_lock_carries_its_ttl(): void
    {
        $this->store->lock('deploy', 30)->acquire();

        $this->assertNotNull($this->client->expiresAt('app:#lock:0:deploy'));
    }

    public function test_a_lock_without_a_ttl_never_expires(): void
    {
        $this->store->lock('deploy')->acquire();

        $this->assertNull($this->client->expiresAt('app:#lock:0:deploy'));
    }

    public function test_the_lock_is_reclaimable_once_its_ttl_lapses(): void
    {
        $this->store->lock('deploy', 30)->acquire();

        $this->client->ageOut('app:#lock:0:deploy');

        $this->assertTrue($this->store->lock('deploy', 30)->acquire());
    }

    public function test_get_runs_the_callback_and_releases(): void
    {
        $lock = $this->store->lock('deploy', 10);

        $this->assertSame('done', $lock->get(fn () => 'done'));
        $this->assertTrue($this->store->lock('deploy', 10)->acquire());
    }

    public function test_block_times_out_while_the_lock_is_held(): void
    {
        $this->store->lock('deploy', 10, 'holder')->acquire();

        $this->expectException(LockTimeoutException::class);

        $this->store->lock('deploy', 10)->betweenBlockedAttemptsSleepFor(10)->block(0);
    }

    /**
     * extend(0) used to be clamped through max(0, $seconds) and handed to the
     * server, where zero means "no expiry" - quietly turning a leased lock into
     * one nothing will ever release. A remaining-time calculation that reaches
     * zero is the ordinary way to get there, and it is precisely when the lease
     * should be allowed to lapse.
     */
    public function test_a_non_positive_extension_does_not_make_the_lock_permanent(): void
    {
        $lock = new RostamLock($this->client, 'deploy', 30);
        $this->assertTrue($lock->acquire());

        $before = $this->client->ttl('deploy');

        $this->assertFalse($lock->extend(0), 'extend(0) must report that it did not extend');
        $this->assertFalse($lock->extend(-5), 'a negative lease is no more meaningful than zero');

        $after = $this->client->ttl('deploy');

        $this->assertNotSame(-1, $after, 'the lock must not have become permanent');
        $this->assertEqualsWithDelta($before, $after, 1, 'the existing lease must be left alone');
    }

    public function test_a_positive_extension_still_works(): void
    {
        $lock = new RostamLock($this->client, 'deploy', 5);
        $lock->acquire();

        $this->assertTrue($lock->extend(120));
        $this->assertEqualsWithDelta(120, $this->client->ttl('deploy'), 1);
    }

    public function test_extending_with_no_argument_uses_the_locks_own_duration(): void
    {
        $lock = new RostamLock($this->client, 'deploy', 45);
        $lock->acquire();

        $this->assertTrue($lock->extend());
        $this->assertEqualsWithDelta(45, $this->client->ttl('deploy'), 1);
    }
}
