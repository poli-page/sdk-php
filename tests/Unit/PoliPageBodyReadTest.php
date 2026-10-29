<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Events\ResponseEvent;
use PoliPage\Events\RetryEvent;
use PoliPage\Exception\BadRequestException;
use PoliPage\Exception\ConnectionException;
use PoliPage\Exception\InternalServerException;
use PoliPage\Exception\TimeoutException;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\RenderInput;
use PoliPage\Tests\Support\DocumentWire;
use PoliPage\Tests\Support\FailingStream;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * An answer's whole body is read inside its attempt (SDK spec §9.1, §8.2),
 * and a PDF download fails as DOWNLOAD_FAILED (§7.2), never as the HTTP
 * library's own exception or a PHP warning (Internal\Http\BodyReader,
 * Internal\Http\DownloadStream).
 */
#[CoversClass(PoliPage::class)]
final class PoliPageBodyReadTest extends TestCase
{
    private Psr17Factory $factory;

    private MockClient $mock;

    /** @var list<ResponseEvent> */
    private array $responses = [];

    /** @var list<RetryEvent> */
    private array $retries = [];

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->mock = new MockClient();
    }

    public function testA2xxBodyDroppedMidReadIsANetworkErrorAndIsRetried(): void
    {
        $drop = new \RuntimeException('Unable to read stream contents: End of response with 86 bytes missing');
        $this->mock->addResponse($this->factory->createResponse(200)->withBody(new FailingStream('{"documentId":', $drop)));
        $this->mock->addResponse($this->descriptorResponse());

        $descriptor = $this->client(maxRetries: 1)->render->document($this->input());

        self::assertSame('doc_abc', $descriptor->documentId);
        self::assertCount(1, $this->retries);
        $reason = $this->retries[0]->reason;
        self::assertInstanceOf(ConnectionException::class, $reason);
        self::assertSame(PoliPageException::NETWORK_ERROR, $reason->errorCode);
        self::assertSame('Network request failed', $reason->getMessage());
        self::assertSame($drop, $reason->getPrevious());
        self::assertCount(1, $this->responses, 'onResponse fires only once the body is read, never for the cut one');
    }

    public function testA2xxBodyWarningMidReadIsANetworkError(): void
    {
        $this->mock->addResponse($this->factory->createResponse(200)->withBody(
            new FailingStream('{"documentId":', new \RuntimeException('End of response with 86 bytes missing'), warn: true),
        ));

        try {
            $this->client()->render->document($this->input());
            self::fail('Expected a network_error');
        } catch (ConnectionException $e) {
            self::assertSame(PoliPageException::NETWORK_ERROR, $e->errorCode);
            self::assertInstanceOf(\ErrorException::class, $e->getPrevious());
            self::assertSame('End of response with 86 bytes missing', $e->getPrevious()->getMessage());
        }
        self::assertSame([], $this->responses);
    }

    public function testA2xxBodyTimingOutMidReadIsATimeout(): void
    {
        $this->mock->addResponse($this->factory->createResponse(200)->withBody(
            new FailingStream('{"documentId":', new \RuntimeException('Idle timeout reached for "http://127.0.0.1/v1/render".')),
        ));

        try {
            $this->client(timeout: 2.5)->render->document($this->input());
            self::fail('Expected a timeout');
        } catch (TimeoutException $e) {
            self::assertSame(PoliPageException::TIMEOUT, $e->errorCode);
            self::assertSame('Request timed out after 2500ms', $e->getMessage());
            self::assertTrue($e->isRetryable());
        }
    }

    public function testAnErrorBodyFailingMidReadKeepsItsStatusAndIsRetriedByIt(): void
    {
        $drop = new \RuntimeException('End of response with 20 bytes missing');
        foreach ([1, 2] as $attempt) {
            $this->mock->addResponse($this->factory->createResponse(503)
                ->withHeader('x-request-id', 'req_' . $attempt)
                ->withBody(new FailingStream('{"error":"ORGANIZATION_MIGRATING"', $drop)));
        }

        try {
            $this->client(maxRetries: 1)->documents->get('doc_abc');
            self::fail('Expected INTERNAL_ERROR');
        } catch (InternalServerException $e) {
            // SDK spec §8.2: a malformed error body, never a code from the partial bytes.
            self::assertSame(PoliPageException::INTERNAL_ERROR, $e->errorCode);
            self::assertSame('HTTP 503', $e->getMessage());
            self::assertSame(503, $e->status);
            self::assertSame('req_2', $e->requestId);
            self::assertSame($drop, $e->getPrevious());
        }
        self::assertCount(2, $this->mock->getRequests(), 'a 503 is retried');
    }

    public function testA400BodyFailingMidReadIsNotRetried(): void
    {
        $this->mock->addResponse($this->factory->createResponse(400)
            ->withBody(new FailingStream('{"error":"VALIDATION_ERROR"', new \RuntimeException('End of response'))));

        try {
            $this->client(maxRetries: 2)->documents->get('doc_abc');
            self::fail('Expected INTERNAL_ERROR');
        } catch (BadRequestException $e) {
            self::assertSame(PoliPageException::INTERNAL_ERROR, $e->errorCode);
            self::assertSame('HTTP 400', $e->getMessage());
            self::assertFalse($e->isRetryable());
        }
        self::assertCount(1, $this->mock->getRequests());
    }

    public function testATransportTimeoutHasTheSpecMessageInMilliseconds(): void
    {
        $timeout = new class ('cURL error 28: Operation timed out after 1500 milliseconds') extends \RuntimeException implements ClientExceptionInterface {
        };
        $this->mock->addException($timeout);

        try {
            $this->client(timeout: 1.5)->documents->get('doc_abc');
            self::fail('Expected a timeout');
        } catch (TimeoutException $e) {
            self::assertSame('Request timed out after 1500ms', $e->getMessage());
            self::assertSame($timeout, $e->getPrevious());
        }
    }

    public function testDownloadNon2xxHasTheStorageStatus(): void
    {
        $this->mock->addResponse($this->factory->createResponse(403)->withBody($this->factory->createStream('<Error/>')));

        try {
            $this->client()->fetchBytes('https://s3.example.com/doc.pdf?sig=x', null);
            self::fail('Expected DOWNLOAD_FAILED');
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::DOWNLOAD_FAILED, $e->errorCode);
            self::assertSame('Failed to download PDF: HTTP 403', $e->getMessage());
            self::assertSame(403, $e->status);
            self::assertTrue($e->isAuthError());
            self::assertFalse($e->isRetryable());
        }
    }

    public function testDownloadWithoutAnAnswerIsDownloadFailedEvenOnATimeout(): void
    {
        $timeout = new class ('cURL error 28: Operation timed out') extends \RuntimeException implements ClientExceptionInterface {
        };
        $this->mock->addException($timeout);

        try {
            $this->client()->fetchBytes('https://s3.example.com/doc.pdf?sig=x', 5.0);
            self::fail('Expected DOWNLOAD_FAILED');
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::DOWNLOAD_FAILED, $e->errorCode);
            self::assertSame('Failed to download PDF: network error', $e->getMessage());
            self::assertNull($e->status);
            self::assertSame($timeout, $e->getPrevious());
            self::assertFalse($e->isRetryable(), 'the download is never retried');
        }
    }

    public function testRenderPdfBodyDroppedMidReadIsDownloadFailed(): void
    {
        $drop = new \RuntimeException('Unable to read stream contents: End of response with 4000 bytes missing');
        $this->mock->addResponse($this->descriptorResponse());
        $this->mock->addResponse($this->factory->createResponse(200)->withBody(new FailingStream('%PDF-1.4 partial', $drop)));

        try {
            $this->client()->render->pdf($this->input());
            self::fail('Expected DOWNLOAD_FAILED');
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::DOWNLOAD_FAILED, $e->errorCode);
            self::assertSame('Failed to download PDF: network error', $e->getMessage());
            self::assertNull($e->status);
            self::assertSame($drop, $e->getPrevious());
        }
    }

    public function testRenderPdfStreamReadFailureIsDownloadFailed(): void
    {
        $this->mock->addResponse($this->descriptorResponse());
        $this->mock->addResponse($this->factory->createResponse(200)->withBody(
            new FailingStream('%PDF-1.4 partial', new \RuntimeException('End of response with 4000 bytes missing'), warn: true),
        ));
        $stream = $this->client()->render->pdfStream($this->input());

        self::assertSame('%PDF-1.4 partial', $stream->read(8192));
        try {
            $stream->read(8192);
            self::fail('Expected DOWNLOAD_FAILED');
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::DOWNLOAD_FAILED, $e->errorCode);
            self::assertSame('Failed to download PDF: network error', $e->getMessage());
            self::assertInstanceOf(\ErrorException::class, $e->getPrevious());
        }
    }

    private function client(int $maxRetries = 0, float $timeout = 60.0): PoliPage
    {
        return new PoliPage(
            apiKey: 'pp_sandbox_x',
            maxRetries: $maxRetries,
            retryDelay: 0.001,
            timeout: $timeout,
            httpClient: $this->mock,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
            onRetry: function (RetryEvent $event): void {
                $this->retries[] = $event;
            },
            onResponse: function (ResponseEvent $event): void {
                $this->responses[] = $event;
            },
        );
    }

    private function input(): RenderInput
    {
        return new RenderInput(project: 'p', template: 't', data: []);
    }

    private function descriptorResponse(): \Psr\Http\Message\ResponseInterface
    {
        return $this->factory->createResponse(200)->withBody($this->factory->createStream(DocumentWire::descriptorJson()));
    }
}
