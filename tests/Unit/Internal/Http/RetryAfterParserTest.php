<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Internal\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\Http\RetryAfterParser;

#[CoversClass(RetryAfterParser::class)]
final class RetryAfterParserTest extends TestCase
{
    public function testReturnsNullForNull(): void
    {
        self::assertNull(RetryAfterParser::parse(null));
    }

    public function testReturnsNullForEmptyString(): void
    {
        self::assertNull(RetryAfterParser::parse(''));
    }

    public function testReturnsZeroForZeroSeconds(): void
    {
        self::assertSame(0.0, RetryAfterParser::parse('0'));
    }

    public function testReturnsFiveSecondsForFive(): void
    {
        self::assertSame(5.0, RetryAfterParser::parse('5'));
    }

    public function testCapsAtThirtySecondsForLargeValues(): void
    {
        self::assertSame(30.0, RetryAfterParser::parse('999'));
        self::assertSame(30.0, RetryAfterParser::parse('100000'));
    }

    public function testReturnsNullForNonNumericNonDateStrings(): void
    {
        self::assertNull(RetryAfterParser::parse('abc'));
        self::assertNull(RetryAfterParser::parse('not a date'));
    }

    public function testReturnsZeroForPastHttpDate(): void
    {
        $past = gmdate('D, d M Y H:i:s \G\M\T', time() - 60);
        self::assertSame(0.0, RetryAfterParser::parse($past));
    }

    public function testReturnsApproximateDeltaForFutureHttpDate(): void
    {
        $future = gmdate('D, d M Y H:i:s \G\M\T', time() + 5);
        $result = RetryAfterParser::parse($future);
        self::assertNotNull($result);
        self::assertGreaterThan(3.0, $result);
        self::assertLessThanOrEqual(5.0, $result);
    }

    public function testCapsVeryFarFutureHttpDateAtThirtySeconds(): void
    {
        $farFuture = gmdate('D, d M Y H:i:s \G\M\T', time() + 60 * 60);
        self::assertSame(30.0, RetryAfterParser::parse($farFuture));
    }
}
