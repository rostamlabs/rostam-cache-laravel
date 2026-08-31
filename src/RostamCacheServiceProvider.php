<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Rostam\Cache\Console\PingCommand;
use Rostam\Cache\Serialization\SerializerFactory;
use Rostam\Cache\Session\RostamSessionHandler;

class RostamCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rostam.php', 'rostam');

        $this->app->singleton(RostamManager::class, static function (Application $app) {
            return new RostamManager($app['config']);
        });

        // A singleton so an application's own provider can extend() it with a
        // serializer of its own before any store is built.
        $this->app->singleton(SerializerFactory::class, static function (Application $app) {
            return new SerializerFactory($app);
        });

        $this->app->alias(RostamManager::class, 'rostam');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/rostam.php' => $this->app->configPath('rostam.php'),
            ], 'rostam-config');

            $this->commands([PingCommand::class]);
        }

        // Note: CacheManager::extend() rebinds this closure to the manager, so
        // it must not reach for $this - everything it needs comes from $app.
        // (It cannot be a static closure either: Laravel 11 and 12 bind the
        // callback unconditionally, which a static closure refuses.)
        $this->app->make('cache')->extend('rostam', function (Application $app, array $config) {
            $client = $app->make(RostamManager::class)->connection($config['connection'] ?? null);

            $store = RostamStore::make(
                $client,
                (string) ($config['prefix'] ?? $app['config']->get('cache.prefix') ?? ''),
                $config,
                $app->make(SerializerFactory::class)->make($config['serializer'] ?? null),
            );

            return $app->make('cache')->repository($store, $config);
        });

        $this->registerSessionDriver();
    }

    /**
     * Register `session.driver = rostam`.
     *
     * Sessions get their own prefix rather than riding on the cache store,
     * because clearing the cache here means bumping a generation number and
     * everything under the old one - sessions included - becomes unreachable
     * at once. A `php artisan cache:clear` would log every user out, silently,
     * and the store would answer empty rather than error.
     *
     * See {@see RostamSessionHandler}.
     */
    protected function registerSessionDriver(): void
    {
        if (! $this->app->bound('session')) {
            return;
        }

        $this->app->make('session')->extend('rostam', function (Application $app) {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('session', []);

            return new RostamSessionHandler(
                $app->make(RostamManager::class)->connection($config['connection'] ?? null),
                (string) ($config['prefix'] ?? 'session:'),
                (int) ($config['lifetime'] ?? 120),
            );
        });
    }
}
