<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Rostam\Contracts\KvClient;
use Rostam\Exceptions\RostamException;
use Rostam\Kv\TcpClient;

/**
 * Resolves and memoises the configured Rostam connections.
 *
 * @mixin KvClient
 */
class RostamManager
{
    /** @var array<string, KvClient> */
    protected array $clients = [];

    /** @var array<string, \Closure(array<string, mixed>): KvClient> */
    protected array $resolvers = [];

    public function __construct(protected ConfigRepository $config) {}

    public function connection(?string $name = null): KvClient
    {
        $name ??= $this->defaultConnection();

        return $this->clients[$name] ??= $this->resolve($name);
    }

    public function defaultConnection(): string
    {
        return (string) $this->config->get('rostam.default', 'default');
    }

    /**
     * Register an already-built client under a name - handy in tests.
     */
    public function extend(string $name, KvClient $client): self
    {
        $this->clients[$name] = $client;

        return $this;
    }

    /**
     * Teach the manager how to build a connection itself.
     *
     * The factory is handed that connection's config array, so an application
     * can swap in an instrumented, failing-over, or entirely different
     * transport without this package knowing about it.
     *
     * @param  \Closure(array<string, mixed>): KvClient  $factory
     */
    public function resolver(string $name, \Closure $factory): self
    {
        $this->resolvers[$name] = $factory;

        return $this;
    }

    /**
     * Close a connection and forget it, so the next call redials.
     */
    public function purge(?string $name = null): void
    {
        $name ??= $this->defaultConnection();

        if (isset($this->clients[$name])) {
            $this->clients[$name]->disconnect();

            unset($this->clients[$name]);
        }
    }

    public function disconnect(): void
    {
        foreach (array_keys($this->clients) as $name) {
            $this->purge($name);
        }
    }

    protected function resolve(string $name): KvClient
    {
        $config = $this->config->get('rostam.connections.'.$name);

        if (! is_array($config)) {
            throw new RostamException("Rostam connection [{$name}] is not configured.");
        }

        return isset($this->resolvers[$name])
            ? ($this->resolvers[$name])($config)
            : TcpClient::fromArray($config);
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->connection()->{$method}(...$arguments);
    }
}
