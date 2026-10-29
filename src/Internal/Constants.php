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

    /** Upper bound applied to any Retry-After response header value (SDK spec §9.2). */
    public const RETRY_AFTER_CAP_SECONDS = 30.0;

    /**
     * Never retried, even on a 429 / 5xx with `Retry-After` (SDK spec §9.1):
     * they last until the monthly reset or until the bucket access is
     * restored, so waiting cannot help.
     */
    public const NON_RETRYABLE_CODES = ['QUOTA_EXCEEDED', 'OVERAGE_CAP_EXCEEDED', 'STORAGE_ACCESS_DENIED'];

    // SDK-side error messages (SDK spec §8.3): exact texts, the same in every
    // Poli Page SDK. `{timeoutMs}` is the attempt's timeout in milliseconds,
    // `{status}` the storage status of a failed download.
    public const MESSAGE_INVALID_OPTIONS = 'apiKey is required';
    public const MESSAGE_NETWORK_ERROR = 'Network request failed';
    public const MESSAGE_TIMEOUT = 'Request timed out after {timeoutMs}ms';
    public const MESSAGE_DOWNLOAD_FAILED = 'Failed to download PDF: HTTP {status}';
    public const MESSAGE_DOWNLOAD_FAILED_NETWORK = 'Failed to download PDF: network error';
    public const MESSAGE_PROJECT_REQUIRED = 'project is required';

    public const USER_AGENT_PREFIX = 'poli-page-sdk-php/';

    public const PATH_RENDER = '/v1/render';

    /** sprintf template — caller passes the rawurlencode'd document id. */
    public const PATH_DOCUMENT = '/v1/documents/%s';
    public const PATH_DOCUMENT_PREVIEW = '/v1/documents/%s/preview';
    public const PATH_DOCUMENT_THUMBNAILS = '/v1/documents/%s/thumbnails';

    public const HEADER_REQUEST_ID = 'x-request-id';
    public const HEADER_RETRY_AFTER = 'retry-after';
    public const HEADER_DOCUMENT_PAGE_COUNT = 'x-document-page-count';
}
