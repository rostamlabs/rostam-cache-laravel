<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Rostam\Cache\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rostam\Cache\Contracts\CacheNamespace;
use Rostam\Cache\Namespacing\GenerationalNamespace;
use Rostam\Cache\Namespacing\ServerFlushNamespace;
use Rostam\Cache\Namespacing\StaticNamespace;
use Rostam\Cache\RostamStore;
use Rostam\Cache\Serialization\PhpSerializer;
use Rostam\Cache\Session\RostamSessionHandler;
use Rostam\Cache\Support\GenerationRegistry;
use Rostam\Testing\ArrayKvClient;

/**
 * Rostam v0.6.0 added a real `flush`, and this is the strategy that uses it.
 *
 * The point of these tests is not that it clears the cache - that part is one
 * line - but that it clears everything else too, and that the package says so
 * in a test rather than only in a paragraph.
 */
class ServerFlushNamespaceTest extends TestCase
{
    private ArrayKvClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new ArrayKvClient;
    }

    public function test_keys_are_bare(): void
    {
        $namespace = new ServerFlushNamespace($this->client, 'app:');

        // No generation segment: nothing to look up, and the shortest key the
        // prefix allows.
        $this->assertSame('app:', $namespace->resolve());
        $this->assertSame('app:greeting', $namespace->qualify('greeting'));
    }

    public function test_it_flushes_by_asking_the_server(): void
    {
        $store = RostamStore::make($this->client, 'app:', ['flush' => 'server']);

        $store->put('greeting', 'salam', 600);
        $this->assertSame('salam', $store->get('greeting'));

        $this->assertTrue($store->flush());
        $this->assertNull($store->get('greeting'));
        $this->assertContains('flush', $this->client->ops);
    }

    /**
     * The trade, stated as a test.
     *
     * A generational flush leaves sessions alone, which is the whole reason
     * this package ships its own session handler. The server flush does not:
     * one op, one keyspace, everything in it. Someone who reads only the
     * config key would never guess, so it is pinned here.
     */
    public function test_the_server_flush_also_logs_every_user_out(): void
    {
        $sessions = new RostamSessionHandler($this->client, 'session:', 120);
        $sessions->write('abc', 'user=42');

        RostamStore::make($this->client, 'app:', ['flush' => 'server'])->flush();

        $this->assertSame('', $sessions->read('abc'), 'this mode is supposed to take the sessions with it');
    }

    /** ...and the default still does not, which is the reason it is the default. */
    public function test_the_default_generational_flush_still_spares_them(): void
    {
        $sessions = new RostamSessionHandler($this->client, 'session:', 120);
        $sessions->write('abc', 'user=42');

        RostamStore::make($this->client, 'app:', ['epoch_refresh' => 0])->flush();

        $this->assertSame('user=42', $sessions->read('abc'));
    }

    /**
     * The failure this guards is silent and expensive: two processes holding
     * the same mutex.
     *
     * Locks sit outside the cache namespace and carry a counter of their own,
     * so `cache:clear --locks` has something to bump. A server flush deletes
     * that counter with everything else, and a deleted counter reads back as
     * ZERO - the one direction it must never move. A process that already had
     * the old number cached goes on taking locks under it while a freshly
     * started one takes them under zero, and neither can see the other.
     *
     * `epoch_refresh => -1` here is not an odd corner: it is the documented
     * "read it once per process" setting, and it is where the split would never
     * heal on its own.
     */
    public function test_a_server_flush_cannot_split_the_lock_namespace(): void
    {
        $options = ['flush' => 'server', 'epoch_refresh' => -1];

        $live = RostamStore::make($this->client, 'app:', $options);
        $live->flushLocks();
        $live->lock('deploy', 60);

        $live->flush();

        // Whatever this one is now using, a process that starts afterwards and
        // reads the counter from scratch has to arrive at the same answer.
        $fresh = RostamStore::make($this->client, 'app:', $options);

        $this->assertSame(
            $this->lockKey($live, 'deploy'),
            $this->lockKey($fresh, 'deploy'),
            'a process starting after the flush landed in a different lock namespace'
        );
    }

    private function lockKey(RostamStore $store, string $name): string
    {
        $method = new \ReflectionMethod($store, 'lockKey');

        return $method->invoke($store, $name);
    }

    /**
     * A CacheNamespace written before v0.6.0 has to keep working.
     *
     * The fact that a flush is server-wide arrived as its own interface rather
     * than a method on CacheNamespace for one reason: PHP resolves an
     * implemented interface eagerly, so a method added to a published one is
     * not a deprecation but a fatal error the next time somebody's class is
     * autoloaded. This class implements the contract as it stood, knows nothing
     * about WipesTheServer, and must both load and be treated as not wiping.
     */
    public function test_a_namespace_written_before_all_this_still_works(): void
    {
        $namespace = new class implements CacheNamespace
        {
            public function prefix(): string
            {
                return 'legacy:';
            }

            public function resolve(): string
            {
                return 'legacy:';
            }

            public function qualify(string $key): string
            {
                return 'legacy:'.$key;
            }

            public function flush(): void {}

            public function supportsFlush(): bool
            {
                return true;
            }

            public function reset(): void {}

            public function withPrefix(string $prefix): static
            {
                return $this;
            }
        };

        $store = new RostamStore(
            $this->client,
            $namespace,
            new PhpSerializer,
            (new GenerationRegistry($this->client, 0))->generation('legacy:#lock-epoch'),
        );

        $store->flushLocks();
        $before = $this->lockKey($store, 'deploy');

        $store->flush();

        $this->assertSame($before, $this->lockKey($store, 'deploy'),
            'a namespace that does not wipe the server had its lock generation bumped anyway');
    }

    public function test_every_mode_is_named(): void
    {
        $this->assertInstanceOf(GenerationalNamespace::class, $this->namespaceFor([]));
        $this->assertInstanceOf(GenerationalNamespace::class, $this->namespaceFor(['flush' => 'epoch']));
        $this->assertInstanceOf(ServerFlushNamespace::class, $this->namespaceFor(['flush' => 'server']));
        $this->assertInstanceOf(StaticNamespace::class, $this->namespaceFor(['flush' => 'unsupported']));
    }

    /**
     * A mode that does not exist used to mean "no flush at all": the factory
     * asked whether the value was 'epoch' and sent everything else to the
     * strategy that refuses. A typo therefore disabled cache:clear silently,
     * and 'server' would have been one of those typos.
     */
    public function test_an_unknown_mode_is_refused_rather_than_guessed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown flush mode \[nonsense\]/');

        RostamStore::make($this->client, 'app:', ['flush' => 'nonsense']);
    }

    private function namespaceFor(array $options): object
    {
        $store = RostamStore::make($this->client, 'app:', $options + ['epoch_refresh' => 0]);

        return (new \ReflectionProperty($store, 'namespace'))->getValue($store);
    }
}
