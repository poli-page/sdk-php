<?php

declare(strict_types=1);

namespace PoliPage;

use PoliPage\Internal\Constants;
use PoliPage\Internal\Transport;
use Psr\Http\Message\StreamInterface;

/**
 * The `render` namespace exposed as `$client->render`: the three render
 * operations, all `POST /v1/render`.
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
     * The download is bounded as a whole by the timeout (`$input->timeout`,
     * else the client's) and is never retried.
     *
     * @throws Exception\AuthenticationException   on 401 from /v1/render
     * @throws Exception\PermissionDeniedException on 403 from /v1/render
     * @throws Exception\BadRequestException       on 400 from /v1/render
     * @throws Exception\NotFoundException         on 404 from /v1/render
     * @throws Exception\RateLimitException        on 429 from /v1/render (after retries)
     * @throws Exception\InternalServerException   on 5xx from /v1/render (after retries)
     * @throws Exception\ConnectionException       on transport failure
     * @throws Exception\InvalidResponseException on a 2xx from /v1/render whose body is not JSON
     * @throws PoliPageException                   on DOWNLOAD_FAILED / INTERNAL_ERROR / catch-all
     */
    public function pdf(RenderInput $input): string
    {
        return $this->document($input)->downloadPdf($input->timeout);
    }

    /**
     * Like {@see pdf} but returns the PSR-7 body stream directly so the
     * caller can pipe it to disk or a response without buffering the whole
     * PDF in memory. The caller owns the stream's lifecycle — close it
     * (or let `__destruct` close it) when done.
     *
     * The download is bounded by the timeout (`$input->timeout`, else the
     * client's) as an idle timeout, not a total: the wait for the first byte
     * and each gap between two chunks, so a large PDF read at the caller's
     * pace is not cut off. Before the answer, this method throws
     * `DOWNLOAD_FAILED` with the message `Failed to download PDF: timeout`;
     * mid-body, reading the stream throws it. With Guzzle, the body streams
     * through its stream handler (part of its default handler stack); a
     * handler stack without it buffers the PDF, and the timeout then bounds
     * the whole download.
     *
     * @throws PoliPageException see {@see pdf} for the failure modes
     */
    public function pdfStream(RenderInput $input): StreamInterface
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
    public function document(RenderInput $input): DocumentDescriptor
    {
        if ($input->project === '') {
            throw new PoliPageException(
                Constants::MESSAGE_PROJECT_REQUIRED,
                PoliPageException::PROJECT_REQUIRED_FOR_DOCUMENT,
            );
        }

        $raw = $this->transport->post(
            Constants::PATH_RENDER,
            $input->toWire(),
            $input->idempotencyKey,
            $input->timeout,
        );

        return DocumentDescriptor::fromWire($raw, $this->transport);
    }
}
