<?php

declare(strict_types=1);

namespace PoliPage\Internal\Http;

/**
 * Joins the configured base URL with a request path, normalising trailing /
 * on the base and leading / on the path so the result has exactly one slash
 * between them.
 *
 * @internal Pure helper; not part of the public API.
 */
final class UrlBuilder
{
    public static function build(string $baseUrl, string $path): string
    {
        $base = rtrim($baseUrl, '/');
        $suffix = str_starts_with($path, '/') ? $path : '/' . $path;

        return $base . $suffix;
    }
}
