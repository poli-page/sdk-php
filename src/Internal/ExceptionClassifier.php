<?php

declare(strict_types=1);

namespace PoliPage\Internal;

use PoliPage\Exception\ApiStatusException;
use PoliPage\Exception\AuthenticationException;
use PoliPage\Exception\BadRequestException;
use PoliPage\Exception\ConnectionException;
use PoliPage\Exception\GoneException;
use PoliPage\Exception\InternalServerException;
use PoliPage\Exception\NotFoundException;
use PoliPage\Exception\PermissionDeniedException;
use PoliPage\Exception\RateLimitException;
use PoliPage\Exception\TimeoutException;
use PoliPage\PoliPageException;

/**
 * Maps a `(code, status, message, requestId)` tuple to the most specific
 * concrete exception in the SDK's hierarchy. The SDK throws subclasses so
 * users can `catch (AuthenticationException $e)` / `catch (RateLimitException $e)`
 * naturally, matching stripe-php / openai-php / aws-sdk-php convention.
 *
 * 5xx statuses map to {@see InternalServerException}; uncategorised 4xx
 * statuses (402, 405, 422, …) fall through to the generic
 * {@see ApiStatusException} base. Transport failures use the dedicated
 * factories {@see networkError()} / {@see timeout()}.
 *
 * @internal
 */
final class ExceptionClassifier
{
    public static function fromStatus(
        string $errorCode,
        int $status,
        string $message,
        ?string $requestId,
        ?\Throwable $previous = null,
    ): ApiStatusException {
        return match (true) {
            $status === 400 => new BadRequestException($message, $errorCode, $status, $requestId, $previous),
            $status === 401 => new AuthenticationException($message, $errorCode, $status, $requestId, $previous),
            $status === 403 => new PermissionDeniedException($message, $errorCode, $status, $requestId, $previous),
            $status === 404 => new NotFoundException($message, $errorCode, $status, $requestId, $previous),
            $status === 410 => new GoneException($message, $errorCode, $status, $requestId, $previous),
            $status === 429 => new RateLimitException($message, $errorCode, $status, $requestId, $previous),
            $status >= 500 && $status < 600 => new InternalServerException($message, $errorCode, $status, $requestId, $previous),
            default => new ApiStatusException($message, $errorCode, $status, $requestId, $previous),
        };
    }

    public static function networkError(string $message, ?\Throwable $previous): ConnectionException
    {
        return new ConnectionException(
            $message,
            PoliPageException::NETWORK_ERROR,
            null,
            null,
            $previous,
        );
    }

    public static function timeout(string $message, ?\Throwable $previous = null): TimeoutException
    {
        return new TimeoutException(
            $message,
            PoliPageException::TIMEOUT,
            null,
            null,
            $previous,
        );
    }
}
