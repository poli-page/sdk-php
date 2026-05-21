<?php

declare(strict_types=1);

namespace PoliPage\Internal\Http;

use PoliPage\Internal\Constants;

/**
 * Parses the `Retry-After` response header. Accepts either an integer
 * number of seconds or an HTTP-date. Returns the delay in **seconds**, capped
 * at {@see Constants::RETRY_AFTER_CAP_SECONDS}. Returns `null` when the
 * header is missing or unparseable.
 *
 * Mirrors sdk-node/src/internal/http.ts `parseRetryAfter` but in seconds
 * (PHP convention) rather than milliseconds.
 *
 * @internal
 */
final class RetryAfterParser
{
    public static function parse(?string $headerValue): ?float
    {
        if ($headerValue === null || $headerValue === '') {
            return null;
        }

        if (is_numeric($headerValue)) {
            $seconds = (float) $headerValue;

            return self::clamp($seconds);
        }

        $timestamp = strtotime($headerValue);
        if ($timestamp === false) {
            return null;
        }
        $delta = (float) ($timestamp - time());

        return self::clamp($delta);
    }

    private static function clamp(float $seconds): float
    {
        return max(0.0, min($seconds, Constants::RETRY_AFTER_CAP_SECONDS));
    }
}
