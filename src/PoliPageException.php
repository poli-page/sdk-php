<?php

declare(strict_types=1);

namespace PoliPage;

use PoliPage\Exception\ApiStatusException;
use PoliPage\Internal\Constants;

/**
 * Base exception type for every error raised by the Poli Page SDK.
 *
 * `$errorCode` is a string carrying either a reserved SDK code (see the
 * `*_ERROR`, `INVALID_OPTIONS`, `TIMEOUT`, `ABORTED` constants) or an API
 * code passed through verbatim from the wire (spec §7.2). `$status` is the
 * HTTP status when the error originated from a non-2xx response; it is
 * `null` for SDK-internal failures and transport-level errors.
 *
 * The class is concrete so the SDK can throw it directly for
 * `INVALID_OPTIONS`, `ABORTED`, `UNKNOWN_ERROR`, `DOWNLOAD_FAILED`, and any
 * other case that does not fit one of the specialised subclasses under
 * {@see \PoliPage\Exception}. Idiomatic PHP usage favours `instanceof`
 * checks against the hierarchy over predicate calls.
 */
class PoliPageException extends \RuntimeException
{
    // Reserved (SDK-internal) codes — $status is null when these are raised.
    public const INVALID_OPTIONS = 'invalid_options';
    public const NETWORK_ERROR = 'network_error';
    public const TIMEOUT = 'timeout';
    public const ABORTED = 'aborted';
    public const UNKNOWN_ERROR = 'unknown_error';
    public const DOWNLOAD_FAILED = 'DOWNLOAD_FAILED';
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    // Known API codes — passed through verbatim. The set is open: the API may
    // return a code missing here, and `$errorCode` carries it unchanged.
    public const MISSING_API_KEY = 'MISSING_API_KEY';
    public const INVALID_API_KEY = 'INVALID_API_KEY';
    public const FORBIDDEN = 'FORBIDDEN';
    public const ORGANIZATION_CANCELLED = 'ORGANIZATION_CANCELLED';
    public const ORGANIZATION_PURGED = 'ORGANIZATION_PURGED';
    public const ORGANIZATION_PAYMENT_GRACE = 'ORGANIZATION_PAYMENT_GRACE';
    public const ENVIRONMENT_PROTECTED = 'ENVIRONMENT_PROTECTED';
    public const THUMBNAILS_NOT_AVAILABLE = 'THUMBNAILS_NOT_AVAILABLE';
    public const NOT_FOUND = 'NOT_FOUND';
    public const PROJECT_NOT_FOUND = 'PROJECT_NOT_FOUND';
    public const PROJECT_NOT_IN_ENVIRONMENT = 'PROJECT_NOT_IN_ENVIRONMENT';
    public const TEMPLATE_NOT_FOUND = 'TEMPLATE_NOT_FOUND';
    public const DOCUMENT_NOT_FOUND = 'DOCUMENT_NOT_FOUND';
    public const DOCUMENT_GONE = 'DOCUMENT_GONE';
    public const VALIDATION_ERROR = 'VALIDATION_ERROR';
    public const MISSING_DATA = 'MISSING_DATA';
    public const MISSING_TEMPLATE_SLUG = 'MISSING_TEMPLATE_SLUG';
    public const PROJECT_REQUIRED_FOR_DOCUMENT = 'PROJECT_REQUIRED_FOR_DOCUMENT';
    public const ENVIRONMENT_MISMATCH = 'ENVIRONMENT_MISMATCH';
    public const EMPTY_DOCUMENT = 'EMPTY_DOCUMENT';
    public const TEMPLATE_ERROR = 'TEMPLATE_ERROR';
    /** The template, a partial or an SVG asset holds an injection attempt; `detail` names it. Not retried. */
    public const TEMPLATE_UNSAFE = 'TEMPLATE_UNSAFE';
    public const RENDER_BODY_TOO_LARGE = 'RENDER_BODY_TOO_LARGE';
    public const DOCUMENT_TOO_LARGE = 'DOCUMENT_TOO_LARGE';
    public const QUOTA_EXCEEDED = 'QUOTA_EXCEEDED';
    public const OVERAGE_CAP_EXCEEDED = 'OVERAGE_CAP_EXCEEDED';
    public const RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED';
    public const STORAGE_ACCESS_DENIED = 'STORAGE_ACCESS_DENIED';
    public const PUBLISH_IN_PROGRESS = 'PUBLISH_IN_PROGRESS';
    public const ORGANIZATION_MIGRATING = 'ORGANIZATION_MIGRATING';
    public const RENDER_TIMEOUT = 'RENDER_TIMEOUT';

