<?php

declare(strict_types=1);

namespace PoliPage\Exception;

/**
 * HTTP 404 — the addressed resource (project, template, or document) does
 * not exist, or the project is not published in the key's environment.
 * Not retryable.
 */
final class NotFoundException extends ApiStatusException
{
}
