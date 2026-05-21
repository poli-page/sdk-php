<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Render;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\InlineModeInput;
use PoliPage\Internal\Constants;
use PoliPage\Internal\Transport;
use PoliPage\PoliPageException;
use PoliPage\PreviewResult;
use PoliPage\ProjectModeInput;
use PoliPage\Render;
use PoliPage\RenderMetadata;

#[CoversClass(Render::class)]
#[CoversClass(ProjectModeInput::class)]
#[CoversClass(InlineModeInput::class)]
#[CoversClass(PreviewResult::class)]
final class PreviewTest extends TestCase
{
    public function testProjectModePreviewSendsExpectedWireBody(): void
    {
        $transport = new FakeTransport(['html' => '<p>x</p>', 'totalPages' => 1, 'environment' => 'sandbox']);
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

        self::assertCount(1, $transport->calls);
        $call = $transport->calls[0];
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
        $transport = new FakeTransport(['html' => '<p>x</p>', 'totalPages' => 1, 'environment' => 'sandbox']);
        $render = new Render($transport);

        $render->preview(new InlineModeInput(
            template: '<p>{{ name }}</p>',
            data: ['name' => 'Alice'],
        ));

        $call = $transport->calls[0];
        self::assertSame(
            ['template' => '<p>{{ name }}</p>', 'data' => ['name' => 'Alice']],
            $call['body'],
        );
        self::assertArrayNotHasKey('project', $call['body']);
        self::assertArrayNotHasKey('version', $call['body']);
    }

    public function testNullOptionalFieldsAreOmittedFromWireBody(): void
    {
        $transport = new FakeTransport(['html' => '', 'totalPages' => 0, 'environment' => 'sandbox']);
        $render = new Render($transport);

        $render->preview(new ProjectModeInput(
            project: 'billing',
            template: 'invoice',
            data: [],
        ));

        $call = $transport->calls[0];
        self::assertArrayNotHasKey('version', $call['body']);
        self::assertArrayNotHasKey('format', $call['body']);
        self::assertArrayNotHasKey('orientation', $call['body']);
        self::assertArrayNotHasKey('locale', $call['body']);
        self::assertArrayNotHasKey('metadata', $call['body']);
    }

    public function testIdempotencyKeyAndTimeoutAreStrippedFromWireBody(): void
    {
        $transport = new FakeTransport(['html' => '', 'totalPages' => 0, 'environment' => 'sandbox']);
        $render = new Render($transport);

        $render->preview(new ProjectModeInput(
            project: 'billing',
            template: 'invoice',
            data: [],
            idempotencyKey: 'idem-xyz',
            timeout: 5.0,
        ));

        $call = $transport->calls[0];
        self::assertArrayNotHasKey('idempotencyKey', $call['body']);
        self::assertArrayNotHasKey('timeout', $call['body']);
        // But they are propagated to the transport call:
        self::assertSame('idem-xyz', $call['idempotencyKey']);
        self::assertSame(5.0, $call['timeout']);
    }

    public function testUnexpectedResponseShapeThrowsInternalError(): void
    {
        $transport = new FakeTransport(['html' => '<p>ok</p>']); // missing totalPages, environment
        $render = new Render($transport);

        $this->expectException(PoliPageException::class);
        $this->expectExceptionMessage('Unexpected preview response shape');

        $render->preview(new InlineModeInput(template: '<p>x</p>', data: []));
    }
}

/**
 * In-test transport stub: captures every call into a typed list so assertions
 * can verify wire shape without touching PSR-18.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{path: string, body: array<string, mixed>, idempotencyKey: ?string, timeout: ?float}> */
    public array $calls = [];

    /**
     * @param array<array-key, mixed> $response
     */
    public function __construct(private readonly array $response)
    {
    }

    public function post(string $path, array $body, ?string $idempotencyKey, ?float $timeout): array
    {
        $this->calls[] = [
            'path' => $path,
            'body' => $body,
            'idempotencyKey' => $idempotencyKey,
            'timeout' => $timeout,
        ];

        return $this->response;
    }
}
