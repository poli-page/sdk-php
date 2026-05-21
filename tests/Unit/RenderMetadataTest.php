<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\PoliPageException;
use PoliPage\RenderMetadata;

#[CoversClass(RenderMetadata::class)]
final class RenderMetadataTest extends TestCase
{
    public function testAcceptsStringIntFloatBoolPrimitives(): void
    {
        $metadata = new RenderMetadata([
            'customerId' => 'cust_123',
            'amount' => 1280,
            'rate' => 0.07,
            'active' => true,
        ]);
        self::assertSame(
            [
                'customerId' => 'cust_123',
                'amount' => 1280,
                'rate' => 0.07,
                'active' => true,
            ],
            $metadata->toArray(),
        );
    }

    public function testAcceptsEmptyMap(): void
    {
        $metadata = new RenderMetadata([]);
        self::assertSame([], $metadata->toArray());
    }

    public function testRejectsArrayValue(): void
    {
        $this->expectException(PoliPageException::class);
        $this->expectExceptionMessage("metadata value for key 'tags' must be a primitive");

        try {
            /** @phpstan-ignore argument.type (test deliberately provides invalid input) */
            new RenderMetadata(['tags' => ['a', 'b']]);
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::INVALID_OPTIONS, $e->errorCode);

            throw $e;
        }
    }

    public function testRejectsObjectValue(): void
    {
        $this->expectException(PoliPageException::class);
        /** @phpstan-ignore argument.type (test deliberately provides invalid input) */
        new RenderMetadata(['customer' => new \stdClass()]);
    }

    public function testRejectsNullValue(): void
    {
        $this->expectException(PoliPageException::class);
        /** @phpstan-ignore argument.type (test deliberately provides invalid input) */
        new RenderMetadata(['locale' => null]);
    }
}
