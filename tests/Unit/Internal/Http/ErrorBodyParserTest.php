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

    public function testFallsBackToMessageAsCodeWhenCodeAbsent(): void
    {
        $result = ErrorBodyParser::parse('{"message":"something broke"}', 400);
        self::assertSame(['code' => 'something broke', 'message' => 'something broke'], $result);
    }

    public function testFallsBackToErrorFieldAsCodeWhenCodeAndMessageAbsent(): void
    {
        $result = ErrorBodyParser::parse('{"error":"oops"}', 400);
        self::assertSame(['code' => 'oops', 'message' => 'API error (400): oops'], $result);
    }

    public function testReturnsUnknownErrorCodeWhenJsonHasNoRecognisedFields(): void
    {
        $result = ErrorBodyParser::parse('{}', 400);
        self::assertSame(
            ['code' => 'unknown_error', 'message' => 'API error (400): unknown_error'],
            $result,
        );
    }

    public function testReturnsInternalErrorWhenBodyIsNotValidJson(): void
    {
        $result = ErrorBodyParser::parse('not json', 502);
        self::assertSame(
            ['code' => 'INTERNAL_ERROR', 'message' => 'API error 502: response body was not valid JSON'],
            $result,
        );
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