    /** Same wire value as INTERNAL_ERROR; distinct constant name disambiguates at call sites. */
    public const API_INTERNAL_ERROR = 'INTERNAL_ERROR';

    public readonly string $errorCode;
    public readonly ?int $status;
    public readonly ?string $requestId;

    public function __construct(
        string $message,
        string $errorCode,
        ?int $status = null,
        ?string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
        $this->status = $status;
        $this->requestId = $requestId;
    }

    // Predicate helpers (SDK spec §8.1), the same in every Poli Page SDK. They
    // read `status` and `code`, so they also hold for an error that is not one
    // of the status subclasses: a DOWNLOAD_FAILED with the storage's 403 is an
    // auth error. Idiomatic PHP can catch the subclasses instead.

    /**
     * `status` is 401 or 403.
     */
    public function isAuthError(): bool
    {
        return $this->status === 401 || $this->status === 403;
    }

    /**
     * `status` is 429.
     */
    public function isRateLimitError(): bool
    {
        return $this->status === 429;
    }

    /**
     * `status` is 400.
     */
    public function isValidationError(): bool
    {
        return $this->status === 400;
    }

    /**
     * `code` is `network_error` or `timeout`.
     */
    public function isNetworkError(): bool
    {
        return $this->errorCode === self::NETWORK_ERROR || $this->errorCode === self::TIMEOUT;
    }

    /**
     * A network error, a timeout, a 5xx or a 429 the SDK retries (SDK spec
     * §9.1). Never `aborted`, and never `QUOTA_EXCEEDED`,
     * `OVERAGE_CAP_EXCEEDED` or `STORAGE_ACCESS_DENIED`: waiting cannot fix
     * them. A `DOWNLOAD_FAILED` is never retryable either, whatever the
     * storage status.
     */
    public function isRetryable(): bool
    {
        if ($this->errorCode === self::ABORTED || $this->errorCode === self::DOWNLOAD_FAILED || in_array($this->errorCode, Constants::NON_RETRYABLE_CODES, true)) {
            return false;
        }
        if ($this->isNetworkError()) {
            return true;
        }
        if ($this->status !== null && $this->status >= 500) {
            return true;
        }

        return $this->status === 429;
    }

    /**
     * Returns true when the receiver is an HTTP status–carrying API error,
     * i.e. when {@see $status} is guaranteed non-null. Useful as a narrowing
     * helper alongside the hierarchy-based catch blocks.
     */
    public function isApiStatusError(): bool
    {
        return $this instanceof ApiStatusException;
    }

    /**
     * Canonical wire payload for framework integrations:
     * `{code, message, status, requestId}`. `status` surfaces 503 for
     * `ConnectionException`, 504 for `TimeoutException`, and the API
     * status otherwise. The {@see $status} property itself stays `null`
     * for transport errors — only the payload surfaces 503/504.
     *
     * @return array{code: string, message: string, status: ?int, requestId: ?string}
     */
    public function toPayload(): array
    {
        return [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'status' => $this->payloadStatus(),
            'requestId' => $this->requestId,
        ];
    }

    /**
     * Hook overridden by ConnectionException → 503 and TimeoutException → 504.
     */
    protected function payloadStatus(): ?int
    {
        return $this->status;
    }
}
