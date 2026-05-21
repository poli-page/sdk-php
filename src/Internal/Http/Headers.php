<?php

declare(strict_types=1);

namespace PoliPage\Internal\Http;

/**
 * Builds the SDK's outgoing request header map. POST gets `Content-Type` plus
 * an optional `Idempotency-Key`; GET and DELETE keep auth/UA/Accept only.
 * `Accept` is always `application/json` — every SDK-originated request hits
 * a JSON-returning endpoint; PDF bytes come from a separate plain fetch
 * against the presigned S3 URL, outside this helper's scope.
 *
 * Mirrors sdk-node/src/internal/http.ts `buildHeaders`.
 *
 * @internal
 *
 * @return array<string, string>
 */
final class Headers
{
    /**
     * @param 'GET'|'POST'|'DELETE' $method
     *
     * @return array<string, string>
     */
    public static function build(
        string $method,
        string $apiKey,
        ?string $idempotencyKey,
        string $userAgent,
    ): array {
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
            'User-Agent' => $userAgent,
        ];
        if ($method === 'POST') {
            $headers['Content-Type'] = 'application/json';
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $headers['Idempotency-Key'] = $idempotencyKey;
            }
        }

        return $headers;
    }
}
