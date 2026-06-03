<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Internal\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\Http\ErrorBodyParser;

#[CoversClass(ErrorBodyParser::class)]
final class ErrorBodyParserTest extends TestCase
{
    public function testExtractsCodeAndMessageFromCompleteJsonBody(): void
    {
        $result = ErrorBodyParser::parse(
            '{"code":"VALIDATION_ERROR","message":"data is required"}',
            400,
        );
        self::assertSame(['code' => 'VALIDATION_ERROR', 'message' => 'data is required'], $result);
    }

    public function testCodeStaysUnknownWhenOnlyMessagePresent(): void
    {
        $result = ErrorBodyParser::parse('{"message":"something broke"}', 400);
        self::assertSame(['code' => 'unknown_error', 'message' => 'something broke'], $result);
    }

    public function testFallsBackToErrorFieldAsCode(): void
    {
        $result = ErrorBodyParser::parse('{"error":"oops"}', 400);
        self::assertSame(['code' => 'oops', 'message' => 'HTTP 400'], $result);
    }

    public function testReturnsUnknownErrorCodeWhenJsonHasNoRecognisedFields(): void
    {
        $result = ErrorBodyParser::parse('{}', 400);
        self::assertSame(
            ['code' => 'unknown_error', 'message' => 'HTTP 400'],
            $result,
        );
    }

    public function testReturnsInternalErrorWhenBodyIsNotValidJson(): void
    {
        $result = ErrorBodyParser::parse('not json', 502);
        self::assertSame(
            ['code' => 'INTERNAL_ERROR', 'message' => 'HTTP 502: response body was not valid JSON'],
            $result,
        );
    }

    public function testUsesRfc7807DetailAsMessage(): void
    {
        $result = ErrorBodyParser::parse(
            '{"code":"authentication_failed","detail":"Forbidden","title":"Authentication failed"}',
            401,
        );
        self::assertSame(['code' => 'authentication_failed', 'message' => 'Forbidden'], $result);
    }

    public function testFallsBackToTitleWhenDetailAbsent(): void
    {
        $result = ErrorBodyParser::parse('{"code":"forbidden","title":"Access denied"}', 403);
        self::assertSame(['code' => 'forbidden', 'message' => 'Access denied'], $result);
    }

    public function testDoesNotSynthesiseApiErrorPrefix(): void
    {
        $result = ErrorBodyParser::parse('{"code":"THUMBNAILS_NOT_AVAILABLE"}', 403);
        self::assertSame(['code' => 'THUMBNAILS_NOT_AVAILABLE', 'message' => 'HTTP 403'], $result);
        self::assertStringNotContainsString('API error', $result['message']);
    }

    public function testReturnsInternalErrorForHtmlErrorPages(): void
    {
        $result = ErrorBodyParser::parse('<html>upstream gone</html>', 502);
        self::assertSame('INTERNAL_ERROR', $result['code']);
        self::assertStringContainsString('502', $result['message']);
    }

    public function testReturnsInternalErrorForEmptyBody(): void
    {
        $result = ErrorBodyParser::parse('', 500);
        self::assertSame('INTERNAL_ERROR', $result['code']);
    }

    public function testReturnsInternalErrorWhenJsonIsScalarNotObject(): void
    {
        // Edge case: valid JSON but not an object (e.g. "true", "42", '"hello"').
        $result = ErrorBodyParser::parse('42', 500);
        self::assertSame('INTERNAL_ERROR', $result['code']);
    }
}
