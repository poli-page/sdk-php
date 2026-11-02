<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use GuzzleHttp\Client as GuzzleClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\Http\DownloadStream;
use PoliPage\PoliPage;
use PoliPage\RenderInput;
use PoliPage\Tests\Support\DocumentWire;
use PoliPage\Tests\Support\TrickleServer;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * A complete `render->pdfStream()` body passes the length check whatever mix
 * of reads, seeks and casts the caller makes, through each HTTP client (Guzzle
 * streams a non-seekable body, Symfony HttpClient a seekable one).
 */
#[CoversClass(DownloadStream::class)]
final class CompleteDownloadTest extends TestCase
{
    private const BODY = '%PDF-1.7 a complete body';

    private ?TrickleServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function httpClients(): iterable
    {
        yield 'guzzle' => ['guzzle'];
        yield 'symfony' => ['symfony'];
    }

    #[DataProvider('httpClients')]
    public function testAPartialReadThenACast(string $httpClient): void
    {
        $stream = $this->pdfStream($httpClient);

        self::assertSame('%PDF-', $stream->read(5));
        $rest = (string) $stream;

        self::assertSame($stream->isSeekable() ? self::BODY : substr(self::BODY, 5), $rest);
        self::assertTrue($stream->eof());
    }

    #[DataProvider('httpClients')]
    public function testASeekForwardThenGetContents(string $httpClient): void
    {
        $stream = $this->pdfStream($httpClient);
        if (!$stream->isSeekable()) {
            self::markTestSkipped($httpClient . ' streams a non-seekable body');
        }

        $stream->seek(5);

        self::assertSame(substr(self::BODY, 5), $stream->getContents());
        self::assertTrue($stream->eof());
    }

    #[DataProvider('httpClients')]
    public function testARewindAfterAPartialRead(string $httpClient): void
    {
        $stream = $this->pdfStream($httpClient);
        if (!$stream->isSeekable()) {
            self::markTestSkipped($httpClient . ' streams a non-seekable body');
        }

        $stream->read(5);
        $stream->rewind();

        self::assertSame(self::BODY, $stream->getContents());
        self::assertTrue($stream->eof());
    }

    private function pdfStream(string $httpClient): StreamInterface
    {
        $this->server = new TrickleServer([
            TrickleServer::json(DocumentWire::descriptorJson(['presignedPdfUrl' => '{{origin}}/doc.pdf'])),
            TrickleServer::pdf(0, [[0, self::BODY]]),
        ]);
        $client = new PoliPage(
            apiKey: 'pp_sandbox_x',
            baseUrl: $this->server->origin,
            timeout: 5.0,
            httpClient: self::httpClient($httpClient),
        );

        return $client->render->pdfStream(new RenderInput(project: 'billing', template: 'invoice', data: []));
    }

    private static function httpClient(string $name): ClientInterface
    {
        return $name === 'guzzle' ? new GuzzleClient() : new Psr18Client();
    }
}
