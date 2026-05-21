<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Render;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\DocumentDescriptor;
use PoliPage\Internal\Constants;
use PoliPage\ProjectModeInput;
use PoliPage\Render;
use PoliPage\Tests\Support\FakeTransport;

#[CoversClass(Render::class)]
#[CoversClass(DocumentDescriptor::class)]
final class DocumentTest extends TestCase
{
    public function testDocumentPostsToRenderEndpointAndParsesDescriptor(): void
    {
        $transport = new FakeTransport();
        $transport->postResponse = self::renderWireResponse();
        $render = new Render($transport);

        $descriptor = $render->document(new ProjectModeInput(
            project: 'billing',
            template: 'invoice',
            data: ['n' => 1],
            version: '1.0.0',
            idempotencyKey: 'idem-1',
        ));

        // Wire route + body assertions
        self::assertCount(1, $transport->postCalls);
        $call = $transport->postCalls[0];
        self::assertSame(Constants::PATH_RENDER, $call['path']);
        self::assertSame('idem-1', $call['idempotencyKey']);
        self::assertSame(
            ['project' => 'billing', 'template' => 'invoice', 'data' => ['n' => 1], 'version' => '1.0.0'],
            $call['body'],
        );

        // Returned descriptor mirrors wire shape
        self::assertSame('doc_abc', $descriptor->documentId);
        self::assertSame('A4', $descriptor->format);
        self::assertSame(2, $descriptor->pageCount);
    }

    public function testDocumentDoesNotDownloadPdfImmediately(): void
    {
        $transport = new FakeTransport();
        $transport->postResponse = self::renderWireResponse();
        $render = new Render($transport);

        $render->document(new ProjectModeInput(project: 'p', template: 't', data: []));

        self::assertCount(0, $transport->fetchBytesCalls, 'document() must not chain a PDF download');
    }

    public function testReturnedDescriptorCanDownloadPdfThroughTheSameTransport(): void
    {
        $transport = new FakeTransport();
        $transport->postResponse = self::renderWireResponse();
        $transport->fetchBytesResponse = 'fake-pdf-bytes';
        $render = new Render($transport);

        $descriptor = $render->document(new ProjectModeInput(project: 'p', template: 't', data: []));
        $bytes = $descriptor->downloadPdf();

        self::assertSame('fake-pdf-bytes', $bytes);
        self::assertCount(1, $transport->fetchBytesCalls);
        self::assertSame('https://s3.example.com/doc.pdf?sig=abc', $transport->fetchBytesCalls[0]['url']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function renderWireResponse(): array
    {
        return [
            'documentId' => 'doc_abc',
            'organizationId' => 'org_xyz',
            'projectId' => null,
            'projectSlug' => 'billing',
            'templateId' => null,
            'templateSlug' => 'invoice',
            'version' => '1.0.0',
            'environment' => 'sandbox',
            'apiKeyId' => null,
            'format' => 'A4',
            'orientation' => null,
            'locale' => null,
            'pageCount' => 2,
            'sizeBytes' => 1024,
            'createdAt' => '2026-05-21T12:00:00Z',
            'metadata' => [],
            'presignedPdfUrl' => 'https://s3.example.com/doc.pdf?sig=abc',
            'expiresAt' => '2026-05-21T12:15:00Z',
        ];
    }
}
