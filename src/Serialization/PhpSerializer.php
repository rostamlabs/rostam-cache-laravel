<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Serialization;

use Rostam\Exceptions\ProtocolException;

/**
 * The default encoding: PHP's own `serialize()`, which is what every other
 * Laravel cache store writes.
 */
class PhpSerializer extends CounterAwareSerializer
{
    protected function encode(mixed $value): string
    {
        return serialize($value);
    }

    protected function decode(string $payload): mixed
    {
        $value = @unserialize($payload);

        if ($value === false && $payload !== 'b:0;') {
            throw new ProtocolException('cache value could not be unserialized');
        }

        return $value;
    }
}
