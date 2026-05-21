<?php

declare(strict_types=1);

namespace PoliPage\Exception;

/**
 * Per-request deadline exceeded. Extends {@see ConnectionException} because
 * timeouts are transport-level failures from the caller's perspective:
 * `instanceof ConnectionException` catches both, and `isNetworkError()`
 * returns true for either.
 */
final class TimeoutException extends ConnectionException
{
}
