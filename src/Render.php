<?php

declare(strict_types=1);

namespace PoliPage;

use PoliPage\Internal\Constants;
use PoliPage\Internal\Transport;

/**
 * The `render` namespace exposed as `$client->render`. Phase 2 ships only
 * `preview` (the simplest of the four operations — accepts both project
 * and inline mode). Phase 3 adds `pdf`, `pdfStream`, and `document`.
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
     * Generate paginated HTML preview output for either a stored project
     * + template or raw inline HTML. Calls `POST /v1/render/preview`.
     *
     * @throws Exception\AuthenticationException   on 401
     * @throws Exception\PermissionDeniedException on 403
     * @throws Exception\BadRequestException       on 400
     * @throws Exception\NotFoundException         on 404
     * @throws Exception\RateLimitException        on 429 after retries exhausted
     * @throws Exception\InternalServerException   on 5xx after retries exhausted
     * @throws Exception\ConnectionException       on transport failure
     * @throws PoliPageException                   catch-all base
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
