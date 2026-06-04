<?php

declare(strict_types=1);

namespace PoliPage\Events;

/**
 * Fired once after each successful (2xx) HTTP response — carries the HTTP
 * status code, the `X-Request-Id` header value (or null if absent), and the
 * round-trip duration in **milliseconds** (spec convention is `durationMs`
 * across all SDKs).
 *
 * Hooks must not throw; the client wraps every call in a swallowing
 * try/catch so a misbehaving hook never breaks the request.
 */
final readonly class ResponseEvent
{
    public function __construct(
        public int $status,
        public ?string $requestId,
        public int $durationMs,
    ) {
    }
}
