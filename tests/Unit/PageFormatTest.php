<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\PageFormat;

#[CoversClass(PageFormat::class)]
final class PageFormatTest extends TestCase
{
    public function testA4ValueIsA4String(): void
    {
        self::assertSame('A4', PageFormat::A4->value);
    }

    public function testAllTwelveCasesExist(): void
    {
        self::assertCount(12, PageFormat::cases());
    }

    public function testFromRoundTripsA4(): void
    {
        self::assertSame(PageFormat::A4, PageFormat::from('A4'));
    }

    public function testTryFromUnknownReturnsNull(): void
    {
        self::assertNull(PageFormat::tryFrom('Unknown'));
    }
}
