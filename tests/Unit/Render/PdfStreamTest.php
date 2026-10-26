<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Render;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Render;
use PoliPage\RenderInput;
use PoliPage\Tests\Support\DocumentWire;
use PoliPage\Tests\Support\FakeTransport;
use Psr\Http\Message\StreamInterface;

#[CoversClass(Render::class)]
final class PdfStreamTest extends TestCase
{
    public function testPdfStreamReturnsPsr7StreamFromPresignedUrl(): void
    {
        $factory = new Psr17Factory();
        $transport = new FakeTransport();
        $transport->postResponse = DocumentWire::descriptor();
        $transport->streamBytesResponse = $factory->createStream("\x25PDF-1.7\nstream-bytes");
        $render = new Render($transport);

        $stream = $render->pdfStream(new RenderInput(
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
        $transport->postResponse = DocumentWire::descriptor();
        $transport->streamBytesResponse = $factory->createStream('bytes');
        $render = new Render($transport);

        $render->pdfStream(new RenderInput(
            project: 'p',
            template: 't',
            data: [],
            timeout: 7.5,
        ));

        self::assertSame(7.5, $transport->postCalls[0]['timeout']);
        self::assertSame(7.5, $transport->streamBytesCalls[0]['timeout']);
    }
}
