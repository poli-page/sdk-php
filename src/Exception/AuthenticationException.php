<?php

declare(strict_types=1);

namespace PoliPage\Exception;

/**
 * HTTP 401 — invalid or missing API key. Not retryable; callers typically
 * refresh credentials and reissue.
 */
final class AuthenticationException extends ApiStatusException
{
}
