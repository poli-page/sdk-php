<?php

declare(strict_types=1);

namespace PoliPage;

/**
 * Result of `$client->documents->preview($id)`. The deployed API responds
 * with `text/html` directly (not a JSON envelope), exposing the page
 * count via the `X-Document-Page-Count` response header.
 *
 * Note the field is `pageCount` (singular) here — distinct from
 * `PreviewResult::totalPages` on the render-preview endpoint. The
 * difference is intentional in the deployed API; mirrors Node `8523e13`.
 */
final readonly class DocumentPreviewResult
{
    public function __construct(
        public string $html,
        public int $pageCount,
    ) {
    }
}
