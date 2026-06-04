<?php

declare(strict_types=1);

namespace PoliPage\Events;

/**
 * Fired just before each HTTP send attempt — carries the HTTP method,
 * full URL, and the 1-based attempt number (1 = initial, 2+ = retries).
 *
 * Hooks must not throw; the client wraps every call in a swallowing
 * try/catch so a misbehaving hook never breaks the request.
 */
final readonly class RequestEvent
{
    public function __construct(
        public string $method,
        public string $url,
        public int $attempt,
    ) {
    }
}
