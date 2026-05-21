<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Render;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\ProjectModeInput;
use PoliPage\Render;
use PoliPage\Tests\Support\FakeTransport;
use Psr\Http\Message\StreamInterface;

#[CoversClass(Render::class)]
final class PdfStreamTest extends TestCase
{
    public function testPdfStreamReturnsPsr7StreamFromPresignedUrl(): void
    {
        $factory = new Psr17Factory();
        $transport = new FakeTransport();
        $transport->postResponse = self::renderWireResponse();
        $transport->streamBytesResponse = $factory->createStream("\x25PDF-1.7\nstream-bytes");
        $render = new Render($transport);

        $stream = $render->pdfStream(new ProjectModeInput(
            project: 'billing',
            template: 'invoice',
            data: ['n' => 1],
        ));

        self::assertInstanceOf(StreamInterface::class, $stream);
        self::assertSame("\x25PDF-1.7\nstream-bytes", (string) $stream);

        // Verifies the two hops + streamBytes target
        self::assertCount(1, $transport->postCalls);
        self::assertCount(1, $transport->streamBytesCalls);
        self::assertSame('https://s3.example.com/doc.pdf?sig=abc', $transport->streamBytesCalls[0]['url']);
    }

    public function testPdfStreamForwardsInputTimeoutToStreamFetch(): void
    {
        $factory = new Psr17Factory();
        $transport = new FakeTransport();
        $transport->postResponse = self::renderWireResponse();
        $transport->streamBytesResponse = $factory->createStream('bytes');
        $render = new Render($transport);

        $render->pdfStream(new ProjectModeInput(
            project: 'p',
            template: 't',
            data: [],
            timeout: 7.5,
        ));

        self::assertSame(7.5, $transport->postCalls[0]['timeout']);
        self::assertSame(7.5, $transport->streamBytesCalls[0]['timeout']);
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
