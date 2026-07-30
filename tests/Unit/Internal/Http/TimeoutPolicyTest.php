<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Internal\Http;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\Http\TimeoutPolicy;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;

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
}
