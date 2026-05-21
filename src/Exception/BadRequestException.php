<?php

declare(strict_types=1);

namespace PoliPage\Exception;

/**
 * HTTP 400 — request payload failed validation (missing data, missing
 * project/template, bad version format, etc.). Not retryable.
 */
final class BadRequestException extends ApiStatusException
{
}
