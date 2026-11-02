<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use GuzzleHttp\Client as GuzzleClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoliPage\DocumentDescriptor;
use PoliPage\Internal\Http\TimeoutPolicy;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\RenderInput;
use PoliPage\Tests\Support\DocumentWire;
use PoliPage\Tests\Support\TrickleServer;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * The presigned-URL download is bounded by the SDK's `timeout`, through
 * clients built with no timeout of their own: as a whole for `render->pdf()`
 * and `downloadPdf()`, as an idle timeout (the wait for the first byte and
 * each gap between two chunks) for `render->pdfStream()`. Past it, the error
 * is DOWNLOAD_FAILED without a status, with the timeout message, and is not
 * retryable.
 *
 * Against a local server that sends the PDF piece by piece (TrickleServer):
 * the contract's mock has no step that sends part of a body and then stalls.
 */
#[CoversClass(TimeoutPolicy::class)]
final class DownloadTimeoutTest extends TestCase
{
    private const TIMEOUT = 0.5;

    /** Ten chunks 100 ms apart: each gap well under the timeout, the whole twice over it. */
    private const TRICKLE_CHUNKS = 10;

    private const TRICKLE_GAP_MS = 100;

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
    public function testRenderPdfDownloadWithNoAnswerTimesOut(string $httpClient): void
    {
        $client = $this->clientFor($httpClient, TrickleServer::pdf(5000, [[0, '%PDF-1.7']]));

        $startedAt = hrtime(true);
        $error = self::downloadFailure(fn () => $client->render->pdf(self::input()));

        self::assertTimeoutFailure($error);
        self::assertLessThan(3.0, self::secondsSince($startedAt), 'the 0.5 s timeout ended the download');
    }

    #[DataProvider('httpClients')]
    public function testRenderPdfDownloadIsBoundedAsAWhole(string $httpClient): void
    {
        $client = $this->clientFor($httpClient, TrickleServer::pdf(0, self::trickle()));

        $startedAt = hrtime(true);
        $error = self::downloadFailure(fn () => $client->render->pdf(self::input()));

        self::assertTimeoutFailure($error);
        self::assertLessThan(
            self::TRICKLE_CHUNKS * self::TRICKLE_GAP_MS / 1000,
            self::secondsSince($startedAt),
            'the download ended before the trickle did, although no gap reached the timeout',
        );
    }

    #[DataProvider('httpClients')]
    public function testDownloadPdfDownloadIsBoundedAsAWhole(string $httpClient): void
    {
        $this->server = new TrickleServer([TrickleServer::pdf(0, self::trickle())]);
        $client = new PoliPage(
            apiKey: 'pp_sandbox_x',
            maxRetries: 2,
            timeout: self::TIMEOUT,
            httpClient: self::httpClient($httpClient),
        );
        $descriptor = DocumentDescriptor::fromWire(
            DocumentWire::descriptor(['presignedPdfUrl' => $this->server->origin . '/doc.pdf']),
            $client,
        );

        $error = self::downloadFailure(fn () => $descriptor->downloadPdf());

        self::assertTimeoutFailure($error);
    }

    #[DataProvider('httpClients')]
    public function testPdfStreamTimeoutIsAnIdleTimeoutNotATotal(string $httpClient): void
    {
        $client = $this->clientFor($httpClient, TrickleServer::pdf(0, self::trickle()));

        $startedAt = hrtime(true);
        $stream = $client->render->pdfStream(self::input());
        $bytes = '';
        while (!$stream->eof()) {
            $bytes .= $stream->read(8192);
        }
        $stream->close();

        self::assertSame(str_repeat('%PDF-chunk', self::TRICKLE_CHUNKS), $bytes);
        self::assertGreaterThan(self::TIMEOUT, self::secondsSince($startedAt), 'the stream outlived the timeout');
    }

