<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoliPage\Events\RetryEvent;
use PoliPage\Exception\InvalidResponseException;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\RenderInput;
use PoliPage\ThumbnailOptions;

/**
 * A 2xx answer whose body is not JSON, from a method that expects JSON, is
 * `invalid_response`: the real 2xx status, the answer's `x-request-id`, the
 * message `Invalid response: HTTP <status> body is not JSON`, never retried,
 * and `toPayload()` answers 502 for it.
 */
#[CoversClass(PoliPage::class)]
final class InvalidResponseTest extends TestCase
{
    private Psr17Factory $factory;

    private MockClient $mock;

    /** @var list<PoliPageException> */
    private array $errors = [];

    /** @var list<RetryEvent> */
    private array $retries = [];

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->mock = new MockClient();
    }

    /**
     * @return iterable<string, array{\Closure(PoliPage): mixed}>
     */
    public static function jsonMethods(): iterable
    {
        $input = new RenderInput(project: 'billing', template: 'invoice', data: []);
        yield 'render.document' => [static fn (PoliPage $c): mixed => $c->render->document($input)];
        yield 'render.pdf' => [static fn (PoliPage $c): mixed => $c->render->pdf($input)];
        yield 'render.pdfStream' => [static fn (PoliPage $c): mixed => $c->render->pdfStream($input)];
        yield 'documents.get' => [static fn (PoliPage $c): mixed => $c->documents->get('doc_abc')];
        yield 'documents.thumbnails' => [
            static fn (PoliPage $c): mixed => $c->documents->thumbnails('doc_abc', new ThumbnailOptions(width: 200)),
        ];
    }

    /**
     * @param \Closure(PoliPage): mixed $call
     */
    #[DataProvider('jsonMethods')]
    public function testA200HtmlBodyIsInvalidResponseAndIsNotRetried(\Closure $call): void
    {
        $this->mock->addResponse($this->factory->createResponse(200)
            ->withHeader('content-type', 'text/html')
            ->withHeader('x-request-id', 'req_invalid_response')
            ->withBody($this->factory->createStream('<html><body><h1>Welcome</h1></body></html>')));

        $error = $this->failure($call);

        self::assertInstanceOf(InvalidResponseException::class, $error);
        self::assertSame(PoliPageException::INVALID_RESPONSE, $error->errorCode);
        self::assertSame('Invalid response: HTTP 200 body is not JSON', $error->getMessage());
        self::assertSame(200, $error->status);
        self::assertSame('req_invalid_response', $error->requestId);
        self::assertInstanceOf(\JsonException::class, $error->getPrevious());
        self::assertFalse($error->isRetryable());
        self::assertFalse($error->isNetworkError());
        self::assertSame(
            [
                'code' => 'invalid_response',
                'message' => 'Invalid response: HTTP 200 body is not JSON',
                'status' => 502,
                'requestId' => 'req_invalid_response',
            ],
            $error->toPayload(),
        );
        self::assertCount(1, $this->mock->getRequests(), 'not retried, with maxRetries 2');
        self::assertSame([], $this->retries);
        self::assertSame([$error], $this->errors, 'onError fires once with the thrown error');
    }

    public function testTheMessageCarriesTheReal2xxAndNoRequestIdIsNull(): void
    {
        $this->mock->addResponse($this->factory->createResponse(201)->withBody($this->factory->createStream('')));

        $error = $this->failure(static fn (PoliPage $c): mixed => $c->documents->get('doc_abc'));

        self::assertSame(PoliPageException::INVALID_RESPONSE, $error->errorCode);
        self::assertSame('Invalid response: HTTP 201 body is not JSON', $error->getMessage());
        self::assertSame(201, $error->status);
        self::assertNull($error->requestId);
        self::assertSame(502, $error->toPayload()['status']);
    }

    public function testMethodsThatExpectNoJsonAcceptANonJsonBody(): void
    {
        $this->mock->addResponse($this->factory->createResponse(200)
            ->withHeader('x-document-page-count', '2')
            ->withBody($this->factory->createStream('<html>preview</html>')));
        $this->mock->addResponse($this->factory->createResponse(200)
            ->withBody($this->factory->createStream('<html>not json</html>')));

        $client = $this->client();
        self::assertSame('<html>preview</html>', $client->documents->preview('doc_abc')->html);
        $client->documents->delete('doc_abc');

        self::assertSame([], $this->errors);
    }

    /**
     * @param \Closure(PoliPage): mixed $call
     */
    private function failure(\Closure $call): PoliPageException
    {
        try {
            $call($this->client());
        } catch (PoliPageException $e) {
            return $e;
        }
        self::fail('Expected invalid_response');
    }

    private function client(): PoliPage
    {
        return new PoliPage(
            apiKey: 'pp_sandbox_x',
            maxRetries: 2,
            retryDelay: 0.0,
            httpClient: $this->mock,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
            onRetry: function (RetryEvent $event): void {
                $this->retries[] = $event;
            },
            onError: function (PoliPageException $error): void {
                $this->errors[] = $error;
            },
        );
    }
}
