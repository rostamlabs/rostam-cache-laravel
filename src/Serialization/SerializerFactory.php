<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Serialization;

use Closure;
use Illuminate\Contracts\Container\Container;
use Rostam\Cache\Contracts\ValueSerializer;
use Rostam\Exceptions\RostamException;

/**
 * Turns a store's `serializer` option into a {@see ValueSerializer}.
 *
 * Accepts a built-in name, a class name, a ready-made instance, or a name
 * someone registered with {@see self::extend()} - so shipping a msgpack or
 * compressed encoding is a service-provider call rather than a fork.
 */
class SerializerFactory
{
    /** @var array<string, class-string<ValueSerializer>> */
    protected array $aliases = [
        'php' => PhpSerializer::class,
        'igbinary' => IgbinarySerializer::class,
    ];

    /** @var array<string, Closure(): ValueSerializer> */
    protected array $custom = [];

    public function __construct(protected ?Container $container = null) {}

    /**
     * Register a serializer under a name usable in `config/cache.php`.
     *
     * @param  Closure(): ValueSerializer  $factory
     */
    public function extend(string $name, Closure $factory): self
    {
        $this->custom[$name] = $factory;

        return $this;
    }

    public function make(string|ValueSerializer|null $serializer = null): ValueSerializer
    {
        if ($serializer instanceof ValueSerializer) {
            return $serializer;
        }

        $name = $serializer ?? 'php';

        if (isset($this->custom[$name])) {
            return ($this->custom[$name])();
        }

        $class = $this->aliases[$name] ?? $name;

        if (! is_subclass_of($class, ValueSerializer::class) && $class !== ValueSerializer::class) {
            throw new RostamException(
                "unknown cache serializer [{$name}]; expected one of ["
                .implode(', ', array_merge(array_keys($this->aliases), array_keys($this->custom)))
                .'] or a class implementing '.ValueSerializer::class
            );
        }

        return $this->container ? $this->container->make($class) : new $class;
    }
}
