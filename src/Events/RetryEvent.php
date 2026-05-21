<?php

declare(strict_types=1);

namespace PoliPage\Events;

use PoliPage\PoliPageException;

/**
 * Fired before each retry sleep — carries the upcoming attempt number,
 * the sleep duration in **milliseconds** (spec convention is `delayMs`
 * across all SDKs even though PHP internals use seconds), and the
 * exception that caused the previous attempt to fail.
 *
 * Hooks must not throw; the client wraps every call in a swallowing
 * try/catch so a misbehaving hook never breaks the request.
 */
final readonly class RetryEvent
{
    public function __construct(
        public int $attempt,
        public float $delayMs,
        public PoliPageException $reason,
    ) {
    }
}
