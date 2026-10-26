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
final class PdfTest extends TestCase
{
    public function testPdfPerformsRenderThenDownloadAndReturnsBytes(): void
    {
        $transport = new FakeTransport();
        $transport->postResponse = DocumentWire::descriptor();
        $transport->fetchBytesResponse = "\x25PDF-1.7\nfake";
        $render = new Render($transport);

        $bytes = $render->pdf(new RenderInput(
            project: 'billing',
            template: 'invoice',
            data: ['n' => 1],
        ));

        self::assertSame("\x25PDF-1.7\nfake", $bytes);

        // Hop 1: POST /v1/render
        self::assertCount(1, $transport->postCalls);
        self::assertSame(Constants::PATH_RENDER, $transport->postCalls[0]['path']);

        // Hop 2: GET presignedPdfUrl
        self::assertCount(1, $transport->fetchBytesCalls);
        self::assertSame('https://s3.example.com/doc.pdf?sig=abc', $transport->fetchBytesCalls[0]['url']);
    }

    public function testPdfPropagatesIdempotencyKeyToTheRenderHopOnly(): void
    {
        $transport = new FakeTransport();
        $transport->postResponse = DocumentWire::descriptor();
        $transport->fetchBytesResponse = 'bytes';
        $render = new Render($transport);

        $render->pdf(new RenderInput(
            project: 'p',
            template: 't',
            data: [],
            idempotencyKey: 'idem-pdf',
        ));

        self::assertSame('idem-pdf', $transport->postCalls[0]['idempotencyKey']);
        // fetchBytes call carries no idempotency surface — confirms by not blowing up; presence verified above.
        self::assertNull($transport->fetchBytesCalls[0]['timeout']);
    }
}