    #[DataProvider('httpClients')]
    public function testPdfStreamTimesOutWaitingForTheFirstByte(string $httpClient): void
    {
        $client = $this->clientFor($httpClient, TrickleServer::pdf(5000, [[0, '%PDF-1.7']]));

        $startedAt = hrtime(true);
        $error = self::downloadFailure(fn () => $client->render->pdfStream(self::input()));

        self::assertTimeoutFailure($error);
        self::assertLessThan(3.0, self::secondsSince($startedAt));
    }

    #[DataProvider('httpClients')]
    public function testPdfStreamFailsWhenTheBodyStallsMidway(string $httpClient): void
    {
        $client = $this->clientFor($httpClient, TrickleServer::pdf(0, [[0, '%PDF-1.7 first part'], [5000, ' rest']]));

        $startedAt = hrtime(true);
        $stream = $client->render->pdfStream(self::input());
        $error = self::downloadFailure(static function () use ($stream): void {
            while (!$stream->eof()) {
                $stream->read(8192);
            }
        });

        self::assertTimeoutFailure($error);
        self::assertLessThan(3.0, self::secondsSince($startedAt));
    }

    /**
     * A timeout of 0 sets no bound: a failure is never labelled `timeout`
     * by the clock, it keeps its own reason.
     */
    #[DataProvider('httpClients')]
    public function testZeroTimeoutKeepsTheRealReasonOfAFailedDownload(string $httpClient): void
    {
        $client = new PoliPage(apiKey: 'pp_sandbox_x', timeout: 0.0, httpClient: self::httpClient($httpClient));
        $descriptor = DocumentDescriptor::fromWire(
            DocumentWire::descriptor(['presignedPdfUrl' => TrickleServer::refusedOrigin() . '/doc.pdf']),
            $client,
        );

        foreach ([fn () => $descriptor->downloadPdf(), fn () => $client->streamBytes($descriptor->presignedPdfUrl, null)] as $call) {
            $error = self::downloadFailure($call);
            self::assertSame(PoliPageException::DOWNLOAD_FAILED, $error->errorCode);
            self::assertSame('Failed to download PDF: network error', $error->getMessage());
            self::assertNull($error->status);
        }
    }

    /**
     * A client at a server that answers the render, then the download with
     * `$download`.
     *
     * @param list<array{int, string}> $download
     */
    private function clientFor(string $httpClient, array $download): PoliPage
    {
        $this->server = new TrickleServer([
            TrickleServer::json(DocumentWire::descriptorJson(['presignedPdfUrl' => '{{origin}}/doc.pdf'])),
            $download,
        ]);

        return new PoliPage(
            apiKey: 'pp_sandbox_x',
            baseUrl: $this->server->origin,
            maxRetries: 2,
            timeout: self::TIMEOUT,
            httpClient: self::httpClient($httpClient),
        );
    }

    /**
     * @return list<array{int, string}>
     */
    private static function trickle(): array
    {
        return array_fill(0, self::TRICKLE_CHUNKS, [self::TRICKLE_GAP_MS, '%PDF-chunk']);
    }

    private static function httpClient(string $name): ClientInterface
    {
        // No timeout of their own: only the SDK's reaches them.
        return $name === 'guzzle' ? new GuzzleClient() : new Psr18Client();
    }

    private static function input(): RenderInput
    {
        return new RenderInput(project: 'billing', template: 'invoice', data: []);
    }

    private static function downloadFailure(\Closure $call): PoliPageException
    {
        try {
            $call();
        } catch (PoliPageException $e) {
            return $e;
        }
        self::fail('Expected DOWNLOAD_FAILED');
    }

    private static function assertTimeoutFailure(PoliPageException $error): void
    {
        self::assertSame(PoliPageException::DOWNLOAD_FAILED, $error->errorCode, $error->getMessage());
        self::assertSame('Failed to download PDF: timeout', $error->getMessage());
        self::assertNull($error->status);
        self::assertNull($error->requestId);
        self::assertFalse($error->isRetryable());
        self::assertFalse($error->isNetworkError());
        self::assertNotNull($error->getPrevious(), "the HTTP library's error is chained");
    }

    private static function secondsSince(int $startedAtNs): float
    {
        return (hrtime(true) - $startedAtNs) / 1e9;
    }
}
