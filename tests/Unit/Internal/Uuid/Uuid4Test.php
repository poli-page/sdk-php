<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Internal\Uuid;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\Uuid\Uuid4;

#[CoversClass(Uuid4::class)]
final class Uuid4Test extends TestCase
{
    public function testMatchesRfc4122V4Pattern(): void
    {
        $uuid = Uuid4::generate();
        // 8-4-4-4-12 hex with version "4" nibble and variant 8/9/a/b on the clock_seq_hi.
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
    }

    public function testGeneratesUniqueIdsAcrossManySamples(): void
    {
        $samples = [];
        for ($i = 0; $i < 1000; ++$i) {
            $samples[Uuid4::generate()] = true;
        }
        self::assertCount(1000, $samples, 'collision detected in 1000 UUID4 samples');
    }
}
