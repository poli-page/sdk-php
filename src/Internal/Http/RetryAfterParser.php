<?php

declare(strict_types=1);

namespace PoliPage\Internal\Http;

use PoliPage\Internal\Constants;

/**
 * Parses the `Retry-After` response header (SDK spec §9.2): integer seconds
 * or an HTTP-date (RFC 9110 §10.2.3). Returns the delay in **seconds**,
 * clamped to 0–{@see Constants::RETRY_AFTER_CAP_SECONDS}; a date in the past
 * gives 0. Returns `null` when the header is missing or is neither form
 * (`1.5`, `-1`, `soon`), so the backoff applies. `Retry-After-Ms` is never
 * read.
 *
 * @internal
 */
final class RetryAfterParser
{
    /** The three HTTP-date forms a recipient accepts: IMF-fixdate, RFC 850, asctime. */
    private const DATE_FORMATS = ['D, d M Y H:i:s \G\M\T', 'l, d-M-y H:i:s \G\M\T', 'D M j H:i:s Y'];

    public static function parse(?string $headerValue): ?float
    {
        $value = trim($headerValue ?? '');
        if ($value === '') {
            return null;
        }

        if (preg_match('/^[0-9]+$/D', $value) === 1) {
            // Clamp on the digit count first: a value too large for an int
            // would saturate or wrap when cast, and any such value is past
            // the cap anyway.
            $digits = ltrim($value, '0');
            if (strlen($digits) > 9) {
                return Constants::RETRY_AFTER_CAP_SECONDS;
            }

            return self::clamp((float) (int) $digits);
        }

        $timestamp = self::parseHttpDate($value);
        if ($timestamp === null) {
            return null;
        }

        return self::clamp($timestamp - microtime(true));
    }

    private static function parseHttpDate(string $value): ?int
    {
        // asctime pads a one-digit day with a space ("Nov  6"): collapse runs.
        $normalized = (string) preg_replace('/\s+/', ' ', $value);
        $utc = new \DateTimeZone('UTC');
        foreach (self::DATE_FORMATS as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $normalized, $utc);
            $errors = \DateTimeImmutable::getLastErrors();
            $clean = $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
            if ($date !== false && $clean) {
                return $date->getTimestamp();
            }
        }

        return null;
    }

    private static function clamp(float $seconds): float
    {
        return max(0.0, min($seconds, Constants::RETRY_AFTER_CAP_SECONDS));
    }
}
