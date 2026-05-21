<?php

declare(strict_types=1);

namespace PoliPage;

/**
 * Result of `client->render->preview($input)`. Wire shape produced by
 * `POST /v1/render/preview` — paginated HTML preview output. The
 * `totalPages` field on this DTO is distinct from `pageCount` on
 * {@see DocumentPreviewResult} (added in Phase 4) — see sdk-node `8523e13`.
 *
 * `environment` is `"sandbox"` for test API keys and `"live"` for production.
 */
final readonly class PreviewResult
{
    public function __construct(
        public string $html,
        public int $totalPages,
        public string $environment,
    ) {
    }
}
