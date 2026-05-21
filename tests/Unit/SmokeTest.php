<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\Version;
use PoliPage\PoliPage;

#[CoversNothing]
final class SmokeTest extends TestCase
{
    public function testClientClassAutoloads(): void
    {
        self::assertTrue(class_exists(PoliPage::class));
    }

    public function testVersionConstantIsDevPlaceholder(): void
    {
        self::assertSame('0.0.0-dev', Version::VERSION);
    }
}
