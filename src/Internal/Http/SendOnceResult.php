<?php

declare(strict_types=1);

namespace PoliPage\Internal\Http;

use PoliPage\PoliPageException;
use Psr\Http\Message\ResponseInterface;

/**
 * Outcome of a single send attempt inside the retry loop. Either the
 * response is set (success, its body already read in full) or the error is
 * set (failure); never both. `$retryAfter` carries the server's hint (in
 * seconds) when the failure is retryable; whether it is comes from the
 * error itself ({@see PoliPageException::isRetryable()}).
 *
 * @internal
 */
final readonly class SendOnceResult
{
    public function __construct(
        public ?ResponseInterface $response,
        public ?PoliPageException $error,
        public ?float $retryAfter = null,
    ) {
    }

    /**
     * @phpstan-assert-if-true !null $this->response
     * @phpstan-assert-if-true null  $this->error
     * @phpstan-assert-if-false null  $this->response
     * @phpstan-assert-if-false !null $this->error
     */
    public function isOk(): bool
    {
        return $this->response !== null;
    }
}
