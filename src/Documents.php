<?php

declare(strict_types=1);

namespace PoliPage;

use PoliPage\Internal\Constants;
use PoliPage\Internal\Transport;

/**
 * The `documents` namespace exposed as `$client->documents`. Hosts the
 * four stored-document operations per spec v1.3 §6.
 */
final class Documents
{
    /**
     * @internal Construction is owned by {@see PoliPage}.
     */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Retrieve a stored document's descriptor with a fresh presigned URL.
     * Use this when the URL handed back by `$client->render->document(...)`
     * has expired (15-minute TTL).
     *
     * Spec §6.1. GET /v1/documents/:id.
     *
     * @throws Exception\AuthenticationException   on 401
     * @throws Exception\PermissionDeniedException on 403
     * @throws Exception\NotFoundException         on 404
     * @throws Exception\GoneException             on 410 (document soft-deleted)
     * @throws PoliPageException                   catch-all base
     */
    public function get(string $id): DocumentDescriptor
    {
        $raw = $this->transport->get(self::pathFor($id), null);

        return DocumentDescriptor::fromWire($raw, $this->transport);
    }

    /**
     * Retrieve a stored document's paginated HTML preview. The deployed
     * API responds with `text/html` directly and exposes the page count
     * via the `X-Document-Page-Count` header — the SDK assembles the
     * envelope from both.
     *
     * No counter increments — the engine performs no work.
     *
     * Spec §6.2. GET /v1/documents/:id/preview.
     *
     * @throws PoliPageException see {@see get} for the failure modes
     */
    public function preview(string $id): DocumentPreviewResult
    {
        $response = $this->transport->getText(self::pathFor($id) . '/preview', null);
        $pageCount = self::parsePageCount($response->header(Constants::HEADER_DOCUMENT_PAGE_COUNT));

        return new DocumentPreviewResult(
            html: $response->body,
            pageCount: $pageCount,
        );
    }

    /**
     * Generate page thumbnails for a stored document.
     *
     * The deployed API wants the options object nested under a top-level
     * `thumbnails` key (`{"thumbnails": {...options}}`); the SDK wraps
     * and unwraps so callers never see the envelope. Mirrors Node
     * documents.ts:96.
     *
     * Spec §6.3. POST /v1/documents/:id/thumbnails.
     *
     * @return list<Thumbnail>
     *
     * @throws PoliPageException see {@see get} for the failure modes
     */
    public function thumbnails(string $id, ThumbnailOptions $options): array
    {
        $response = $this->transport->post(
            self::pathFor($id) . '/thumbnails',
            ['thumbnails' => $options->toWire()],
            null,
            null,
        );

        $raw = $response['thumbnails'] ?? null;
        if (!is_array($raw)) {
            throw new PoliPageException(
                'Unexpected thumbnails wire shape: missing or non-array "thumbnails" field',
                PoliPageException::INTERNAL_ERROR,
            );
        }

        $result = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                throw new PoliPageException(
                    'Unexpected thumbnails wire shape: entry must be an object',
                    PoliPageException::INTERNAL_ERROR,
                );
            }
            $result[] = Thumbnail::fromWire($entry);
        }

        return $result;
    }

    /**
     * Soft-delete a stored document. The PDF is purged from storage;
     * metadata is retained for audit. A re-delete surfaces as a
     * {@see Exception\GoneException} from the transport layer.
     *
     * Spec §6.4. DELETE /v1/documents/:id.
     *
     * @throws PoliPageException see {@see get} for the failure modes
     */
    public function delete(string $id): void
    {
        $this->transport->delete(self::pathFor($id), null);
    }

    private static function pathFor(string $id): string
    {
        return sprintf(Constants::PATH_DOCUMENT, rawurlencode($id));
    }

    private static function parsePageCount(?string $headerValue): int
    {
        if ($headerValue === null) {
            return 0;
        }
        if (!is_numeric($headerValue)) {
            return 0;
        }
        $value = (int) $headerValue;

        return $value >= 0 ? $value : 0;
    }
}
