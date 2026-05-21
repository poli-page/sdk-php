<?php

declare(strict_types=1);

namespace PoliPage\Internal\Uuid;

/**
 * Crypto-safe RFC 4122 §4.4 UUID v4 generator. ~15 lines so the SDK does not
 * pull a dedicated UUID dependency.
 *
 * @internal Used to generate Idempotency-Key values when the caller does not
 *           supply one. Not part of the public API.
 */
final class Uuid4
{
    public static function generate(): string
    {
        $bytes = random_bytes(16);
        // Set the version (4) and variant (10xx) bits per RFC 4122 §4.4.
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
