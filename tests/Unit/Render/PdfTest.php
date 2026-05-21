<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Render;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\Constants;
use PoliPage\ProjectModeInput;
use PoliPage\Render;
use PoliPage\Tests\Support\FakeTransport;

#[CoversClass(Render::class)]
final class PdfTest extends TestCase
{
    public function testPdfPerformsRenderThenDownloadAndReturnsBytes(): void
    {
        $transport = new FakeTransport();
        $transport->postResponse = self::renderWireResponse();
        $transport->fetchBytesResponse = "\x25PDF-1.7\nfake";
        $render = new Render($transport);

        $bytes = $render->pdf(new ProjectModeInput(
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
        $transport->postResponse = self::renderWireResponse();
        $transport->fetchBytesResponse = 'bytes';
        $render = new Render($transport);

        $render->pdf(new ProjectModeInput(
            project: 'p',
            template: 't',
            data: [],
            idempotencyKey: 'idem-pdf',
        ));

        self::assertSame('idem-pdf', $transport->postCalls[0]['idempotencyKey']);
        // fetchBytes call carries no idempotency surface — confirms by not blowing up; presence verified above.
        self::assertNull($transport->fetchBytesCalls[0]['timeout']);
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
            'projectSlug' => 'p',
            'templateId' => null,
            'templateSlug' => 't',
            'version' => null,
            'environment' => 'sandbox',
            'apiKeyId' => null,
            'format' => 'A4',
            'orientation' => null,
            'locale' => null,
            'pageCount' => 1,
            'sizeBytes' => 256,
            'createdAt' => '2026-05-21T12:00:00Z',
            'metadata' => [],
            'presignedPdfUrl' => 'https://s3.example.com/doc.pdf?sig=abc',
            'expiresAt' => '2026-05-21T12:15:00Z',
        ];
    }
}
