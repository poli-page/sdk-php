<?php

declare(strict_types=1);

namespace PoliPage\Exception;

/**
 * HTTP 410 — the addressed resource existed but has been soft-deleted
 * (typically a template version). Not retryable.
 */
final class GoneException extends ApiStatusException
{
}
