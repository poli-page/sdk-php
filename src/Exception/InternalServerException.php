<?php

declare(strict_types=1);

namespace PoliPage\Exception;

/**
 * HTTP 5xx — the API itself failed. Retryable; the SDK already attempts
 * exponential backoff up to `maxRetries` retries before surfacing this.
 */
final class InternalServerException extends ApiStatusException
{
}
