<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Events\RequestEvent;
use PoliPage\Events\ResponseEvent;
use PoliPage\PoliPage;
use PoliPage\ProjectModeInput;

#[CoversClass(PoliPage::class)]
final class PoliPageHooksTest extends TestCase
{
    // -----------------------------------------------------------------------
    // onRequest
    // -----------------------------------------------------------------------

    public function testOnRequestFiresOncePerAttemptWithSequentialCounter(): void
    {
        $attempts = [];
        [$client, $mock, $factory] = $this->makeClientWithMock(
            maxRetries: 2,
            onRequest: function (RequestEvent $event) use (&$attempts): void {
                $attempts[] = $event->attempt;
            },
        );
        // First attempt fails, second succeeds.
        $mock->addResponse($factory->createResponse(503));
        $mock->addResponse(
            $factory->createResponse(200)->withBody($factory->createStream(json_encode([
                'html' => '', 'totalPages' => 0, 'environment' => 'sandbox',
            ], flags: JSON_THROW_ON_ERROR))),
        );

        $client->render->preview(new ProjectModeInput(project: 'p', template: 't', data: []));

        self::assertSame([1, 2], $attempts, 'onRequest fires once per attempt with sequential counter');
    }

    public function testOnRequestFiresOnFirstAttemptEvenOnSuccess(): void
    {
        $events = [];
        [$client, $mock, $factory] = $this->makeClientWithMock(
            onRequest: function (RequestEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );
        $mock->addResponse(
            $factory->createResponse(200)->withBody($factory->createStream(json_encode([
                'html' => '', 'totalPages' => 0, 'environment' => 'sandbox',
            ], flags: JSON_THROW_ON_ERROR))),
        );

        $client->render->preview(new ProjectModeInput(project: 'p', template: 't', data: []));

        self::assertCount(1, $events);
        self::assertSame(1, $events[0]->attempt);
        self::assertSame('POST', $events[0]->method);
        self::assertStringContainsString('/v1/render/preview', $events[0]->url);
    }

    public function testThrowingOnRequestDoesNotBreakRequest(): void
    {
        [$client, $mock, $factory] = $this->makeClientWithMock(
            onRequest: static fn (RequestEvent $e) => throw new \RuntimeException('hook blew up'),
        );
        $mock->addResponse(
            $factory->createResponse(200)->withBody($factory->createStream(json_encode([
                'html' => 'ok', 'totalPages' => 1, 'environment' => 'sandbox',
            ], flags: JSON_THROW_ON_ERROR))),
        );

        $result = $client->render->preview(new ProjectModeInput(project: 'p', template: 't', data: []));

        self::assertSame('ok', $result->html);
    }

    // -----------------------------------------------------------------------
    // onResponse
    // -----------------------------------------------------------------------

    public function testOnResponseFiresAfterSuccessful2xxWithCorrectFields(): void
    {
        $events = [];
        [$client, $mock, $factory] = $this->makeClientWithMock(
            onResponse: function (ResponseEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );
        $mock->addResponse(
            $factory->createResponse(200)
                ->withHeader('X-Request-Id', 'req_hello')
                ->withBody($factory->createStream(json_encode([
                    'html' => '', 'totalPages' => 0, 'environment' => 'sandbox',
                ], flags: JSON_THROW_ON_ERROR))),
        );

        $client->render->preview(new ProjectModeInput(project: 'p', template: 't', data: []));

        self::assertCount(1, $events);
        self::assertSame(200, $events[0]->status);
        self::assertSame('req_hello', $events[0]->requestId);
        self::assertGreaterThanOrEqual(0, $events[0]->durationMs);
    }

    public function testOnResponseReceivesNullRequestIdWhenHeaderAbsent(): void
    {
        $events = [];
        [$client, $mock, $factory] = $this->makeClientWithMock(
            onResponse: function (ResponseEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );
        $mock->addResponse(
            $factory->createResponse(200)->withBody($factory->createStream(json_encode([
                'html' => '', 'totalPages' => 0, 'environment' => 'sandbox',
            ], flags: JSON_THROW_ON_ERROR))),
        );

        $client->render->preview(new ProjectModeInput(project: 'p', template: 't', data: []));

        self::assertCount(1, $events);
        self::assertNull($events[0]->requestId);
    }

    public function testOnResponseDoesNotFireOnErrorResponse(): void
    {
        $events = [];
        [$client, $mock, $factory] = $this->makeClientWithMock(
            maxRetries: 0,
            onResponse: function (ResponseEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );
        $mock->addResponse($factory->createResponse(500));

        try {
            $client->render->preview(new ProjectModeInput(project: 'p', template: 't', data: []));
        } catch (\Throwable) {
            // expected
        }

        self::assertCount(0, $events, 'onResponse must not fire for non-2xx responses');
    }

    public function testThrowingOnResponseDoesNotBreakRequest(): void
    {
        [$client, $mock, $factory] = $this->makeClientWithMock(
            onResponse: static fn (ResponseEvent $e) => throw new \RuntimeException('response hook blew up'),
        );
        $mock->addResponse(
            $factory->createResponse(200)->withBody($factory->createStream(json_encode([
                'html' => 'safe', 'totalPages' => 1, 'environment' => 'sandbox',
            ], flags: JSON_THROW_ON_ERROR))),
        );

        $result = $client->render->preview(new ProjectModeInput(project: 'p', template: 't', data: []));

        self::assertSame('safe', $result->html);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @param (\Closure(RequestEvent): void)|null  $onRequest
     * @param (\Closure(ResponseEvent): void)|null $onResponse
     *
     * @return array{0: PoliPage, 1: MockClient, 2: Psr17Factory}
     */
    private function makeClientWithMock(
        int $maxRetries = 0,
        ?\Closure $onRequest = null,
        ?\Closure $onResponse = null,
    ): array {
        $factory = new Psr17Factory();
        $mock = new MockClient();
        $client = new PoliPage(
            apiKey: 'pp_test_xyz',
            maxRetries: $maxRetries,
            retryDelay: 0.0,
            httpClient: $mock,
            requestFactory: $factory,
            streamFactory: $factory,
            onRequest: $onRequest,
            onResponse: $onResponse,
            jitterSource: static fn (): float => 0.0,
        );

        return [$client, $mock, $factory];
    }
}
