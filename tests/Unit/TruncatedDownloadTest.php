<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use GuzzleHttp\Client as GuzzleClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\Http\DownloadStream;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\RenderInput;

use function PoliPage\renderToFile;

use PoliPage\Tests\Support\DocumentWire;
use PoliPage\Tests\Support\TrickleServer;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * A download whose connection closes before the body's announced
 * `Content-Length` is DOWNLOAD_FAILED with the network message, through every
 * HTTP client: never a shorter PDF. Guzzle 7's stream handler, used by
 * `render->pdfStream()`, reports such a close as a plain end of stream, so the
 * SDK counts the bytes itself. `renderToFile()` then leaves the target as it
 * was.
 */
#[CoversClass(DownloadStream::class)]
final class TruncatedDownloadTest extends TestCase
{
    private const ANNOUNCED = 1000;

    private const SENT = '%PDF-1.7 only twenty';

    private ?TrickleServer $server = null;

    private string $dir = '';

    protected function tearDown(): void
    {
        $this->server?->stop();
        if ($this->dir !== '') {
            foreach (array_diff(self::scan($this->dir), ['.', '..']) as $entry) {
                @unlink($this->dir . '/' . $entry);
            }
            @rmdir($this->dir);
        }
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
    public function testRenderPdfFailsOnATruncatedBody(string $httpClient): void
    {
        $client = $this->client($httpClient);

        self::assertNetworkFailure(fn () => $client->render->pdf(self::input()));
    }

    #[DataProvider('httpClients')]
    public function testPdfStreamFailsOnATruncatedBody(string $httpClient): void
    {
        $client = $this->client($httpClient);

        self::assertNetworkFailure(static function () use ($client): void {
            $stream = $client->render->pdfStream(self::input());
            while (!$stream->eof()) {
                $stream->read(8192);
            }
        });
    }

    #[DataProvider('httpClients')]
    public function testPdfStreamGetContentsFailsOnATruncatedBody(string $httpClient): void
    {
        $client = $this->client($httpClient);

        self::assertNetworkFailure(static fn () => $client->render->pdfStream(self::input())->getContents());
    }

    #[DataProvider('httpClients')]
    public function testRenderToFileLeavesTheTargetUntouchedOnATruncatedBody(string $httpClient): void
    {
        $this->dir = sys_get_temp_dir() . '/sdk-php-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $path = $this->dir . '/invoice.pdf';
        file_put_contents($path, 'the good PDF');
        $client = $this->client($httpClient);

        self::assertNetworkFailure(static fn () => renderToFile($client, self::input(), $path));

        self::assertSame('the good PDF', file_get_contents($path));
        self::assertSame(['invoice.pdf'], array_values(array_diff(self::scan($this->dir), ['.', '..'])), 'no temporary file left');
    }

    private function client(string $httpClient): PoliPage
    {
        $this->server = new TrickleServer([
            TrickleServer::json(DocumentWire::descriptorJson(['presignedPdfUrl' => '{{origin}}/doc.pdf'])),
            TrickleServer::truncatedPdf(self::ANNOUNCED, self::SENT),
        ]);

        return new PoliPage(
            apiKey: 'pp_sandbox_x',
            baseUrl: $this->server->origin,
            maxRetries: 2,
            timeout: 5.0,
            httpClient: self::httpClient($httpClient),
        );
    }

    private static function httpClient(string $name): ClientInterface
    {
        return $name === 'guzzle' ? new GuzzleClient() : new Psr18Client();
    }

    private static function input(): RenderInput
    {
        return new RenderInput(project: 'billing', template: 'invoice', data: []);
    }

    private static function assertNetworkFailure(\Closure $call): void
    {
        try {
            $call();
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::DOWNLOAD_FAILED, $e->errorCode, $e->getMessage());
            self::assertSame('Failed to download PDF: network error', $e->getMessage());
            self::assertNull($e->status);
            self::assertFalse($e->isRetryable());

            return;
        }
        self::fail('Expected DOWNLOAD_FAILED');
    }

    /**
     * @return list<string>
     */
    private static function scan(string $dir): array
    {
        $entries = scandir($dir);

        return $entries === false ? [] : $entries;
    }
}
