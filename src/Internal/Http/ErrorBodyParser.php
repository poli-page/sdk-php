<?php

declare(strict_types=1);

namespace PoliPage\Internal\Http;

/**
 * Parses a non-2xx response body into a `{code, message}` pair. Falls back to
 * `INTERNAL_ERROR` when the body is not parseable JSON or is not an object.
 *
 * Mirrors sdk-node/src/internal/http.ts `parseErrorBody`. Fallback chain:
 * `code → message → error → 'unknown_error'`.
 *
 * @internal
 */
final class ErrorBodyParser
{
    /**
     * @return array{code: string, message: string}
     */
    public static function parse(string $body, int $status): array
    {
        try {
            $json = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::internalErrorFallback($status);
        }
        if (!is_array($json)) {
            return self::internalErrorFallback($status);
        }

        // RFC 7807: prefer `detail` (specific reason) > `title` (generic name)
        // > legacy `message` field > canned "HTTP <status>". Code is verbatim
        // from the API — never inferred from message.
        $code = self::firstStringField($json, ['code', 'error']) ?? 'unknown_error';
        $message = self::firstStringField($json, ['detail', 'title', 'message']) ?? "HTTP $status";

        return ['code' => $code, 'message' => $message];
    }

    /**
     * @param array<array-key, mixed> $json
     * @param list<string>            $fields
     */
    private static function firstStringField(array $json, array $fields): ?string
    {
        foreach ($fields as $field) {
            if (isset($json[$field]) && is_string($json[$field])) {
                return $json[$field];
            }
        }

        return null;
    }

    /**
     * @return array{code: string, message: string}
     */
    private static function internalErrorFallback(int $status): array
    {
        return [
            'code' => 'INTERNAL_ERROR',
            'message' => "HTTP $status: response body was not valid JSON",
        ];
    }
}
