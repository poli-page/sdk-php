<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\RenderInput;
use PoliPage\Tests\Support\DocumentWire;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * An apiKey or idempotencyKey that is not a valid HTTP header value must
 * fail as a PoliPageException that never carries the value. Both stacks matter:
 * Guzzle PSR-7 rejects the value in withHeader() (and guzzlehttp/psr7 2.x
 * echoes it in that message), while nyholm/psr7 lets a trailing "\n"
 * through and Symfony HttpClient then echoes the whole header in a
 * transport error.
 */
#[CoversClass(PoliPage::class)]
final class PoliPageHeaderValueTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef';
    private const VALID_KEY = 'pp_sandbox_' . self::SECRET;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidKeyOnEachStack(): iterable
    {
        $keys = [
            'trailing LF' => self::VALID_KEY . "\n",
            'CRLF inside' => 'pp_sandbox_0123456789abcdef' . "\r\n" . '0123456789abcdef',
            'trailing CRLF' => self::VALID_KEY . "\r\n",
            'NUL' => self::VALID_KEY . "\0",
            'DEL' => self::VALID_KEY . "\x7F",
        ];
        foreach (self::stacks() as $stack) {
            foreach ($keys as $name => $key) {
                yield "{$name} on {$stack}" => [$stack, $key];
            }
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidIdempotencyKeyOnEachStack(): iterable
    {
        $keys = [
            'trailing LF' => 'idem-' . self::SECRET . "\n",
            'CRLF inside' => 'idem-' . self::SECRET . "\r\nX-Injected: 1",
            'NUL' => 'idem-' . self::SECRET . "\0",
        ];
        foreach (self::stacks() as $stack) {
            foreach ($keys as $name => $key) {
                yield "{$name} on {$stack}" => [$stack, $key];
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function eachStack(): iterable
    {
        foreach (self::stacks() as $stack) {
            yield $stack => [$stack];
        }
    }

    #[DataProvider('invalidKeyOnEachStack')]
    public function testKeyThatIsNotAValidHeaderValueFailsWithAFixedMessageBeforeAnyRequest(
        string $stack,
        string $apiKey,
    ): void {
        $errors = [];
        $requestEvents = 0;
        [$client, $sentCount] = $this->makeClient(
            $stack,
            $apiKey,
            onError: static function (PoliPageException $e) use (&$errors): void {
                $errors[] = $e;
            },
            onRequest: static function () use (&$requestEvents): void {
                ++$requestEvents;
            },
        );

        $error = $this->renderAndCatch($client, new RenderInput(project: 'p', template: 't', data: []));

        self::assertSame(PoliPageException::INVALID_OPTIONS, $error->errorCode);
        self::assertSame(
            'apiKey contains characters that are not allowed in an HTTP header '
            . '(such as a trailing newline or another control character)',
            $error->getMessage(),
        );
        self::assertNull($error->status);
        self::assertNull($error->getPrevious());
        self::assertKeyNeverSurfaces($error, $apiKey);
        self::assertSame([$error], $errors, 'onError fires once, with the thrown exception');
        self::assertSame(0, $requestEvents, 'no request is built');
        self::assertSame(0, $sentCount());
    }

    #[DataProvider('invalidIdempotencyKeyOnEachStack')]
    public function testIdempotencyKeyThatIsNotAValidHeaderValueFailsWithAFixedMessageBeforeAnyRequest(
        string $stack,
        string $idempotencyKey,
    ): void {
        $errors = [];
        $requestEvents = 0;
        [$client, $sentCount] = $this->makeClient(
            $stack,
            self::VALID_KEY,
            onError: static function (PoliPageException $e) use (&$errors): void {
                $errors[] = $e;
            },
            onRequest: static function () use (&$requestEvents): void {
                ++$requestEvents;
            },
        );

        $error = $this->renderAndCatch($client, new RenderInput(
            project: 'p',
            template: 't',
            data: [],
            idempotencyKey: $idempotencyKey,
        ));

        self::assertSame(PoliPageException::INVALID_OPTIONS, $error->errorCode);
        self::assertSame(
            'idempotencyKey contains characters that are not allowed in an HTTP header '
            . '(such as a trailing newline or another control character)',
            $error->getMessage(),
        );
        self::assertNull($error->status);
        self::assertNull($error->getPrevious());
        self::assertKeyNeverSurfaces($error, $idempotencyKey);
        self::assertSame([$error], $errors, 'onError fires once, with the thrown exception');
        self::assertSame(0, $requestEvents, 'no request is built');
        self::assertSame(0, $sentCount());
    }

    public function testHeaderValueRejectedByAStricterPsr7IsRethrownWithoutTheOriginalMessage(): void
    {
        // Why: "\xFF" passes the RFC 7230 check, but some PSR-7
        // implementations reject it in withHeader() and echo the value.
        $idempotencyKey = "idem-\xFF-" . self::SECRET;
        $errors = [];
        $factory = new Psr17Factory();
        $strictRequests = new class () implements RequestFactoryInterface {
            public function createRequest(string $method, $uri): RequestInterface
            {
                return new class ($method, $uri) extends GuzzleRequest {
                    /**
                     * @param string          $name
                     * @param string|string[] $value
                     */
                    public function withHeader($name, $value): MessageInterface
                    {
                        if (is_string($value) && preg_match('/[^\x09\x20-\x7E]/', $value) === 1) {
                            throw new \InvalidArgumentException(sprintf('"%s" is not valid header value', $value));
                        }

                        return parent::withHeader($name, $value);
                    }
                };
            }
        };
        $client = new PoliPage(
            apiKey: self::VALID_KEY,
            maxRetries: 2,
            retryDelay: 0.0,
            httpClient: new Psr18Client(new MockHttpClient(static function (): MockResponse {
                self::fail('No request may reach the transport');
            }), $factory, $factory),
            requestFactory: $strictRequests,
            streamFactory: $factory,
            onError: static function (PoliPageException $e) use (&$errors): void {
                $errors[] = $e;
            },
            jitterSource: static fn (): float => 0.0,
        );

        $error = $this->renderAndCatch($client, new RenderInput(
            project: 'p',
            template: 't',
            data: [],
            idempotencyKey: $idempotencyKey,
        ));

        self::assertSame(PoliPageException::INVALID_OPTIONS, $error->errorCode);
        self::assertSame(
            'A request header value was rejected by the PSR-7 implementation '
            . '(apiKey or idempotencyKey contains a byte it does not accept)',
            $error->getMessage(),
        );
        self::assertNull($error->getPrevious());
        self::assertKeyNeverSurfaces($error, $idempotencyKey);
        self::assertSame([$error], $errors, 'onError fires once, with the thrown exception');
    }

    #[DataProvider('eachStack')]
    public function testValidKeyStillSendsTheBearerHeaderAndRenders(string $stack): void
    {
        $authorization = null;
        [$client, $sentCount] = $this->makeClient(
            $stack,
            self::VALID_KEY,
            onSend: static function (string $header) use (&$authorization): void {
                $authorization = $header;
            },
        );

        $result = $client->render->document(new RenderInput(project: 'p', template: 't', data: []));

        self::assertSame('doc_abc', $result->documentId);
        self::assertSame(1, $sentCount());
        self::assertSame('Bearer ' . self::VALID_KEY, $authorization);
    }

    /**
     * @return list<string>
     */
    private static function stacks(): array
    {
        return ['guzzle-psr7', 'nyholm-psr7+symfony-psr18'];
    }

    private static function assertKeyNeverSurfaces(PoliPageException $error, string $secret): void
    {
        $payload = json_encode($error->toPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString(self::SECRET, $payload);
        self::assertStringNotContainsString(trim($secret), $payload);
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            self::assertStringNotContainsString(trim($secret), $e->getMessage());
        }
    }

    private function renderAndCatch(PoliPage $client, RenderInput $input): PoliPageException
    {
        try {
            $client->render->document($input);
        } catch (PoliPageException $e) {
            return $e;
        }
        self::fail('Expected a PoliPageException');
    }

    /**
     * @param (\Closure(PoliPageException): void)|null $onError
     * @param (\Closure(): void)|null                   $onRequest
     * @param (\Closure(string): void)|null             $onSend    receives the Authorization header the transport got
     *
     * @return array{0: PoliPage, 1: \Closure(): int}
     */
    private function makeClient(
        string $stack,
        string $apiKey,
        ?\Closure $onError = null,
        ?\Closure $onRequest = null,
        ?\Closure $onSend = null,
    ): array {
        $sent = 0;
        $body = DocumentWire::descriptorJson();

        if ($stack === 'guzzle-psr7') {
            $factory = new HttpFactory();
            $httpClient = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([
                static function (RequestInterface $request) use (&$sent, $onSend, $body): Response {
                    ++$sent;
                    if ($onSend !== null) {
                        $onSend($request->getHeaderLine('Authorization'));
                    }

                    return new Response(200, ['Content-Type' => 'application/json'], $body);
                },
            ]))]);
        } else {
            $factory = new Psr17Factory();
            $respond = static function (string $method, string $url, array $options) use (&$sent, $onSend, $body): MockResponse {
                ++$sent;
                if ($onSend !== null) {
                    $headers = $options['headers'];
                    self::assertIsArray($headers);
                    foreach ($headers as $line) {
                        if (is_string($line) && str_starts_with($line, 'Authorization: ')) {
                            $onSend(substr($line, strlen('Authorization: ')));
                        }
                    }
                }

                return new MockResponse($body, ['http_code' => 200]);
            };
            $httpClient = new Psr18Client(new MockHttpClient($respond), $factory, $factory);
        }

        $client = new PoliPage(
            apiKey: $apiKey,
            maxRetries: 2,
            retryDelay: 0.0,
            httpClient: $httpClient,
            requestFactory: $factory,
            streamFactory: $factory,
            onError: $onError,
            onRequest: $onRequest,
            jitterSource: static fn (): float => 0.0,
        );

        return [$client, static function () use (&$sent): int {
            return $sent;
        }];
    }
}
