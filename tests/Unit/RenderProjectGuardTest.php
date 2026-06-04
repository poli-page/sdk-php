<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\PoliPageException;
use PoliPage\ProjectModeInput;
use PoliPage\Render;
use PoliPage\Tests\Support\FakeTransport;

/**
 * Verifies that `render.document`, `render.pdf`, and `render.pdfStream`
 * all fail fast with PROJECT_REQUIRED_FOR_DOCUMENT when `project` is empty,
 * without ever touching the HTTP transport.
 */
#[CoversClass(Render::class)]
final class RenderProjectGuardTest extends TestCase
{
    public function testDocumentThrowsProjectRequiredForDocumentWhenProjectIsEmpty(): void
    {
        $transport = new FakeTransport();
        $render = new Render($transport);

        try {
            $render->document(new ProjectModeInput(project: '', template: 't', data: []));
            self::fail('Expected PoliPageException to be thrown');
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::PROJECT_REQUIRED_FOR_DOCUMENT, $e->errorCode);
        }

        // Transport must never be invoked
        self::assertCount(0, $transport->postCalls, 'HTTP transport must not be called when project is empty');
    }

    public function testPdfThrowsProjectRequiredForDocumentWhenProjectIsEmpty(): void
    {
        $transport = new FakeTransport();
        $render = new Render($transport);

        try {
            $render->pdf(new ProjectModeInput(project: '', template: 't', data: []));
            self::fail('Expected PoliPageException to be thrown');
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::PROJECT_REQUIRED_FOR_DOCUMENT, $e->errorCode);
        }

        // Transport must never be invoked (pdf delegates to document)
        self::assertCount(0, $transport->postCalls, 'HTTP transport must not be called when project is empty');
        self::assertCount(0, $transport->fetchBytesCalls, 'HTTP transport must not be called when project is empty');
    }

    public function testPdfStreamThrowsProjectRequiredForDocumentWhenProjectIsEmpty(): void
    {
        $transport = new FakeTransport();
        $render = new Render($transport);

        try {
            $render->pdfStream(new ProjectModeInput(project: '', template: 't', data: []));
            self::fail('Expected PoliPageException to be thrown');
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::PROJECT_REQUIRED_FOR_DOCUMENT, $e->errorCode);
        }

        // Transport must never be invoked (pdfStream delegates to document)
        self::assertCount(0, $transport->postCalls, 'HTTP transport must not be called when project is empty');
        self::assertCount(0, $transport->streamBytesCalls, 'HTTP transport must not be called when project is empty');
    }
}
