<?php

declare(strict_types=1);

namespace PoliPage\Exception;

/**
 * HTTP 404 — the addressed resource (project, template, version, or
 * document) does not exist. Not retryable.
 */
final class NotFoundException extends ApiStatusException
{
}
