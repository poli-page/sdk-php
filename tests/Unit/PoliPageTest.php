<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Events\RetryEvent;
use PoliPage\Exception\AuthenticationException;
use PoliPage\Exception\BadRequestException;
use PoliPage\Exception\ConnectionException;
use PoliPage\Exception\InternalServerException;
use PoliPage\Internal\Constants;
use PoliPage\Internal\Version;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\ProjectModeInput;
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

    public function testSuccessfulPreviewSendsExpectedHttpRequestAndDecodesBody(): void
    {
        [$client, $mock, $factory] = $this->makeClientWithMock();
        $mock->addResponse(
            $factory->createResponse(200)->withBody($factory->createStream(json_encode([
                'html' => '<p>hi</p>',
                'totalPages' => 2,
                'environment' => 'sandbox',
            ], flags: JSON_THROW_ON_ERROR))),
        );

        $result = $client->render->preview(new ProjectModeInput(
            project: 'billing',
            template: 'invoice',
            data: ['n' => 1],
            version: '1.0.0',
        ));

        self::assertSame('<p>hi</p>', $result->html);
        self::assertSame(2, $result->totalPages);
        self::assertSame('sandbox', $result->environment);

        $request = $mock->getLastRequest();
        self::assertNotFalse($request);
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            Constants::DEFAULT_BASE_URL . Constants::PATH_RENDER_PREVIEW,
            (string) $request->getUri(),
        );
        self::assertSame('Bearer pp_test_xyz', $request->getHeaderLine('Authorization'));
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
                'version' => '1.0.0',
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
            $factory->createResponse(200)->withBody($factory->createStream(json_encode([
                'html' => '',
                'totalPages' => 0,
                'environment' => 'sandbox',
            ], flags: JSON_THROW_ON_ERROR))),
        );

        $client->render->preview(new ProjectModeInput(project: 'p', template: 't', data: []));

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
            $client->render->preview(new ProjectModeInput(project: 'p', template: 't', data: []));
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
            $client->render->preview(new ProjectModeInput(project: 'p', template: 't', data: []));
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
            $client->render->preview(new ProjectModeInput(project: 'p', template: 't', data: []));
            self::fail('Expected BadRequestException');
        } catch (BadRequestException $e) {
            self::assertSame(400, $e->status);
            self::assertCount(1, $mock->getRequests(), 'must not retry on 400');
        }
    }

    public function testRetriesOnNetworkErrorAndThrowsConnectionExceptionWhenExhausted(): void
    {
        [$client, $mock, $factory] = $this->makeClientWithMock(maxRetries: 1);
        $networkException = $this->makeNetworkException($factory);
        $mock->addException($networkException);
        $mock->addException($networkException);

        try {
            $client->render->preview(new ProjectModeInput(project: 'p', template: 't', data: []));
            self::fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            self::assertSame(PoliPageException::NETWORK_ERROR, $e->errorCode);
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
            $factory->createResponse(200)->withBody($factory->createStream(json_encode([
                'html' => '', 'totalPages' => 0, 'environment' => 'sandbox',
            ], flags: JSON_THROW_ON_ERROR))),
        );

        $client->render->preview(new ProjectModeInput(project: 'p', template: 't', data: []));

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
            $client->render->preview(new ProjectModeInput(project: 'p', template: 't', data: []));
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
            $factory->createResponse(200)->withBody($factory->createStream(json_encode([
                'html' => '', 'totalPages' => 0, 'environment' => 'sandbox',
            ], flags: JSON_THROW_ON_ERROR))),
        );

        $result = $client->render->preview(new ProjectModeInput(project: 'p', template: 't', data: []));

        // If the hook exception had escaped, this assertion would never run.
        self::assertSame('', $result->html);
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
            apiKey: 'pp_test_xyz',
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

    private function makeNetworkException(Psr17Factory $factory): NetworkExceptionInterface
    {
        return new class($factory->createRequest('POST', 'https://api.poli.page/v1/render/preview')) extends \RuntimeException implements NetworkExceptionInterface {
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
