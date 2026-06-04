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
}
