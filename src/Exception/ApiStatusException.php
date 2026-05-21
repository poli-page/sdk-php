<?php

declare(strict_types=1);

namespace PoliPage\Exception;

use PoliPage\PoliPageException;

/**
 * Any non-2xx response received from the Poli Page API. `$status` is
 * guaranteed non-null on instances of this class — concrete subclasses are
 * narrower (one per common HTTP status), but the API can return arbitrary
 * 4xx codes (e.g. 402) that fall through to this generic base.
 */
class ApiStatusException extends PoliPageException
{
    public function __construct(
        string $message,
        string $errorCode,
        int $status,
        ?string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $status, $requestId, $previous);
    }
}
