<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\InlineModeInput;
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
}
