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

    public function testReadsOnlyIntegerSecondsAsSeconds(): void
    {
        // SDK spec §9.2: integer seconds or an HTTP-date; anything else is unparseable.
        self::assertNull(RetryAfterParser::parse('1.5'));
        self::assertNull(RetryAfterParser::parse('-1'));
        self::assertNull(RetryAfterParser::parse('+2'));
        self::assertNull(RetryAfterParser::parse('1e3'));
        self::assertNull(RetryAfterParser::parse('0x10'));
        self::assertSame(2.0, RetryAfterParser::parse(' 2 '));
    }

    public function testHugeIntegerClampsToTheCap(): void
    {
        // Past PHP_INT_MAX: an (int) cast would saturate or wrap, never a small delay.
        self::assertSame(30.0, RetryAfterParser::parse('9223372036854775808'));
        self::assertSame(30.0, RetryAfterParser::parse(str_repeat('9', 400)));
        self::assertSame(7.0, RetryAfterParser::parse(str_repeat('0', 20) . '7'));
    }

    public function testRejectsRelativeDateWords(): void
    {
        // strtotime() would read these; an HTTP-date is one of three fixed forms.
        self::assertNull(RetryAfterParser::parse('soon'));
        self::assertNull(RetryAfterParser::parse('tomorrow'));
        self::assertNull(RetryAfterParser::parse('+1 hour'));
        self::assertNull(RetryAfterParser::parse('now'));
    }

    public function testReadsTheObsoleteHttpDateForms(): void
    {
        $future = time() + 5;
        $rfc850 = RetryAfterParser::parse(gmdate('l, d-M-y H:i:s \G\M\T', $future));
        $asctime = RetryAfterParser::parse(
            gmdate('D M ', $future) . str_pad(gmdate('j', $future), 2, ' ', STR_PAD_LEFT) . gmdate(' H:i:s Y', $future),
        );
        self::assertNotNull($rfc850);
        self::assertNotNull($asctime);
        self::assertGreaterThan(3.0, $rfc850);
        self::assertGreaterThan(3.0, $asctime);
    }

    public function testFutureHttpDateKeepsSubSecondPrecision(): void
    {
        // The delay is the time left until the date, not a whole number of seconds.
        $at = (int) ceil(microtime(true)) + 3;
        $result = RetryAfterParser::parse(gmdate('D, d M Y H:i:s \G\M\T', $at));
        self::assertNotNull($result);
        self::assertEqualsWithDelta($at - microtime(true), $result, 0.05);
    }
}
