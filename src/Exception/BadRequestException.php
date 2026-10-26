<?php

declare(strict_types=1);

namespace PoliPage\Exception;

/**
 * HTTP 400 — request payload failed validation (missing data, unknown
 * field, template error, etc.). Not retryable.
 */
final class BadRequestException extends ApiStatusException
{
}
