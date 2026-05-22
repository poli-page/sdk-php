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

    public function testIsTimeoutDetectsCurlError28InGuzzleConnectException(): void
    {
        if (!class_exists(\GuzzleHttp\Exception\ConnectException::class)) {
            self::markTestSkipped('guzzlehttp/guzzle not installed in this matrix combination');
        }
        $request = (new Psr17Factory())->createRequest('GET', 'https://example.test/x');
        $exception = new \GuzzleHttp\Exception\ConnectException(
            'cURL error 28: Operation timed out',
            $request,
            null,
            ['errno' => 28, 'error' => 'Operation timed out'],
        );

        self::assertTrue(TimeoutPolicy::isTimeout($exception));
    }

    public function testIsTimeoutReturnsFalseForGuzzleConnectExceptionWithDifferentErrno(): void
    {
        if (!class_exists(\GuzzleHttp\Exception\ConnectException::class)) {
            self::markTestSkipped('guzzlehttp/guzzle not installed in this matrix combination');
        }
        $request = (new Psr17Factory())->createRequest('GET', 'https://example.test/x');
        $exception = new \GuzzleHttp\Exception\ConnectException(
            'cURL error 6: Could not resolve host',
            $request,
            null,
            ['errno' => 6, 'error' => 'Could not resolve host'],
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
