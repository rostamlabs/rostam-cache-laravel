<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rostam\Cache\Contracts\ValueSerializer;
use Rostam\Cache\Serialization\IgbinarySerializer;
use Rostam\Cache\Serialization\PhpSerializer;
use Rostam\Cache\Serialization\SerializerFactory;
use Rostam\Exceptions\ProtocolException;
use Rostam\Exceptions\RostamException;

class SerializationTest extends TestCase
{
    /**
     * @return list<array{0: ValueSerializer}>
     */
    public static function serializers(): array
    {
        $serializers = ['php' => [new PhpSerializer]];

        if (IgbinarySerializer::isAvailable()) {
            $serializers['igbinary'] = [new IgbinarySerializer];
        }

        return $serializers;
    }

    #[DataProvider('serializers')]
    public function test_integers_are_stored_bare_as_eight_bytes(ValueSerializer $serializer): void
    {
        // Every encoding owes the store this: it is what the server's incr_ex
        // will accept, and therefore what makes increment() atomic.
        $this->assertSame(8, strlen($serializer->serialize(0)));
        $this->assertSame(8, strlen($serializer->serialize(PHP_INT_MAX)));
        $this->assertSame("\x00\x00\x00\x00\x00\x00\x00\x2a", $serializer->serialize(42));
    }

    #[DataProvider('serializers')]
    public function test_integers_round_trip(ValueSerializer $serializer): void
    {
        foreach ([0, 1, -1, PHP_INT_MIN, PHP_INT_MAX] as $value) {
            $this->assertSame($value, $serializer->unserialize($serializer->serialize($value)));
        }
    }

    #[DataProvider('serializers')]
    public function test_other_values_round_trip(ValueSerializer $serializer): void
    {
        $values = [null, true, false, 1.5, 'hello', 'abcdefgh', ['a' => 1, 'b' => [2, 3]], (object) ['x' => 1]];

        foreach ($values as $value) {
            $this->assertEquals($value, $serializer->unserialize($serializer->serialize($value)));
        }
    }

    #[DataProvider('serializers')]
    public function test_no_non_integer_can_be_mistaken_for_a_counter(ValueSerializer $serializer): void
    {
        // serialize('') is exactly 7 bytes, which with a one-byte header would
        // land on 8 and read back as an integer. The padded header exists only
        // to keep that from happening.
        foreach ([null, true, false, '', 'a', 'ab', 'abc', [], [1], 0.0] as $value) {
            $encoded = $serializer->serialize($value);

            $this->assertNotSame(8, strlen($encoded), 'collided with the counter encoding');
            $this->assertEquals($value, $serializer->unserialize($encoded));
        }
    }

    #[DataProvider('serializers')]
    public function test_only_integers_count_as_counters(ValueSerializer $serializer): void
    {
        $this->assertTrue($serializer->isCounter(1));
        $this->assertFalse($serializer->isCounter('1'));
        $this->assertFalse($serializer->isCounter(1.0));
    }

    public function test_it_rejects_a_value_it_did_not_write(): void
    {
        $this->expectException(ProtocolException::class);

        (new PhpSerializer)->unserialize('garbage from somewhere else');
    }

    public function test_it_rejects_an_empty_value(): void
    {
        $this->expectException(ProtocolException::class);

        (new PhpSerializer)->unserialize('');
    }

    public function test_the_factory_resolves_the_built_in_names(): void
    {
        $factory = new SerializerFactory;

        $this->assertInstanceOf(PhpSerializer::class, $factory->make());
        $this->assertInstanceOf(PhpSerializer::class, $factory->make('php'));
        $this->assertInstanceOf(PhpSerializer::class, $factory->make(PhpSerializer::class));

        $instance = new PhpSerializer;
        $this->assertSame($instance, $factory->make($instance));
    }

    public function test_the_factory_takes_a_registered_serializer(): void
    {
        $factory = (new SerializerFactory)->extend('mine', fn () => new PhpSerializer);

        $this->assertInstanceOf(PhpSerializer::class, $factory->make('mine'));
    }

    public function test_the_factory_refuses_something_that_is_not_a_serializer(): void
    {
        $this->expectException(RostamException::class);

        (new SerializerFactory)->make('nope');
    }
}
