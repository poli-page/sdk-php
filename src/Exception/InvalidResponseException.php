<?php

declare(strict_types=1);

namespace PoliPage\Exception;

use PoliPage\PoliPageException;

/**
 * A 2xx answer whose body is not JSON, from a method that expects JSON
 * (`render->document()`, `render->pdf()` and `render->pdfStream()` for their
 * API call, `documents->get()`, `documents->thumbnails()`). Error code
 * `invalid_response`; `$status` is the real 2xx and `$requestId` the
 * answer's `x-request-id`. Such a body usually comes from something between
 * the SDK and the API (a proxy, a captive portal), which answers the same way
 * again, so it is never retried.
 *
 * `toPayload()` answers 502 for it: an integration must not answer an error
 * with the 2xx it carries.
 */
final class InvalidResponseException extends PoliPageException
{
    protected function payloadStatus(): int
    {
        return 502;
    }
}
