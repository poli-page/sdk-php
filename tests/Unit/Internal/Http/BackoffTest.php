<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Internal\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\Http\Backoff;

#[CoversClass(Backoff::class)]
final class BackoffTest extends TestCase
{
    public function testReturnsRetryAfterAsIsWhenDefinedNoJitter(): void
    {
        $callCount = 0;
        $jitterSource = static function () use (&$callCount): float {
            ++$callCount;

            return 0.0;
        };
        self::assertSame(1.0, Backoff::compute(1, 0.5, 1.0, $jitterSource));
        self::assertSame(0.25, Backoff::compute(3, 0.5, 0.25, $jitterSource));
        self::assertSame(0, $callCount, 'jitter source must not be consulted when retryAfter is set');
    }

    public function testReturnsZeroWhenRetryAfterIsZeroNotTreatedAsFalsy(): void
    {
        self::assertSame(0.0, Backoff::compute(1, 0.5, 0.0, static fn (): float => 0.0));
    }

    public function testAppliesExponentialBackoffWhenRetryAfterIsNull(): void
    {
        $jitterMinimum = static fn (): float => 0.0; // jitter factor = 0.5
        self::assertSame(0.25, Backoff::compute(1, 0.5, null, $jitterMinimum)); // 0.5 * 1 * 0.5
        self::assertSame(0.5, Backoff::compute(2, 0.5, null, $jitterMinimum));  // 0.5 * 2 * 0.5
        self::assertSame(1.0, Backoff::compute(3, 0.5, null, $jitterMinimum));  // 0.5 * 4 * 0.5
    }

    public function testAppliesMaximumJitterAtUpperBound(): void
    {
        $jitterMax = static fn (): float => 0.999; // jitter factor ≈ 1.499
        $result = Backoff::compute(1, 0.5, null, $jitterMax);
        self::assertEqualsWithDelta(0.75, $result, 0.01);
    }

    public function testJitterFactorStaysWithinHalfOpenInterval(): void
    {
        // Use the production source (mt_rand / mt_getrandmax) over 200 samples.
        $samples = [];
        for ($i = 0; $i < 200; ++$i) {
            $samples[] = Backoff::compute(1, 1.0, null);
        }
        foreach ($samples as $d) {
            self::assertGreaterThanOrEqual(0.5, $d);
            self::assertLessThanOrEqual(1.5, $d);
        }
    }

    public function testCallsJitterSourceExactlyOnceWhenRetryAfterNull(): void
    {
        $callCount = 0;
        $jitterSource = static function () use (&$callCount): float {
            ++$callCount;

            return 0.25;
        };
        Backoff::compute(2, 0.5, null, $jitterSource);
        self::assertSame(1, $callCount);
    }

    public function testDoesNotCallJitterSourceWhenRetryAfterDefined(): void
    {
        $callCount = 0;
        $jitterSource = static function () use (&$callCount): float {
            ++$callCount;

            return 0.5;
        };
        Backoff::compute(2, 0.5, 1.0, $jitterSource);
        self::assertSame(0, $callCount);
    }
}
