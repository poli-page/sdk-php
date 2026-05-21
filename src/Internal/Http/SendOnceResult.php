<?php

declare(strict_types=1);

namespace PoliPage\Internal\Http;

use PoliPage\PoliPageException;
use Psr\Http\Message\ResponseInterface;

/**
 * Outcome of a single send attempt inside the retry loop. Either the
 * response is set (success) or the error is set (failure); never both.
 * `$retryAfter` carries the server's hint (in seconds) when the failure
 * is retryable.
 *
 * @internal
 */
final readonly class SendOnceResult
{
    public function __construct(
        public ?ResponseInterface $response,
        public ?PoliPageException $error,
        public ?float $retryAfter,
        public bool $retryable,
    ) {
    }
}
