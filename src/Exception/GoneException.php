<?php

declare(strict_types=1);

namespace PoliPage\Exception;

/**
 * HTTP 410 — the addressed document existed but has been soft-deleted or
 * has expired (`DOCUMENT_GONE`). Not retryable.
 */
final class GoneException extends ApiStatusException
{
}
