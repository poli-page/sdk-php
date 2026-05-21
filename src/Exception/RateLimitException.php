<?php

declare(strict_types=1);

namespace PoliPage\Exception;

/**
 * HTTP 429 — too many requests. Retryable; the SDK already attempts up to
 * `maxRetries` retries internally before surfacing this. Callers that see
 * it should back off further on top of the SDK's retry budget.
 */
final class RateLimitException extends ApiStatusException
{
}
