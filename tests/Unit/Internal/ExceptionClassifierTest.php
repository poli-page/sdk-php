<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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
use PoliPage\Internal\ExceptionClassifier;
use PoliPage\PoliPageException;

#[CoversClass(ExceptionClassifier::class)]
final class ExceptionClassifierTest extends TestCase
{
    public function testReturnsBadRequestExceptionFor400(): void
    {
        $exception = ExceptionClassifier::fromStatus('VALIDATION_ERROR', 400, 'bad', 'req_1');
        self::assertInstanceOf(BadRequestException::class, $exception);
        self::assertSame('VALIDATION_ERROR', $exception->errorCode);
        self::assertSame(400, $exception->status);
        self::assertSame('req_1', $exception->requestId);
        self::assertSame('bad', $exception->getMessage());
    }

    public function testReturnsAuthenticationExceptionFor401(): void
    {
        $exception = ExceptionClassifier::fromStatus('INVALID_API_KEY', 401, 'unauthorized', null);
        self::assertInstanceOf(AuthenticationException::class, $exception);
        self::assertTrue($exception->isAuthError());
    }

    public function testReturnsPermissionDeniedExceptionFor403(): void
    {
        $exception = ExceptionClassifier::fromStatus('FORBIDDEN', 403, 'no', null);
        self::assertInstanceOf(PermissionDeniedException::class, $exception);
        self::assertTrue($exception->isAuthError());
    }

    public function testReturnsNotFoundExceptionFor404(): void
    {
        $exception = ExceptionClassifier::fromStatus('NOT_FOUND', 404, 'missing', null);
        self::assertInstanceOf(NotFoundException::class, $exception);
    }

    public function testReturnsGoneExceptionFor410(): void
    {
        $exception = ExceptionClassifier::fromStatus('GONE', 410, 'gone', null);
        self::assertInstanceOf(GoneException::class, $exception);
    }

    public function testReturnsRateLimitExceptionFor429(): void
    {
        $exception = ExceptionClassifier::fromStatus('QUOTA_EXCEEDED', 429, 'too many', null);
        self::assertInstanceOf(RateLimitException::class, $exception);
        self::assertTrue($exception->isRateLimitError());
    }

    public function testReturnsInternalServerExceptionFor5xx(): void
    {
        foreach ([500, 502, 503, 504] as $status) {
            $exception = ExceptionClassifier::fromStatus('INTERNAL_ERROR', $status, 'oops', null);
            self::assertInstanceOf(InternalServerException::class, $exception, "status $status");
            self::assertTrue($exception->isRetryable());
        }
    }

    public function testFallsBackToGenericApiStatusExceptionForUnknown4xx(): void
    {
        $exception = ExceptionClassifier::fromStatus('PAYMENT_REQUIRED', 402, 'pay up', null);
        self::assertInstanceOf(ApiStatusException::class, $exception);
        // not one of the well-known subclasses
        self::assertNotInstanceOf(BadRequestException::class, $exception);
        self::assertNotInstanceOf(AuthenticationException::class, $exception);
    }

    public function testNetworkErrorReturnsConnectionException(): void
    {
        $exception = ExceptionClassifier::networkError('dns failed', null);
        self::assertInstanceOf(ConnectionException::class, $exception);
        self::assertSame(PoliPageException::NETWORK_ERROR, $exception->errorCode);
        self::assertNull($exception->status);
    }

    public function testTimeoutReturnsTimeoutExceptionWhichIsAConnectionException(): void
    {
        $exception = ExceptionClassifier::timeout('60s deadline exceeded');
        self::assertInstanceOf(TimeoutException::class, $exception);
        self::assertInstanceOf(ConnectionException::class, $exception);
        self::assertSame(PoliPageException::TIMEOUT, $exception->errorCode);
        self::assertNull($exception->status);
    }
}
