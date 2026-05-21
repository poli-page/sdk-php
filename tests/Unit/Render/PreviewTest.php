<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Render;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\InlineModeInput;
use PoliPage\Internal\Constants;
use PoliPage\PoliPageException;
use PoliPage\PreviewResult;
use PoliPage\ProjectModeInput;
use PoliPage\Render;
use PoliPage\RenderMetadata;
use PoliPage\Tests\Support\FakeTransport;

#[CoversClass(Render::class)]
#[CoversClass(ProjectModeInput::class)]
#[CoversClass(InlineModeInput::class)]
#[CoversClass(PreviewResult::class)]
final class PreviewTest extends TestCase
{
    public function testProjectModePreviewSendsExpectedWireBody(): void
    {
        $transport = new FakeTransport();
        $transport->postResponse = ['html' => '<p>x</p>', 'totalPages' => 1, 'environment' => 'sandbox'];
        $render = new Render($transport);

        $result = $render->preview(new ProjectModeInput(
            project: 'billing',
            template: 'invoice',
            data: ['invoiceNumber' => 'INV-001'],
            version: '1.0.0',
            metadata: new RenderMetadata(['customerId' => 'cust_123']),
            idempotencyKey: 'idem-abc',
        ));

        self::assertSame('<p>x</p>', $result->html);
        self::assertSame(1, $result->totalPages);
        self::assertSame('sandbox', $result->environment);

        self::assertCount(1, $transport->postCalls);
        $call = $transport->postCalls[0];
        self::assertSame(Constants::PATH_RENDER_PREVIEW, $call['path']);
        self::assertSame('idem-abc', $call['idempotencyKey']);
        self::assertSame(
            [
                'project' => 'billing',
                'template' => 'invoice',
                'data' => ['invoiceNumber' => 'INV-001'],
                'version' => '1.0.0',
                'metadata' => ['customerId' => 'cust_123'],
            ],
            $call['body'],
        );
    }

    public function testInlineModePreviewSendsTemplateAsHtml(): void
    {
        $transport = new FakeTransport();
        $transport->postResponse = ['html' => '<p>x</p>', 'totalPages' => 1, 'environment' => 'sandbox'];
        $render = new Render($transport);

        $render->preview(new InlineModeInput(
            template: '<p>{{ name }}</p>',
            data: ['name' => 'Alice'],
        ));

        $call = $transport->postCalls[0];
        self::assertSame(
            ['template' => '<p>{{ name }}</p>', 'data' => ['name' => 'Alice']],
            $call['body'],
        );
        self::assertArrayNotHasKey('project', $call['body']);
        self::assertArrayNotHasKey('version', $call['body']);
    }

    public function testNullOptionalFieldsAreOmittedFromWireBody(): void
    {
        $transport = new FakeTransport();
        $transport->postResponse = ['html' => '', 'totalPages' => 0, 'environment' => 'sandbox'];
        $render = new Render($transport);

        $render->preview(new ProjectModeInput(
            project: 'billing',
            template: 'invoice',
            data: [],
        ));

        $call = $transport->postCalls[0];
        self::assertArrayNotHasKey('version', $call['body']);
        self::assertArrayNotHasKey('format', $call['body']);
        self::assertArrayNotHasKey('orientation', $call['body']);
        self::assertArrayNotHasKey('locale', $call['body']);
        self::assertArrayNotHasKey('metadata', $call['body']);
    }

    public function testIdempotencyKeyAndTimeoutAreStrippedFromWireBody(): void
    {
        $transport = new FakeTransport();
        $transport->postResponse = ['html' => '', 'totalPages' => 0, 'environment' => 'sandbox'];
        $render = new Render($transport);

        $render->preview(new ProjectModeInput(
            project: 'billing',
            template: 'invoice',
            data: [],
            idempotencyKey: 'idem-xyz',
            timeout: 5.0,
        ));

        $call = $transport->postCalls[0];
        self::assertArrayNotHasKey('idempotencyKey', $call['body']);
        self::assertArrayNotHasKey('timeout', $call['body']);
        // But they are propagated to the transport call:
        self::assertSame('idem-xyz', $call['idempotencyKey']);
        self::assertSame(5.0, $call['timeout']);
    }

    public function testUnexpectedResponseShapeThrowsInternalError(): void
    {
        $transport = new FakeTransport();
        $transport->postResponse = ['html' => '<p>ok</p>']; // missing totalPages, environment
        $render = new Render($transport);

        $this->expectException(PoliPageException::class);
        $this->expectExceptionMessage('Unexpected preview response shape');

        $render->preview(new InlineModeInput(template: '<p>x</p>', data: []));
    }
}
