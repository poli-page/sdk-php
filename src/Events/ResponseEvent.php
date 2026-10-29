<?php

declare(strict_types=1);

namespace PoliPage\Events;

/**
 * Fired once after each successful (2xx) HTTP response, once its whole body
 * has been read — carries the HTTP status code, the `X-Request-Id` header
 * value (or null if absent), and the round-trip duration in **milliseconds**,
 * body included (spec convention is `durationMs` across all SDKs). It never
 * fires for a 2xx whose body failed while it was read.
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
