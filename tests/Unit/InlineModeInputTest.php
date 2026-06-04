<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\InlineModeInput;
use PoliPage\Orientation;
use PoliPage\PageFormat;

#[CoversClass(InlineModeInput::class)]
final class InlineModeInputTest extends TestCase
{
    public function testFormatRoundTripsWhenProvided(): void
    {
        $input = new InlineModeInput(
            template: '<h1>Hello</h1>',
            data: [],
            format: PageFormat::A5,
        );

        self::assertSame(PageFormat::A5, $input->format);
    }

    public function testFormatIsNullWhenOmitted(): void
    {
        $input = new InlineModeInput(
            template: '<h1>Hello</h1>',
            data: [],
        );

        self::assertNull($input->format);
    }

    public function testOrientationRoundTripsWhenProvided(): void
    {
        $input = new InlineModeInput(
            template: '<h1>Hello</h1>',
            data: [],
            orientation: Orientation::Landscape,
        );

        self::assertSame(Orientation::Landscape, $input->orientation);
    }

    public function testToWireIncludesOrientationAsWireString(): void
    {
        $input = new InlineModeInput(
            template: '<h1>Hello</h1>',
            data: [],
            orientation: Orientation::Landscape,
        );

        $wire = $input->toWire();
        self::assertArrayHasKey('orientation', $wire);
        self::assertSame('landscape', $wire['orientation']);
    }

    public function testToWireOmitsOrientationKeyWhenNull(): void
    {
        $input = new InlineModeInput(
            template: '<h1>Hello</h1>',
            data: [],
        );

        self::assertArrayNotHasKey('orientation', $input->toWire());
    }
}
