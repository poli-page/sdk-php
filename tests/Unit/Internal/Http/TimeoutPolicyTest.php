<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Internal\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\Http\StreamResourceRecorder;
use PoliPage\Internal\Http\TimeoutPolicy;
use PoliPage\Tests\Support\FailingStream;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(TimeoutPolicy::class)]
final class TimeoutPolicyTest extends TestCase
{
    public function testSendDelegatesToPsr18WhenTimeoutIsNull(): void
    {
        $factory = new Psr17Factory();
        $mock = new MockClient();
        $mock->addResponse($factory->createResponse(200));

        $response = TimeoutPolicy::send(
            $mock,
            $factory->createRequest('GET', 'https://example.test/x'),
            null,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $mock->getRequests());
    }

    public function testSendDelegatesToPsr18ForNonGuzzleClientsEvenWithTimeoutSet(): void
    {
        $factory = new Psr17Factory();
        $mock = new MockClient();
        $mock->addResponse($factory->createResponse(204));

        $response = TimeoutPolicy::send(
            $mock,
            $factory->createRequest('GET', 'https://example.test/x'),
            5.0,
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testIsTimeoutDetectsGuzzleConnectTimeout(): void
    {
        if (!class_exists(\GuzzleHttp\Exception\ConnectException::class)) {
            self::markTestSkipped('guzzlehttp/guzzle not installed in this matrix combination');
        }
        $request = (new Psr17Factory())->createRequest('GET', 'https://example.test/x');
        // cURL error 28 = CURLE_OPERATION_TIMEDOUT. The message wording is
        // identical across Guzzle 7's ConnectException and Guzzle 8's typed
        // timeout exceptions, so this covers both majors.
        $exception = new \GuzzleHttp\Exception\ConnectException(
            'cURL error 28: Operation timed out after 5000 milliseconds',
            $request,
        );

        self::assertTrue(TimeoutPolicy::isTimeout($exception));
    }

    public function testIsTimeoutReturnsFalseForNonTimeoutGuzzleConnectException(): void
    {
        if (!class_exists(\GuzzleHttp\Exception\ConnectException::class)) {
            self::markTestSkipped('guzzlehttp/guzzle not installed in this matrix combination');
        }
        $request = (new Psr17Factory())->createRequest('GET', 'https://example.test/x');
        $exception = new \GuzzleHttp\Exception\ConnectException(
            'cURL error 6: Could not resolve host',
            $request,
        );

        self::assertFalse(TimeoutPolicy::isTimeout($exception));
    }

    public function testIsTimeoutFallsBackToMessageTextForOtherClients(): void
    {
        $request = (new Psr17Factory())->createRequest('GET', 'https://example.test/x');
        $exception = new class ($request) extends \RuntimeException implements ClientExceptionInterface {
            public function __construct(private readonly RequestInterface $request)
            {
                parent::__construct('Symfony transport: operation timed out');
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };

        self::assertTrue(TimeoutPolicy::isTimeout($exception));
    }

    public function testIsTimeoutReturnsFalseForGenericMessage(): void
    {
        $request = (new Psr17Factory())->createRequest('GET', 'https://example.test/x');
        $exception = new class ($request) extends \RuntimeException implements ClientExceptionInterface {
            public function __construct(private readonly RequestInterface $request)
            {
                parent::__construct('connection refused');
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };

        self::assertFalse(TimeoutPolicy::isTimeout($exception));
    }

    public function testIsTimeoutReadsSymfonysPhrasesAndTheWrappedException(): void
    {
        self::assertTrue(TimeoutPolicy::isTimeout(new \RuntimeException('Idle timeout reached for "http://127.0.0.1/x".')));
        self::assertTrue(TimeoutPolicy::isTimeout(new \RuntimeException('Max duration was reached for "http://127.0.0.1/x".')));
        self::assertTrue(TimeoutPolicy::isTimeout(
            new \RuntimeException('transport failed', 0, new \RuntimeException('cURL error 28: Operation timed out')),
        ));
    }

    public function testIsTimeoutIgnoresAHostNamedTimeout(): void
    {
        self::assertFalse(TimeoutPolicy::isTimeout(new \RuntimeException('Could not resolve host: timeout.example.com')));
    }

    public function testStreamedBodyKeepsTheRecordedResourceAndTheBody(): void
    {
        $factory = new Psr17Factory();
        $recorder = (new StreamResourceRecorder())->around($factory);
        $body = $recorder->createStreamFromResource(self::memory('pdf'));

        [$streamed, $resource] = TimeoutPolicy::streamedBody(new GuzzleClient(), $body, $recorder, $factory);

        self::assertSame($body, $streamed);
        self::assertSame($recorder->resource(), $resource);
    }

    public function testStreamedBodyRebuildsAPlainBodyOfGuzzleOrSymfonyAroundItsResource(): void
    {
        $factory = new Psr17Factory();
        foreach ([new GuzzleClient(), new Psr18Client()] as $client) {
            $memory = self::memory('pdf');
            $body = $factory->createStreamFromResource($memory);

            [$streamed, $resource] = TimeoutPolicy::streamedBody($client, $body, new StreamResourceRecorder(), $factory);

            self::assertSame($memory, $resource);
            self::assertNotSame($body, $streamed);
            self::assertSame('pdf', (string) $streamed, 'nothing read or lost');
        }
    }

    public function testStreamedBodyLeavesOtherClientsAndDecoratedBodiesAlone(): void
    {
        $factory = new Psr17Factory();
        $plain = $factory->createStreamFromResource(self::memory('pdf'));
        [$streamed, $resource] = TimeoutPolicy::streamedBody(new MockClient(), $plain, new StreamResourceRecorder(), $factory);
        self::assertSame($plain, $streamed);
        self::assertNull($resource);

        $decorated = new FailingStream('pdf', new \RuntimeException('x'));
        [$streamed, $resource] = TimeoutPolicy::streamedBody(new GuzzleClient(), $decorated, new StreamResourceRecorder(), $factory);
        self::assertSame($decorated, $streamed);
        self::assertNull($resource);
    }

    /**
     * @return resource
     */
    private static function memory(string $content)
    {
        $memory = fopen('php://memory', 'r+');
        self::assertIsResource($memory);
        fwrite($memory, $content);
        rewind($memory);

        return $memory;
    }

    /**
     * The whole milliseconds a client arms for each timeout handed over,
     * when it drops the fraction as Guzzle (`(int) ($seconds * 1000)`) and
     * cURL's `CURLOPT_TIMEOUT_MS` do: the SDK's timeout rounded up, never
     * less, also where the float of a whole millisecond sits a hair under it
     * (1.003 × 1000 is 1002.999…).
     */
    public function testHandedOverTimeoutsTruncateToTheTimeoutRoundedUp(): void
    {
        for ($ms = 1; $ms <= 120_000; ++$ms) {
            $handedOver = TimeoutPolicy::handedOver($ms / 1000);
            $armed = (int) ($handedOver * 1000);
            if ($armed !== $ms) {
                self::fail(sprintf('%d ms handed over as %.9F s, armed as %d ms', $ms, $handedOver, $armed));
            }
        }
        self::assertSame(1004, self::truncatedMs(TimeoutPolicy::handedOver(1.0031)));
        self::assertSame(1, self::truncatedMs(TimeoutPolicy::handedOver(0.0001)));
    }

    /**
     * @return iterable<string, array{float, int}>
     */
    public static function timeoutsAndWholeMilliseconds(): iterable
    {
        yield '1.003 s' => [1.003, 1003];
        yield '1.0005 s' => [1.0005, 1001];
        yield '0.2509 s' => [0.2509, 251];
        yield '60 s' => [60.0, 60000];
    }

    #[DataProvider('timeoutsAndWholeMilliseconds')]
    public function testGuzzleGetsTheTimeoutRoundedUpToAWholeMillisecond(float $timeout, int $ms): void
    {
        $handler = new MockHandler([new Response(200), new Response(200)]);
        $client = new GuzzleClient(['handler' => HandlerStack::create($handler)]);
        $request = (new Psr17Factory())->createRequest('GET', 'https://example.test/x');

        TimeoutPolicy::send($client, $request, $timeout);
        $options = $handler->getLastOptions();
        self::assertSame($ms, self::truncatedMs($options['timeout'] ?? null), 'the attempt timeout');

        TimeoutPolicy::send($client, $request, $timeout, idle: true);
        $options = $handler->getLastOptions();
        self::assertSame($ms, self::truncatedMs($options['timeout'] ?? null), 'the download timeout');
        if (\array_key_exists('read_timeout', $options)) {
            self::assertSame($ms, self::truncatedMs($options['read_timeout']), 'the idle timeout (Guzzle 8)');
        }
    }

    #[DataProvider('timeoutsAndWholeMilliseconds')]
    public function testSymfonyGetsTheTimeoutRoundedUpToAWholeMillisecond(float $timeout, int $ms): void
    {
        /** @var list<array<mixed>> $sent */
        $sent = [];
        $client = new Psr18Client(new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent[] = $options;

            return new MockResponse('', ['http_code' => 200]);
        }));
        $request = (new Psr17Factory())->createRequest('GET', 'https://example.test/x');

        TimeoutPolicy::send($client, $request, $timeout);
        TimeoutPolicy::send($client, $request, $timeout, idle: true);

        self::assertCount(2, $sent);
        // CurlHttpClient hands `1000 * max_duration` to CURLOPT_TIMEOUT_MS.
        self::assertSame($ms, self::truncatedMs($sent[0]['max_duration'] ?? null), 'the attempt timeout');
        self::assertSame($ms, self::truncatedMs($sent[0]['timeout'] ?? null), 'the idle timeout of an attempt');
        self::assertSame($ms, self::truncatedMs($sent[1]['timeout'] ?? null), 'the download timeout');
        self::assertGreaterThanOrEqual($timeout, $sent[1]['timeout'], 'Symfony keeps the float');
    }

    /**
     * The whole milliseconds a client that drops the fraction arms.
     */
    private static function truncatedMs(mixed $seconds): int
    {
        self::assertTrue(\is_float($seconds) || \is_int($seconds), 'a timeout in seconds');

        return (int) ($seconds * 1000);
    }
}
