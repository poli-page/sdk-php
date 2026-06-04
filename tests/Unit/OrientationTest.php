<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Orientation;

#[CoversClass(Orientation::class)]
final class OrientationTest extends TestCase
{
    public function testPortraitValueIsPortraitString(): void
    {
        self::assertSame('portrait', Orientation::Portrait->value);
    }

    public function testLandscapeValueIsLandscapeString(): void
    {
        self::assertSame('landscape', Orientation::Landscape->value);
    }

    public function testExactlyTwoCasesExist(): void
    {
        self::assertCount(2, Orientation::cases());
    }

    public function testFromRoundTripsPortrait(): void
    {
        self::assertSame(Orientation::Portrait, Orientation::from('portrait'));
    }

    public function testTryFromUnknownReturnsNull(): void
    {
        self::assertNull(Orientation::tryFrom('Unknown'));
    }
}
