<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Serialization;

use Rostam\Exceptions\ProtocolException;
use Rostam\Exceptions\RostamException;

/**
 * igbinary instead of `serialize()`: markedly faster on arrays and objects, and
 * a good deal smaller on the wire, which is where a cache spends its time.
 *
 * Values written by one serializer cannot be read by the other, so switching an
 * existing store means flushing it (which, being generational here, is cheap).
 */
class IgbinarySerializer extends CounterAwareSerializer
{
    public function __construct()
    {
        if (! function_exists('igbinary_serialize')) {
            throw new RostamException(
                'the igbinary serializer needs ext-igbinary; install it or use the default php serializer'
            );
        }
    }

    public static function isAvailable(): bool
    {
        return function_exists('igbinary_serialize');
    }

    protected function encode(mixed $value): string
    {
        return igbinary_serialize($value) ?? throw new ProtocolException('igbinary could not encode the value');
    }

    protected function decode(string $payload): mixed
    {
        return igbinary_unserialize($payload);
    }
}
