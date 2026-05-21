<?php

declare(strict_types=1);

namespace PoliPage\Internal;

/**
 * Constants shared across the transport core and the public client.
 *
 * @internal Not part of the public API. Test files may reference these to keep
 *           wire-format expectations in one place.
 */
final class Constants
{
    public const DEFAULT_BASE_URL = 'https://api.poli.page';

    public const DEFAULT_MAX_RETRIES = 2;

    /** Default retry delay in **seconds** (Node ships 500 ms; PHP convention is seconds). */
    public const DEFAULT_RETRY_DELAY_SECONDS = 0.5;

    /** Default per-attempt request deadline in **seconds**. */
    public const DEFAULT_TIMEOUT_SECONDS = 60.0;

    /** Upper bound applied to any Retry-After response header value (sdk-php.md §6). */
    public const RETRY_AFTER_CAP_SECONDS = 30.0;

    public const USER_AGENT_PREFIX = 'poli-page-sdk-php/';

    public const PATH_RENDER = '/v1/render';
    public const PATH_RENDER_PREVIEW = '/v1/render/preview';

    /** sprintf template — caller passes the rawurlencode'd document id. */
    public const PATH_DOCUMENT = '/v1/documents/%s';
    public const PATH_DOCUMENT_PREVIEW = '/v1/documents/%s/preview';
    public const PATH_DOCUMENT_THUMBNAILS = '/v1/documents/%s/thumbnails';

    public const HEADER_REQUEST_ID = 'x-request-id';
    public const HEADER_RETRY_AFTER = 'retry-after';
    public const HEADER_DOCUMENT_PAGE_COUNT = 'x-document-page-count';
}
