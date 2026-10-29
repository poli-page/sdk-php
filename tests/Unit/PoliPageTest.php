<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Events\RetryEvent;
use PoliPage\Exception\ApiStatusException;
use PoliPage\Exception\AuthenticationException;
use PoliPage\Exception\BadRequestException;
use PoliPage\Exception\ConnectionException;
use PoliPage\Exception\InternalServerException;
use PoliPage\Exception\NotFoundException;
use PoliPage\Internal\Constants;
use PoliPage\Internal\Version;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\RenderInput;
use PoliPage\Tests\Support\DocumentWire;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

#[CoversClass(PoliPage::class)]
final class PoliPageTest extends TestCase
{
    public function testConstructorRejectsEmptyApiKey(): void
    {
        $this->expectException(PoliPageException::class);
        $this->expectExceptionMessage('apiKey is required');

        try {
            new PoliPage(apiKey: '');
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::INVALID_OPTIONS, $e->errorCode);
            self::assertNull($e->status);

            throw $e;
        }
    }

    public function testSuccessfulRenderSendsExpectedHttpRequestAndDecodesBody(): void
    {
        [$client, $mock, $factory] = $this->makeClientWithMock();
        $mock->addResponse(
            $factory->createResponse(200)->withBody($factory->createStream(DocumentWire::descriptorJson())),
        );

        $result = $client->render->document(new RenderInput(
            project: 'billing',
            template: 'invoice',
            data: ['n' => 1],
        ));

        self::assertSame('doc_abc', $result->documentId);
        self::assertSame(1, $result->pageCount);
        self::assertSame('sandbox', $result->environment);

        $request = $mock->getLastRequest();
        self::assertNotFalse($request);
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            Constants::DEFAULT_BASE_URL . Constants::PATH_RENDER,
            (string) $request->getUri(),
        );
        self::assertSame('Bearer pp_sandbox_xyz', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame(
            Constants::USER_AGENT_PREFIX . Version::VERSION,
            $request->getHeaderLine('User-Agent'),
        );
        // Idempotency-Key was auto-generated (UUID4 shape).
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $request->getHeaderLine('Idempotency-Key'),
        );
        // Wire body excludes idempotencyKey and timeout SDK-only fields.
        $body = json_decode((string) $request->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            [
                'project' => 'billing',
                'template' => 'invoice',
                'data' => ['n' => 1],
            ],
            $body,
        );
    }

    public function testRetriesOn5xxThenSucceeds(): void
    {
        [$client, $mock, $factory] = $this->makeClientWithMock(maxRetries: 2);
        $mock->addResponse($factory->createResponse(503));
        $mock->addResponse($factory->createResponse(503));
        $mock->addResponse(
            $factory->createResponse(200)->withBody($factory->createStream(DocumentWire::descriptorJson())),
        );

        $client->render->document(new RenderInput(project: 'p', template: 't', data: []));

        self::assertCount(3, $mock->getRequests());
    }

    public function testThrowsInternalServerExceptionWhenRetriesExhaustedOn5xx(): void
    {
        [$client, $mock, $factory] = $this->makeClientWithMock(maxRetries: 1);
        $mock->addResponse(
            $factory->createResponse(503)->withBody($factory->createStream(json_encode([
                'code' => 'INTERNAL_ERROR',
                'message' => 'engine boom',
            ], flags: JSON_THROW_ON_ERROR))),
        );
        $mock->addResponse($factory->createResponse(503));

        try {
            $client->render->document(new RenderInput(project: 'p', template: 't', data: []));
            self::fail('Expected InternalServerException');
        } catch (InternalServerException $e) {
            self::assertSame(503, $e->status);
            self::assertCount(2, $mock->getRequests());
        }
    }

    public function testDoesNotRetryOn401AndThrowsAuthenticationException(): void
    {
        [$client, $mock, $factory] = $this->makeClientWithMock(maxRetries: 3);
        $mock->addResponse(
            $factory->createResponse(401)->withBody($factory->createStream(json_encode([
                'code' => 'INVALID_API_KEY',
                'message' => 'invalid key',
            ], flags: JSON_THROW_ON_ERROR))),
        );

        try {
            $client->render->document(new RenderInput(project: 'p', template: 't', data: []));
            self::fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            self::assertSame(401, $e->status);
            self::assertSame('INVALID_API_KEY', $e->errorCode);
            self::assertCount(1, $mock->getRequests(), 'must not retry on 401');
            self::assertTrue($e->isAuthError());
        }
    }

    public function testDoesNotRetryOn400AndThrowsBadRequestException(): void
    {
        [$client, $mock, $factory] = $this->makeClientWithMock(maxRetries: 3);
        $mock->addResponse(
            $factory->createResponse(400)->withBody($factory->createStream(json_encode([
                'code' => 'VALIDATION_ERROR',
                'message' => 'data is required',
            ], flags: JSON_THROW_ON_ERROR))),
        );

        try {
            $client->render->document(new RenderInput(project: 'p', template: 't', data: []));
            self::fail('Expected BadRequestException');
        } catch (BadRequestException $e) {
            self::assertSame(400, $e->status);
            self::assertCount(1, $mock->getRequests(), 'must not retry on 400');
        }
    }

    public function testErrorBodyErrorFieldIsTheCodeAndDetailIsTheMessage(): void
    {
        [$client, $mock, $factory] = $this->makeClientWithMock(maxRetries: 3);
        $mock->addResponse(
            $factory->createResponse(404)
                ->withHeader('X-Request-Id', 'req_404')
                ->withBody($factory->createStream(json_encode([
                    'error' => 'TEMPLATE_NOT_FOUND',
                    'detail' => 'Template "does-not-exist" not found in project "getting-started"',
                    'requestId' => 'req_404',
                ], flags: JSON_THROW_ON_ERROR))),
        );

        try {
            $client->render->document(new RenderInput(project: 'getting-started', template: 'does-not-exist', data: []));
            self::fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            self::assertSame(PoliPageException::TEMPLATE_NOT_FOUND, $e->errorCode);
            self::assertSame('Template "does-not-exist" not found in project "getting-started"', $e->getMessage());
            self::assertSame(404, $e->status);
            self::assertSame('req_404', $e->requestId);
            self::assertCount(1, $mock->getRequests(), 'must not retry on 404');
        }
    }

    public function testUnknownErrorCodePassesThroughVerbatim(): void
    {
        [$client, $mock, $factory] = $this->makeClientWithMock();
        $mock->addResponse(
            $factory->createResponse(409)->withBody($factory->createStream(json_encode([
                'error' => 'SOME_CODE_ADDED_LATER',
                'detail' => 'a code this SDK version does not know',
            ], flags: JSON_THROW_ON_ERROR))),
        );

        try {
            $client->render->document(new RenderInput(project: 'p', template: 't', data: []));
            self::fail('Expected ApiStatusException');
        } catch (ApiStatusException $e) {
            self::assertSame('SOME_CODE_ADDED_LATER', $e->errorCode);
            self::assertSame('a code this SDK version does not know', $e->getMessage());
            self::assertSame(409, $e->status);
        }
    }

    public function testPublishInProgressIsRetriedAfterRetryAfterAndSurfacesOnceRetriesAreExhausted(): void
    {
        $events = [];
        [$client, $mock, $factory] = $this->makeClientWithMock(
            maxRetries: 1,
            onRetry: function (RetryEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );
        for ($i = 0; $i < 2; ++$i) {
            $mock->addResponse(
                $factory->createResponse(503)
                    ->withHeader('Retry-After', '1')
                    ->withBody($factory->createStream(json_encode([
                        'error' => 'PUBLISH_IN_PROGRESS',
                        'detail' => 'A publish of this project is in progress',
                    ], flags: JSON_THROW_ON_ERROR))),
            );
        }

        $started = microtime(true);
        try {
            $client->render->document(new RenderInput(project: 'p', template: 't', data: []));
            self::fail('Expected InternalServerException');
        } catch (InternalServerException $e) {
            self::assertSame(PoliPageException::PUBLISH_IN_PROGRESS, $e->errorCode);
            self::assertSame(503, $e->status);
            self::assertTrue($e->isRetryable());
        }

        self::assertCount(2, $mock->getRequests());
        self::assertCount(1, $events);
        self::assertSame(1000.0, $events[0]->delayMs);
        self::assertSame(PoliPageException::PUBLISH_IN_PROGRESS, $events[0]->reason->errorCode);
        // Why: Windows timers measured 0.99993 s around the 1 s usleep; 0.9 still proves the client slept.
        self::assertGreaterThanOrEqual(0.9, microtime(true) - $started);
    }

    public function testThumbnailsWithoutOptionsSendsAnEmptyJsonObject(): void
    {
        [$client, $mock, $factory] = $this->makeClientWithMock();
        $mock->addResponse(
            $factory->createResponse(200)->withBody($factory->createStream('{"thumbnails":[]}')),
        );

        self::assertSame([], $client->documents->thumbnails('doc_abc'));

        $request = $mock->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertStringEndsWith('/v1/documents/doc_abc/thumbnails', (string) $request->getUri());
        self::assertSame('{}', (string) $request->getBody());
    }

    public function testDeleteResolvesOn204IncludingForAnAlreadyDeletedDocument(): void
    {
        [$client, $mock, $factory] = $this->makeClientWithMock();
        $mock->addResponse($factory->createResponse(204));
        $mock->addResponse($factory->createResponse(204));

        $client->documents->delete('doc_abc');
        $client->documents->delete('doc_abc');

        self::assertCount(2, $mock->getRequests());
        self::assertSame('DELETE', $mock->getRequests()[1]->getMethod());
    }

    public function testRetriesOnNetworkErrorAndThrowsConnectionExceptionWhenExhausted(): void
    {
        [$client, $mock, $factory] = $this->makeClientWithMock(maxRetries: 1);
        $networkException = $this->makeNetworkException($factory);
        $mock->addException($networkException);
        $mock->addException($networkException);

        try {
            $client->render->document(new RenderInput(project: 'p', template: 't', data: []));
            self::fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            self::assertSame(PoliPageException::NETWORK_ERROR, $e->errorCode);
            self::assertSame('Network request failed', $e->getMessage());
            self::assertSame($networkException, $e->getPrevious());
            self::assertNull($e->status);
            self::assertCount(2, $mock->getRequests());
        }
    }

    public function testOnRetryHookFiresWithRetryEvent(): void
    {
        $events = [];
        [$client, $mock, $factory] = $this->makeClientWithMock(
            maxRetries: 2,
            onRetry: function (RetryEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );
        $mock->addResponse($factory->createResponse(503));
        $mock->addResponse(
            $factory->createResponse(200)->withBody($factory->createStream(DocumentWire::descriptorJson())),
        );

        $client->render->document(new RenderInput(project: 'p', template: 't', data: []));

        self::assertCount(1, $events);
        self::assertSame(2, $events[0]->attempt);
        self::assertSame(503, $events[0]->reason->status);
    }

    public function testOnErrorHookFiresOnTerminalFailure(): void
    {
        $captured = null;
        [$client, $mock, $factory] = $this->makeClientWithMock(
            maxRetries: 0,
            onError: function (PoliPageException $e) use (&$captured): void {
                $captured = $e;
            },
        );
        $mock->addResponse($factory->createResponse(401));

        try {
            $client->render->document(new RenderInput(project: 'p', template: 't', data: []));
        } catch (PoliPageException) {
            // expected
        }

        self::assertInstanceOf(AuthenticationException::class, $captured);
    }

    public function testHookExceptionsAreSwallowedAndDoNotBreakTheRequest(): void
    {
        [$client, $mock, $factory] = $this->makeClientWithMock(
            maxRetries: 1,
            onRetry: static fn (RetryEvent $e) => throw new \RuntimeException('hook broke'),
        );
        $mock->addResponse($factory->createResponse(503));
        $mock->addResponse(
            $factory->createResponse(200)->withBody($factory->createStream(DocumentWire::descriptorJson())),
        );

        $result = $client->render->document(new RenderInput(project: 'p', template: 't', data: []));

        // If the hook exception had escaped, this assertion would never run.
        self::assertSame('doc_abc', $result->documentId);
    }

    /**
     * @param (\Closure(RetryEvent): void)|null     $onRetry
     * @param (\Closure(PoliPageException): void)|null $onError
     *
     * @return array{0: PoliPage, 1: MockClient, 2: Psr17Factory}
     */
    private function makeClientWithMock(
        int $maxRetries = 0,
        ?\Closure $onRetry = null,
        ?\Closure $onError = null,
    ): array {
        $factory = new Psr17Factory();
        $mock = new MockClient();
        $client = new PoliPage(
            apiKey: 'pp_sandbox_xyz',
            maxRetries: $maxRetries,
            retryDelay: 0.0,
            httpClient: $mock,
            requestFactory: $factory,
            streamFactory: $factory,
            onRetry: $onRetry,
            onError: $onError,
            jitterSource: static fn (): float => 0.0,
        );

        return [$client, $mock, $factory];
    }

    /**
     * @return \RuntimeException&NetworkExceptionInterface
     */
    private function makeNetworkException(Psr17Factory $factory): \RuntimeException
    {
        return new class ($factory->createRequest('POST', 'https://api.poli.page/v1/render')) extends \RuntimeException implements NetworkExceptionInterface {
            public function __construct(private RequestInterface $request)
            {
                parent::__construct('connection refused');
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };
    }
}
