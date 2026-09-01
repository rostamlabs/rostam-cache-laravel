<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Tests\Feature;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use Rostam\Cache\Facades\Rostam;
use Rostam\Cache\RostamCacheServiceProvider;
use Rostam\Cache\RostamManager;
use Rostam\Cache\RostamStore;
use Rostam\Cache\Serialization\PhpSerializer;
use Rostam\Cache\Serialization\SerializerFactory;
use Rostam\Cache\Session\RostamSessionHandler;
use Rostam\Testing\ArrayKvClient;
use Rostam\Testing\FakeServer;

/**
 * The wiring: a `driver => rostam` store resolves, the facade reaches the same
 * server, and the artisan health check reports on it.
 */
class ServiceProviderTest extends TestCase
{
    private FakeServer $server;

    protected function setUp(): void
    {
        $this->server = FakeServer::start();

        parent::setUp();

        // A real server is shared across tests and remembers everything - a
        // lock a previous test took and never released will fail the next
        // test's acquire for the length of its lease. The fake sidesteps this
        // by being a new process each time; here the reset has to be asked for.
        if (FakeServer::isExternal()) {
            Cache::store('rostam')->flush();
            Cache::store('rostam')->flushLocks();
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->server->stop();
    }

    protected function getPackageProviders($app): array
    {
        return [RostamCacheServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('rostam.connections.default.host', '127.0.0.1');
        $app['config']->set('rostam.connections.default.port', $this->server->port);
        $app['config']->set('rostam.connections.default.token', '');

        $app['config']->set('cache.stores.rostam', [
            'driver' => 'rostam',
            'connection' => 'default',
            'epoch_refresh' => 0,
        ]);

        $app['config']->set('cache.prefix', 'testbench:');
    }

    public function test_the_driver_resolves(): void
    {
        $store = Cache::store('rostam')->getStore();

        $this->assertInstanceOf(RostamStore::class, $store);
        $this->assertSame('testbench:', $store->getPrefix());
    }

    public function test_the_store_reads_and_writes(): void
    {
        Cache::store('rostam')->put('greeting', 'salam', 60);

        $this->assertSame('salam', Cache::store('rostam')->get('greeting'));
    }

    public function test_a_store_prefix_overrides_the_cache_prefix(): void
    {
        config()->set('cache.stores.other', [
            'driver' => 'rostam',
            'prefix' => 'other:',
        ]);

        $this->assertSame('other:', Cache::store('other')->getStore()->getPrefix());
    }

    public function test_a_store_can_choose_its_serializer(): void
    {
        config()->set('cache.stores.custom', [
            'driver' => 'rostam',
            'serializer' => PhpSerializer::class,
        ]);

        $this->assertInstanceOf(PhpSerializer::class, Cache::store('custom')->getStore()->getSerializer());
    }

    public function test_a_serializer_can_be_registered_by_name(): void
    {
        $this->app->make(SerializerFactory::class)->extend('mine', fn () => new PhpSerializer);

        config()->set('cache.stores.custom', ['driver' => 'rostam', 'serializer' => 'mine']);

        $this->assertInstanceOf(PhpSerializer::class, Cache::store('custom')->getStore()->getSerializer());
    }

    public function test_a_connection_can_be_built_by_a_custom_resolver(): void
    {
        $client = new ArrayKvClient;

        $this->app->make(RostamManager::class)->resolver('fake', fn (array $config) => $client);

        config()->set('rostam.connections.fake', ['host' => 'unused', 'port' => 0]);

        $this->assertSame($client, $this->app->make(RostamManager::class)->connection('fake'));
    }

    public function test_the_facade_talks_to_the_same_server(): void
    {
        Rostam::put('raw', 'bytes');

        $this->assertSame('bytes', Rostam::get('raw'));
        $this->assertTrue(Rostam::ping());
    }

    public function test_the_framework_rate_limiter_resets_after_its_window(): void
    {
        // The regression this guards: Rostam's incr clears the TTL, and
        // RateLimiter::increment() never re-applies one - so without the
        // driver restoring it, `throttle` would lock a client out permanently.
        $limiter = new RateLimiter(Cache::store('rostam'));

        $limiter->hit('login:1.2.3.4', 1);
        $limiter->hit('login:1.2.3.4', 1);

        $this->assertSame(2, $limiter->attempts('login:1.2.3.4'));
        $this->assertTrue($limiter->tooManyAttempts('login:1.2.3.4', 2));

        usleep(1_300_000);

        $this->assertSame(0, $limiter->attempts('login:1.2.3.4'));
        $this->assertFalse($limiter->tooManyAttempts('login:1.2.3.4', 2));
    }

    public function test_cache_clear_can_flush_locks(): void
    {
        // Flushing locks through cache:clear --locks arrived in Laravel 13. On
        // 11 and 12 the store still implements the interface (declared by this
        // package where the framework does not ship it) and flushLocks() is
        // callable directly - there is simply no framework feature here to
        // exercise.
        if (! method_exists(Cache::store('rostam'), 'supportsFlushingLocks')) {
            $this->markTestSkipped('cache:clear --locks arrived in Laravel 13');
        }

        $this->assertTrue(Cache::store('rostam')->supportsFlushingLocks());
        $this->assertTrue(Cache::store('rostam')->lock('deploy', 60)->get());

        $this->artisan('cache:clear rostam --locks')->assertSuccessful();

        $this->assertTrue(Cache::store('rostam')->lock('deploy', 60)->get());
    }

    public function test_cache_clear_flushes_the_store(): void
    {
        Cache::store('rostam')->forever('a', 1);

        $this->artisan('cache:clear rostam')->assertSuccessful();

        $this->assertNull(Cache::store('rostam')->get('a'));
    }

    public function test_the_ping_command_reports_success(): void
    {
        $this->artisan('rostam:ping')->assertSuccessful();
    }

    public function test_the_ping_command_reports_an_unreachable_server(): void
    {
        config()->set('rostam.connections.dead', ['host' => '127.0.0.1', 'port' => 1, 'connect_timeout' => 0.5]);

        $this->artisan('rostam:ping --connection=dead')->assertFailed();
    }

    /**
     * session.connection is not ours to read.
     *
     * It already names a DATABASE connection - Laravel's own database and
     * Redis session drivers both resolve it that way - so an application
     * running SESSION_CONNECTION=mysql that switched nothing but its driver
     * would have sent us looking for rostam.connections.mysql, and the switch
     * would have failed on a key the operator never pointed at us.
     */
    public function test_the_session_driver_leaves_laravels_connection_key_alone(): void
    {
        config()->set('session.driver', 'rostam');
        config()->set('session.connection', 'mysql');

        $handler = $this->app->make('session')->driver('rostam')->getHandler();

        $this->assertInstanceOf(RostamSessionHandler::class, $handler);
    }

    /** A Rostam connection is chosen under a name that is unambiguously ours. */
    public function test_the_session_driver_takes_the_connection_it_is_given(): void
    {
        $client = new ArrayKvClient;
        $this->app->make(RostamManager::class)->resolver('sessions', fn (array $config) => $client);

        config()->set('rostam.connections.sessions', ['host' => 'unused', 'port' => 0]);
        config()->set('session.driver', 'rostam');
        config()->set('session.rostam_connection', 'sessions');
        config()->set('session.prefix', 'sess:');

        $this->app->make('session')->driver('rostam')->getHandler()->write('abc', 'user=42');

        $this->assertSame('user=42', $client->get('sess:abc'), 'the session went to the wrong server');
    }

    /**
     * The lifetime is refused rather than reinterpreted, and it is refused
     * here - when the driver is resolved - not at boot, so an application that
     * does not use this driver is not stopped by its configuration.
     */
    public function test_a_session_lifetime_of_zero_is_refused(): void
    {
        config()->set('session.driver', 'rostam');
        config()->set('session.lifetime', 0);

        $this->expectException(InvalidArgumentException::class);

        $this->app->make('session')->driver('rostam');
    }
}
