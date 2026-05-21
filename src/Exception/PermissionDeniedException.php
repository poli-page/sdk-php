<?php

declare(strict_types=1);

namespace PoliPage\Exception;

/**
 * HTTP 403 — authenticated but the caller is not permitted to perform the
 * action (forbidden scope, cancelled organisation, etc.). Not retryable.
 */
final class PermissionDeniedException extends ApiStatusException
{
}
