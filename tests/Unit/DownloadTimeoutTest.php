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
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * The presigned-URL download is bounded by the SDK's `timeout`, through
 * clients built with no timeout of their own: as a whole for `render->pdf()`
 * and `downloadPdf()`, as an idle timeout (the wait for the first byte and
 * each gap between two chunks) for `render->pdfStream()`, where the wait for
 * the headers and the wait for the first byte share one budget. Past it, the error
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

    /** For the first-byte budget: delays of 300 ms or more stay clear of it on a busy runner. */
    private const FIRST_BYTE_TIMEOUT = 1.0;

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

    /**
     * The wait for the first byte never ends before the timeout, measured on
     * `hrtime()` from before the download starts: PHP waits on a socket for
     * whole milliseconds and drops the rest, so a time left of 99.6 ms armed
     * as is used to end the wait after 99 ms. A timeout with a fraction of a
     * millisecond, ten times over, since the fraction left at the first
     * read varies with the time the headers took.
     */
    #[DataProvider('httpClients')]
    public function testPdfStreamFirstByteTimeoutNeverEndsBeforeItsBound(string $httpClient): void
    {
        $timeout = 0.1005;
        for ($i = 0; $i < 10; ++$i) {
            $this->server?->stop();
            $server = new TrickleServer([TrickleServer::pdf(0, [[3000, '%PDF-1.7']])]);
            $this->server = $server;
            $client = new PoliPage(apiKey: 'pp_sandbox_x', timeout: $timeout, httpClient: self::httpClient($httpClient));

            $startedAt = hrtime(true);
            $error = self::downloadFailure(static fn () => self::readAll($client->streamBytes($server->origin . '/doc.pdf', $timeout), 'read'));
            $elapsedNs = hrtime(true) - $startedAt;

            self::assertTimeoutFailure($error);
            self::assertGreaterThanOrEqual(
                (int) round($timeout * 1e9),
                $elapsedNs,
                sprintf('the %.1f ms timeout ended after %.3f ms', $timeout * 1000, $elapsedNs / 1e6),
            );
        }
    }

    /**
     * The headers and the first byte of the body, each within the timeout
     * but not both: the two waits share one budget.
     */
    #[DataProvider('httpClientsAndReads')]
    public function testPdfStreamHeadersAndFirstByteShareOneBudget(string $httpClient, string $read): void
    {
        $client = $this->clientFor(
            $httpClient,
            TrickleServer::pdf(600, [[600, '%PDF-1.7'], [0, ' rest']]),
            self::FIRST_BYTE_TIMEOUT,
        );

        $startedAt = hrtime(true);
        $error = self::downloadFailure(static fn () => self::readAll($client->render->pdfStream(self::input()), $read));

        self::assertTimeoutFailure($error);
        self::assertLessThan(3.0, self::secondsSince($startedAt));
    }

    /**
     * Headers that trickle in, each gap within the timeout, but complete
     * only once the whole budget is gone.
     */
    #[DataProvider('httpClients')]
    public function testPdfStreamTimesOutWhenTheHeadersTakeTheWholeBudget(string $httpClient): void
    {
        $client = $this->clientFor($httpClient, [
            [400, "HTTP/1.1 200 OK\r\n"],
            [400, "content-type: application/pdf\r\n"],
            [400, "content-length: 8\r\nconnection: close\r\n\r\n%PDF-1.7"],
        ], self::FIRST_BYTE_TIMEOUT);

        $error = self::downloadFailure(static function () use ($client): void {
            $stream = $client->render->pdfStream(self::input());
            while (!$stream->eof()) {
                $stream->read(8192);
            }
        });

        self::assertTimeoutFailure($error);
    }

    /**
     * Headers that keep trickling in, each gap within the timeout, are a
     * timeout. Symfony stops them once the budget is gone. Guzzle reads them
     * inside PHP's HTTP stream wrapper, which bounds only each gap, so its
     * timeout comes once they are complete (a documented exception).
     */
    #[DataProvider('httpClients')]
    public function testPdfStreamHeadersThatKeepTricklingInAreATimeout(string $httpClient): void
    {
        $client = $this->clientFor($httpClient, [
            [0, "HTTP/1.1 200 OK\r\n"],
            [400, "x-a: 1\r\n"],
            [400, "x-b: 1\r\n"],
            [400, "x-c: 1\r\n"],
            [400, "x-d: 1\r\n"],
            [400, "x-e: 1\r\n"],
            [400, "content-type: application/pdf\r\ncontent-length: 8\r\nconnection: close\r\n\r\n%PDF-1.7"],
        ], self::FIRST_BYTE_TIMEOUT);

        $startedAt = hrtime(true);
        $error = self::downloadFailure(static fn () => self::readAll($client->render->pdfStream(self::input()), 'read'));

        self::assertTimeoutFailure($error);
        if ($httpClient === 'symfony') {
            self::assertLessThan(2.0, self::secondsSince($startedAt), 'stopped before the headers are complete');
        }
    }

    /**
     * Once the first byte is in, each gap gets the whole timeout again.
     */
    #[DataProvider('httpClientsAndReads')]
    public function testPdfStreamGapsAfterTheFirstByteGetTheIdleTimeout(string $httpClient, string $read): void
    {
        $client = $this->clientFor(
            $httpClient,
            TrickleServer::pdf(300, [[300, '%PDF-1.7'], [700, ' second'], [700, ' third']]),
            self::FIRST_BYTE_TIMEOUT,
        );

        self::assertSame('%PDF-1.7 second third', self::readAll($client->render->pdfStream(self::input()), $read));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function httpClientsAndReads(): iterable
    {
        foreach (['guzzle', 'symfony'] as $httpClient) {
            foreach (['read', 'getContents', '__toString'] as $read) {
                yield $httpClient . ' ' . $read => [$httpClient, $read];
            }
        }
    }

    private static function readAll(StreamInterface $stream, string $read): string
    {
        if ($read === 'getContents') {
            return $stream->getContents();
        }
        if ($read === '__toString') {
            return (string) $stream;
        }
        $bytes = '';
        while (!$stream->eof()) {
            $bytes .= $stream->read(8192);
        }

        return $bytes;
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
    private function clientFor(string $httpClient, array $download, float $timeout = self::TIMEOUT): PoliPage
    {
        $this->server = new TrickleServer([
            TrickleServer::json(DocumentWire::descriptorJson(['presignedPdfUrl' => '{{origin}}/doc.pdf'])),
            $download,
        ]);

        return new PoliPage(
            apiKey: 'pp_sandbox_x',
            baseUrl: $this->server->origin,
            maxRetries: 2,
            timeout: $timeout,
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
