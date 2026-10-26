<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoliPage\Orientation;
use PoliPage\PageFormat;
use PoliPage\RenderInput;
use PoliPage\RenderMetadata;

#[CoversClass(RenderInput::class)]
final class RenderInputTest extends TestCase
{
    public function testFormatRoundTripsWhenProvided(): void
    {
        $input = new RenderInput(
            project: 'billing',
            template: 'invoice',
            data: [],
            format: PageFormat::A5,
        );

        self::assertSame(PageFormat::A5, $input->format);
    }

    public function testFormatIsNullWhenOmitted(): void
    {
        $input = new RenderInput(
            project: 'billing',
            template: 'invoice',
            data: [],
        );

        self::assertNull($input->format);
    }

    public function testToWireIncludesFormatAsStringValue(): void
    {
        $input = new RenderInput(
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
        $input = new RenderInput(
            project: 'billing',
            template: 'invoice',
            data: [],
        );

        self::assertArrayNotHasKey('format', $input->toWire());
    }

    public function testJsonEncodesFormatAsA5String(): void
    {
        $input = new RenderInput(
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
        $input = new RenderInput(
            project: 'billing',
            template: 'invoice',
            data: [],
        );

        $json = json_encode($input->toWire(), flags: JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('"format"', $json);
    }

    public function testOrientationRoundTripsWhenProvided(): void
    {
        $input = new RenderInput(
            project: 'billing',
            template: 'invoice',
            data: [],
            orientation: Orientation::Landscape,
        );

        self::assertSame(Orientation::Landscape, $input->orientation);
    }

    public function testToWireIncludesOrientationAsWireString(): void
    {
        $input = new RenderInput(
            project: 'billing',
            template: 'invoice',
            data: [],
            orientation: Orientation::Landscape,
        );

        $wire = $input->toWire();
        self::assertArrayHasKey('orientation', $wire);
        self::assertSame('landscape', $wire['orientation']);
    }

    public function testToWireOmitsOrientationKeyWhenNull(): void
    {
        $input = new RenderInput(
            project: 'billing',
            template: 'invoice',
            data: [],
        );

        self::assertArrayNotHasKey('orientation', $input->toWire());
    }

    public function testToWireHoldsExactlyTheSevenDocumentedFieldsWhenEverythingIsSet(): void
    {
        $input = new RenderInput(
            project: 'billing',
            template: 'invoice',
            data: ['n' => 1],
            format: PageFormat::A5,
            orientation: Orientation::Landscape,
            locale: 'fr-FR',
            metadata: new RenderMetadata(['customerId' => 'cust_1']),
            idempotencyKey: 'idem-1',
            timeout: 5.0,
        );

        self::assertSame(
            [
                'project' => 'billing',
                'template' => 'invoice',
                'data' => ['n' => 1],
                'format' => 'A5',
                'orientation' => 'landscape',
                'locale' => 'fr-FR',
                'metadata' => ['customerId' => 'cust_1'],
            ],
            $input->toWire(),
        );
    }

    public function testToWireHoldsOnlyTheRequiredFieldsWhenNoOptionalIsSet(): void
    {
        $input = new RenderInput(project: 'billing', template: 'invoice', data: []);

        self::assertSame(['project' => 'billing', 'template' => 'invoice', 'data' => []], $input->toWire());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function removedOrForeignFields(): iterable
    {
        yield 'version' => ['version'];
        yield 'environment' => ['environment'];
    }

    #[DataProvider('removedOrForeignFields')]
    public function testRejectsFieldsOutsideTheContract(string $field): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Unknown named parameter $' . $field);

        (new \ReflectionClass(RenderInput::class))->newInstanceArgs([
            'project' => 'billing',
            'template' => 'invoice',
            'data' => [],
            $field => 'x',
        ]);
    }
}
