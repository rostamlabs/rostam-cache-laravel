<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Cache\Serialization;

use Rostam\Cache\Contracts\ValueSerializer;
use Rostam\Exceptions\ProtocolException;

/**
 * The half of serialization that every encoding shares.
 *
 * Integers are stored bare as a big-endian int64 - exactly the eight bytes the
 * server's `incr_ex` will accept - and everything else goes through the
 * subclass's encoding behind a one-byte header:
 *
 *     "\x00" . $encoded            the ordinary case
 *     "\x01" . $encoded . "\x00"   when $encoded is 7 bytes long
 *
 * The padded form exists only so a non-integer can never land on a total length
 * of 8 and be read back as a counter. With PHP's own serializer that is not
 * hypothetical: `serialize('')` is exactly 7 bytes.
 */
abstract class CounterAwareSerializer implements ValueSerializer
{
    protected const HEADER_PLAIN = "\x00";

    protected const HEADER_PADDED = "\x01";

    abstract protected function encode(mixed $value): string;

    abstract protected function decode(string $payload): mixed;

    public function serialize(mixed $value): string
    {
        if (is_int($value)) {
            return pack('J', $value);
        }

        $encoded = $this->encode($value);

        return strlen($encoded) === 7
            ? self::HEADER_PADDED.$encoded."\x00"
            : self::HEADER_PLAIN.$encoded;
    }

    public function unserialize(string $raw): mixed
    {
        if (strlen($raw) === 8) {
            /** @var array{1: int} $unpacked */
            $unpacked = unpack('J', $raw);

            return $unpacked[1];
        }

        if ($raw === '') {
            throw new ProtocolException('cannot decode an empty cache value');
        }

        $body = substr($raw, 1);

        return $this->decode(match ($raw[0]) {
            self::HEADER_PLAIN => $body,
            self::HEADER_PADDED => substr($body, 0, -1),
            default => throw new ProtocolException(
                'unknown cache value header 0x'.bin2hex($raw[0]).'; the key was not written by this driver'
            ),
        });
    }

    public function isCounter(mixed $value): bool
    {
        return is_int($value);
    }
}
