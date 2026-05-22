<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Events\RetryEvent;
use PoliPage\Exception\InternalServerException;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\ProjectModeInput;

/**
 * Behavioural coverage for the bits of PoliPage's HTTP path the original
 * PoliPageTest didn't exercise: the unauthenticated presigned-URL GET
 * (DOWNLOAD_FAILED path, empty-body INTERNAL_ERROR), Retry-After
 * propagation into the backoff, and non-array JSON responses.
 */
#[CoversClass(PoliPage::class)]
final class PoliPageTransportTest extends TestCase
{
    public function testFetchBytesThrowsDownloadFailedOnNon2xxResponse(): void
    {
        $factory = new Psr17Factory();
        $mock = new MockClient();
        $mock->addResponse($factory->createResponse(403)->withBody($factory->createStream('Forbidden')));
        $client = new PoliPage(
            apiKey: 'pp_test_x',
            httpClient: $mock,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        try {
            $client->fetchBytes('https://s3.example.com/expired.pdf?sig=stale', null);
            self::fail('Expected PoliPageException(DOWNLOAD_FAILED)');
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::DOWNLOAD_FAILED, $e->errorCode);
            self::assertSame(403, $e->status);
        }
    }

    public function testFetchBytesThrowsInternalErrorOnEmptyBody(): void
    {
        $factory = new Psr17Factory();
        $mock = new MockClient();
        $mock->addResponse($factory->createResponse(200)); // empty body
        $client = new PoliPage(
            apiKey: 'pp_test_x',
            httpClient: $mock,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        try {
            $client->fetchBytes('https://s3.example.com/empty.pdf?sig=x', null);
            self::fail('Expected PoliPageException(INTERNAL_ERROR)');
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::INTERNAL_ERROR, $e->errorCode);
        }
    }

    public function testRetryAfterHeaderControlsBackoffOn429(): void
    {
        $factory = new Psr17Factory();
        $mock = new MockClient();
        // First response: 429 with Retry-After: 0 (no actual sleep budget needed).
        // Second response: success.
        $mock->addResponse(
            $factory->createResponse(429)
                ->withHeader('Retry-After', '0')
                ->withBody($factory->createStream(json_encode([
                    'code' => 'QUOTA_EXCEEDED',
                    'message' => 'slow down',
                ], flags: JSON_THROW_ON_ERROR))),
        );
        $mock->addResponse(
            $factory->createResponse(200)->withBody($factory->createStream(json_encode([
                'html' => '', 'totalPages' => 0, 'environment' => 'sandbox',
            ], flags: JSON_THROW_ON_ERROR))),
        );

        $events = [];
        $client = new PoliPage(
            apiKey: 'pp_test_x',
            maxRetries: 2,
            retryDelay: 99.0, // would normally cause a long sleep — Retry-After: 0 overrides it
            httpClient: $mock,
            requestFactory: $factory,
            streamFactory: $factory,
            onRetry: static function (RetryEvent $e) use (&$events): void {
                $events[] = $e;
            },
            jitterSource: static fn (): float => 0.5, // would yield jitterFactor=1.0
        );

        $client->render->preview(new ProjectModeInput(project: 'p', template: 't', data: []));

        self::assertCount(1, $events, 'one retry should have fired between the two responses');
        // Retry-After: 0 should win over the otherwise-99s exponential backoff,
        // so the recorded delay sits well under what retryDelay would produce.
        self::assertLessThan(100.0, $events[0]->delayMs, 'Retry-After=0 must override the configured retryDelay');
    }

    public function testNonArrayJsonResponseRaisesInternalError(): void
    {
        $factory = new Psr17Factory();
        $mock = new MockClient();
        // Wire shape violation: response body is a JSON scalar instead of an object.
        $mock->addResponse(
            $factory->createResponse(200)->withBody($factory->createStream('42')),
        );
        $client = new PoliPage(
            apiKey: 'pp_test_x',
            httpClient: $mock,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        try {
            $client->render->preview(new ProjectModeInput(project: 'p', template: 't', data: []));
            self::fail('Expected PoliPageException(INTERNAL_ERROR)');
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::INTERNAL_ERROR, $e->errorCode);
            self::assertStringContainsString('non-object JSON', $e->getMessage());
        }
    }

    public function testMalformedJsonResponseRaisesInternalError(): void
    {
        $factory = new Psr17Factory();
        $mock = new MockClient();
        $mock->addResponse(
            $factory->createResponse(200)->withBody($factory->createStream('not json at all')),
        );
        $client = new PoliPage(
            apiKey: 'pp_test_x',
            httpClient: $mock,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        try {
            $client->render->preview(new ProjectModeInput(project: 'p', template: 't', data: []));
            self::fail('Expected PoliPageException(INTERNAL_ERROR)');
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::INTERNAL_ERROR, $e->errorCode);
            self::assertSame(200, $e->status, 'status from the wire response should propagate');
        }
    }

    public function testServer5xxAfterMaxRetriesSurfacesInternalServerException(): void
    {
        $factory = new Psr17Factory();
        $mock = new MockClient();
        for ($i = 0; $i < 3; ++$i) {
            $mock->addResponse(
                $factory->createResponse(503)->withBody($factory->createStream(json_encode([
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'engine cold',
                ], flags: JSON_THROW_ON_ERROR))),
            );
        }
        $client = new PoliPage(
            apiKey: 'pp_test_x',
            maxRetries: 2,
            retryDelay: 0.0,
            httpClient: $mock,
            requestFactory: $factory,
            streamFactory: $factory,
            jitterSource: static fn (): float => 0.0,
        );

        try {
            $client->render->preview(new ProjectModeInput(project: 'p', template: 't', data: []));
            self::fail('Expected InternalServerException after retries exhausted');
        } catch (InternalServerException $e) {
            self::assertSame(503, $e->status);
            self::assertCount(3, $mock->getRequests());
        }
    }
}
