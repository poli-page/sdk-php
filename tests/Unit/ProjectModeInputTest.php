<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\PageFormat;
use PoliPage\ProjectModeInput;

#[CoversClass(ProjectModeInput::class)]
final class ProjectModeInputTest extends TestCase
{
    public function testFormatRoundTripsWhenProvided(): void
    {
        $input = new ProjectModeInput(
            project: 'billing',
            template: 'invoice',
            data: [],
            format: PageFormat::A5,
        );

        self::assertSame(PageFormat::A5, $input->format);
    }

    public function testFormatIsNullWhenOmitted(): void
    {
        $input = new ProjectModeInput(
            project: 'billing',
            template: 'invoice',
            data: [],
        );

        self::assertNull($input->format);
    }

    public function testToWireIncludesFormatAsStringValue(): void
    {
        $input = new ProjectModeInput(
            project: 'billing',
            template: 'invoice',
            data: [],
            format: PageFormat::A5,
        );

        $wire = $input->toWire();
        self::assertArrayHasKey('format', $wire);
        self::assertSame('A5', $wire['format']);
    }

    public function testToWireOmitsFormatKeyWhenNull(): void
    {
        $input = new ProjectModeInput(
            project: 'billing',
            template: 'invoice',
            data: [],
        );

        self::assertArrayNotHasKey('format', $input->toWire());
    }

    public function testJsonEncodesFormatAsA5String(): void
    {
        $input = new ProjectModeInput(
            project: 'billing',
            template: 'invoice',
            data: [],
            format: PageFormat::A5,
        );

        $json = json_encode($input->toWire(), flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"format":"A5"', $json);
    }

    public function testJsonEncodingOmitsFormatKeyWhenNull(): void
    {
        $input = new ProjectModeInput(
            project: 'billing',
            template: 'invoice',
            data: [],
        );

        $json = json_encode($input->toWire(), flags: JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('"format"', $json);
    }
}
