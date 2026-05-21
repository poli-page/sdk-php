<?php

declare(strict_types=1);

namespace PoliPage\Internal;

use Psr\Http\Message\StreamInterface;

/**
 * Small request seam used by namespace classes (Render, Documents) so they
 * can be unit-tested without spinning up a PSR-18 mock. PoliPage implements
 * this interface; tests inject a hand-rolled fake.
 *
 * `post` carries auth + retry + idempotency + hook firing. `fetchBytes` and
 * `streamBytes` target presigned S3 URLs — unauthenticated, single attempt,
 * no retry, no idempotency. The four-verb design decision (n2 design memory)
 * means we accept the surface up-front; `get` and `delete` join in Phase 4
 * when Documents lands.
 *
 * @internal Not part of the public API.
 */
interface Transport
{
    /**
     * Send an authenticated POST and return the decoded JSON body.
     *
     * @param array<string, mixed> $body
     * @param float|null           $timeout per-call override; `null` defers to the client default
     *
     * @return array<array-key, mixed>
     */
    public function post(string $path, array $body, ?string $idempotencyKey, ?float $timeout): array;

    /**
     * Fetch the body bytes of an arbitrary URL — used to download PDFs from
     * presigned S3 URLs. No auth, no retry. Throws PoliPageException with
     * code DOWNLOAD_FAILED on transport failure or non-2xx response.
     */
    public function fetchBytes(string $url, ?float $timeout): string;

    /**
     * Like {@see fetchBytes} but returns the PSR-7 stream directly so the
     * caller can pipe it to disk / a response without buffering. The caller
     * owns the stream's lifecycle and is responsible for closing it.
     */
    public function streamBytes(string $url, ?float $timeout): StreamInterface;
}
