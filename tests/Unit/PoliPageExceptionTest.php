<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

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
use PoliPage\PoliPageException;

#[CoversClass(PoliPageException::class)]
final class PoliPageExceptionTest extends TestCase
{
    public function testBaseExtendsRuntimeException(): void
    {
        $exception = new PoliPageException('msg', PoliPageException::INVALID_OPTIONS);
        self::assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testStoresMessageErrorCodeStatusRequestId(): void
    {
        $exception = new PoliPageException('msg', 'VALIDATION_ERROR', 400, 'req_123');
        self::assertSame('msg', $exception->getMessage());
        self::assertSame('VALIDATION_ERROR', $exception->errorCode);
        self::assertSame(400, $exception->status);
        self::assertSame('req_123', $exception->requestId);
    }

    public function testStatusAndRequestIdDefaultToNull(): void
    {
        $exception = new PoliPageException('msg', PoliPageException::NETWORK_ERROR);
        self::assertNull($exception->status);
        self::assertNull($exception->requestId);
    }

    public function testParentCodeIsZero(): void
    {
        // PHP's \Exception::$code is reserved for ints; we keep it at 0 and expose errorCode separately.
        $exception = new PoliPageException('msg', 'VALIDATION_ERROR');
        self::assertSame(0, $exception->getCode());
    }

    public function testIsAuthErrorTrueFor401(): void
    {
        $exception = new AuthenticationException('msg', 'INVALID_API_KEY', 401);
        self::assertTrue($exception->isAuthError());
    }

    public function testIsAuthErrorTrueFor403(): void
    {
        $exception = new PermissionDeniedException('msg', 'FORBIDDEN', 403);
        self::assertTrue($exception->isAuthError());
    }

    public function testIsAuthErrorFalseFor400And429(): void
    {
        self::assertFalse((new BadRequestException('msg', 'VALIDATION_ERROR', 400))->isAuthError());
        self::assertFalse((new RateLimitException('msg', 'QUOTA_EXCEEDED', 429))->isAuthError());
    }

    public function testIsRateLimitErrorTrueOnlyForRateLimitException(): void
    {
        self::assertTrue((new RateLimitException('msg', 'QUOTA_EXCEEDED', 429))->isRateLimitError());
        self::assertFalse((new BadRequestException('msg', 'VALIDATION_ERROR', 400))->isRateLimitError());
    }

    public function testIsValidationErrorTrueOnlyForBadRequestException(): void
    {
        self::assertTrue((new BadRequestException('msg', 'VALIDATION_ERROR', 400))->isValidationError());
        self::assertFalse((new AuthenticationException('msg', 'INVALID_API_KEY', 401))->isValidationError());
    }

    public function testIsNetworkErrorTrueForConnectionException(): void
    {
        $exception = new ConnectionException('boom', PoliPageException::NETWORK_ERROR);
        self::assertTrue($exception->isNetworkError());
    }

    public function testIsNetworkErrorTrueForTimeoutException(): void
    {
        $exception = new TimeoutException('boom', PoliPageException::TIMEOUT);
        self::assertTrue($exception->isNetworkError());
    }

    public function testIsNetworkErrorFalseForApiStatusException(): void
    {
        $exception = new BadRequestException('msg', 'VALIDATION_ERROR', 400);
        self::assertFalse($exception->isNetworkError());
    }

    public function testIsRetryableNetworkErrors(): void
    {
        self::assertTrue((new ConnectionException('msg', PoliPageException::NETWORK_ERROR))->isRetryable());
        self::assertTrue((new TimeoutException('msg', PoliPageException::TIMEOUT))->isRetryable());
    }

    public function testIsRetryableTrueFor5xxAnd429(): void
    {
        self::assertTrue((new InternalServerException('msg', 'INTERNAL_ERROR', 500))->isRetryable());
        self::assertTrue((new InternalServerException('msg', 'INTERNAL_ERROR', 503))->isRetryable());
        self::assertTrue((new RateLimitException('msg', 'QUOTA_EXCEEDED', 429))->isRetryable());
    }

    public function testIsRetryableFalseFor4xxOtherThan429(): void
    {
        self::assertFalse((new BadRequestException('msg', 'VALIDATION_ERROR', 400))->isRetryable());
        self::assertFalse((new AuthenticationException('msg', 'INVALID_API_KEY', 401))->isRetryable());
        self::assertFalse((new PermissionDeniedException('msg', 'FORBIDDEN', 403))->isRetryable());
        self::assertFalse((new NotFoundException('msg', 'NOT_FOUND', 404))->isRetryable());
        self::assertFalse((new GoneException('msg', 'GONE', 410))->isRetryable());
    }

    public function testIsRetryableFalseForAborted(): void
    {
        $exception = new PoliPageException('aborted', PoliPageException::ABORTED);
        self::assertFalse($exception->isRetryable());
    }

    public function testTimeoutExtendsConnectionException(): void
    {
        $exception = new TimeoutException('boom', PoliPageException::TIMEOUT);
        self::assertInstanceOf(ConnectionException::class, $exception);
        self::assertInstanceOf(PoliPageException::class, $exception);
    }

    public function testApiStatusSubclassesExtendApiStatus(): void
    {
        foreach (
            [
                new BadRequestException('msg', 'VALIDATION_ERROR', 400),
                new AuthenticationException('msg', 'INVALID_API_KEY', 401),
                new PermissionDeniedException('msg', 'FORBIDDEN', 403),
                new NotFoundException('msg', 'NOT_FOUND', 404),
                new GoneException('msg', 'GONE', 410),
                new RateLimitException('msg', 'QUOTA_EXCEEDED', 429),
                new InternalServerException('msg', 'INTERNAL_ERROR', 500),
            ] as $exception
        ) {
            self::assertInstanceOf(ApiStatusException::class, $exception);
            self::assertInstanceOf(PoliPageException::class, $exception);
        }
    }

    public function testToPayloadUsesApiStatusForStatusBearingExceptions(): void
    {
        $err = new AuthenticationException('Forbidden', 'authentication_failed', 401, 'req_abc');
        self::assertSame(
            ['code' => 'authentication_failed', 'message' => 'Forbidden', 'status' => 401, 'requestId' => 'req_abc'],
            $err->toPayload(),
        );
    }

    public function testToPayloadUses503ForConnectionException(): void
    {
        $err = new ConnectionException('dns failure', PoliPageException::NETWORK_ERROR);
        self::assertSame(503, $err->toPayload()['status']);
        self::assertNull($err->status, 'status attribute stays null for transport errors');
    }

    public function testToPayloadUses504ForTimeoutException(): void
    {
        $err = new \PoliPage\Exception\TimeoutException('slow', PoliPageException::TIMEOUT);
        self::assertSame(504, $err->toPayload()['status']);
        self::assertNull($err->status);
    }
}
