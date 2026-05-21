<?php

declare(strict_types=1);

namespace PoliPage\Exception;

use PoliPage\PoliPageException;

/**
 * Transport-level failure (DNS error, connection refused, TLS handshake
 * failure, etc.). Carries no HTTP status; `$status` is always `null`.
 * Always retryable.
 */
class ConnectionException extends PoliPageException
{
}
