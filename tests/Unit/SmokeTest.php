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

    public function testVersionConstantMatchesSemverShape(): void
    {
        // The actual value is bumped by the release flow; this test only
        // guarantees the constant exists, is a non-empty string, and looks
        // like a SemVer 2.0.0 tag (optional `-prerelease` suffix).
        self::assertNotEmpty(Version::VERSION);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[A-Za-z0-9.\-]+)?$/', Version::VERSION);
    }
}
