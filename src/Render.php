<?php

declare(strict_types=1);

namespace PoliPage;

use PoliPage\Internal\Constants;
use PoliPage\Internal\Transport;
use Psr\Http\Message\StreamInterface;

/**
 * The `render` namespace exposed as `$client->render`. Phases 2 + 3
 * cover the four render operations; Phase 4 adds Documents.
 */
final class Render
{
    /**
     * @internal Construction is owned by {@see PoliPage}.
     */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Render a PDF and return its raw bytes. Two HTTP calls under the hood:
     * `POST /v1/render` produces a stored document, then `GET presignedPdfUrl`
     * fetches the bytes. From the caller's perspective it's one operation.
     *
     * @throws Exception\AuthenticationException   on 401 from /v1/render
     * @throws Exception\PermissionDeniedException on 403 from /v1/render
     * @throws Exception\BadRequestException       on 400 from /v1/render
     * @throws Exception\NotFoundException         on 404 from /v1/render
     * @throws Exception\RateLimitException        on 429 from /v1/render (after retries)
     * @throws Exception\InternalServerException   on 5xx from /v1/render (after retries)
     * @throws Exception\ConnectionException       on transport failure
     * @throws PoliPageException                   on DOWNLOAD_FAILED / INTERNAL_ERROR / catch-all
     */
    public function pdf(ProjectModeInput $input): string
    {
        return $this->document($input)->downloadPdf();
    }

    /**
     * Like {@see pdf} but returns the PSR-7 body stream directly so the
     * caller can pipe it to disk or a response without buffering the whole
     * PDF in memory. The caller owns the stream's lifecycle — close it
     * (or let `__destruct` close it) when done.
     *
     * @throws PoliPageException see {@see pdf} for the failure modes
     */
    public function pdfStream(ProjectModeInput $input): StreamInterface
    {
        $descriptor = $this->document($input);

        return $this->transport->streamBytes($descriptor->presignedPdfUrl, $input->timeout);
    }

    /**
     * Render a PDF, store it server-side, and return the descriptor with
     * a fresh presigned URL. Same wire endpoint as {@see pdf} — the
     * difference is that `pdf` chains a second fetch for the bytes;
     * `document` returns immediately so the caller can defer the download
     * (or skip it entirely, e.g. when only the documentId is needed).
     *
     * @throws PoliPageException see {@see pdf} for the failure modes
     */
    public function document(ProjectModeInput $input): DocumentDescriptor
    {
        $raw = $this->transport->post(
            Constants::PATH_RENDER,
            $input->toWire(),
            $input->idempotencyKey,
            $input->timeout,
        );

        return DocumentDescriptor::fromWire($raw, $this->transport);
    }

    /**
     * Generate paginated HTML preview output for either a stored project
     * + template or raw inline HTML. Calls `POST /v1/render/preview`.
     *
     * @throws PoliPageException see {@see pdf} for the failure modes
     */
    public function preview(RenderInput $input): PreviewResult
    {
        $response = $this->transport->post(
            Constants::PATH_RENDER_PREVIEW,
            $input->toWire(),
            $input->idempotencyKey,
            $input->timeout,
        );

        $html = $response['html'] ?? null;
        $totalPages = $response['totalPages'] ?? null;
        $environment = $response['environment'] ?? null;

        if (!is_string($html) || !is_int($totalPages) || !is_string($environment)) {
            throw new PoliPageException(
                'Unexpected preview response shape from API',
                PoliPageException::INTERNAL_ERROR,
            );
        }

        return new PreviewResult(
            html: $html,
            totalPages: $totalPages,
            environment: $environment,
        );
    }
}
