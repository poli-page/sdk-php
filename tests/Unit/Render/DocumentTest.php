<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Render;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\Constants;
use PoliPage\Render;
use PoliPage\RenderInput;
use PoliPage\Tests\Support\DocumentWire;
use PoliPage\Tests\Support\FakeTransport;

#[CoversClass(Render::class)]
final class DocumentTest extends TestCase
{
    public function testDocumentPostsToRenderEndpointAndParsesDescriptor(): void
    {
        $transport = new FakeTransport();
        $transport->postResponse = DocumentWire::descriptor(['pageCount' => 2]);
        $render = new Render($transport);

        $descriptor = $render->document(new RenderInput(
            project: 'billing',
            template: 'invoice',
            data: ['n' => 1],
            idempotencyKey: 'idem-1',
        ));

        // Wire route + body assertions
        self::assertCount(1, $transport->postCalls);
        $call = $transport->postCalls[0];
        self::assertSame(Constants::PATH_RENDER, $call['path']);
        self::assertSame('idem-1', $call['idempotencyKey']);
        self::assertSame(
            ['project' => 'billing', 'template' => 'invoice', 'data' => ['n' => 1]],
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
        $transport->postResponse = DocumentWire::descriptor();
        $render = new Render($transport);

        $render->document(new RenderInput(project: 'p', template: 't', data: []));

        self::assertCount(0, $transport->fetchBytesCalls, 'document() must not chain a PDF download');
    }

    public function testReturnedDescriptorCanDownloadPdfThroughTheSameTransport(): void
    {
        $transport = new FakeTransport();
        $transport->postResponse = DocumentWire::descriptor();
        $transport->fetchBytesResponse = 'fake-pdf-bytes';
        $render = new Render($transport);

        $descriptor = $render->document(new RenderInput(project: 'p', template: 't', data: []));
        $bytes = $descriptor->downloadPdf();

        self::assertSame('fake-pdf-bytes', $bytes);
        self::assertCount(1, $transport->fetchBytesCalls);
        self::assertSame('https://s3.example.com/doc.pdf?sig=abc', $transport->fetchBytesCalls[0]['url']);
    }
}
