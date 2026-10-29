<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use GuzzleHttp\Client as GuzzleClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PoliPage\Exception\ConnectionException;
use PoliPage\Internal\Http\TimeoutPolicy;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\RenderInput;
use PoliPage\Tests\Contract\MockServer;
use PoliPage\Tests\Contract\RecordedRequest;
use PoliPage\Tests\Support\DocumentWire;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * libcurl sends a request again on a fresh connection when it went out on a
 * reused keep-alive connection that died before any answer. For a POST that
 * resend would be invisible to the SDK and, as the API does not deduplicate
 * on Idempotency-Key, could store a render twice. Against a real local
 * server with keep-alive, through Guzzle and Symfony HttpClient.
 */
#[CoversClass(TimeoutPolicy::class)]
#[RequiresPhpExtension('curl')]
final class NoHiddenResendTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function httpClients(): iterable
    {
        yield 'guzzle' => ['guzzle'];
        yield 'symfony' => ['symfony'];
    }

    #[DataProvider('httpClients')]
    public function testAPostOnADeadReusedConnectionIsANetworkErrorNotAHiddenResend(string $httpClient): void
    {
        $server = $this->server();
        $client = $this->client($server, $httpClient);
        $client->documents->get('doc_abc');

        try {
            $client->render->document(new RenderInput(project: 'p', template: 't', data: []));
            self::fail('Expected network_error');
        } catch (ConnectionException $e) {
            self::assertSame(PoliPageException::NETWORK_ERROR, $e->errorCode);
        } finally {
            $requests = $server->stop()['requests'];
        }

        self::assertSame(['GET /v1/documents/doc_abc', 'POST /v1/render'], self::lines($requests));
    }

    /**
     * Control: the same connection really is reused, since libcurl resends a
     * GET (which stores nothing) on it.
     */
    #[DataProvider('httpClients')]
    public function testTheConnectionIsReusedSoAGetIsResent(string $httpClient): void
    {
        $server = $this->server();
        $client = $this->client($server, $httpClient);
        $client->documents->get('doc_abc');
        $client->documents->get('doc_abc');
        $requests = $server->stop()['requests'];

        self::assertSame(
            ['GET /v1/documents/doc_abc', 'GET /v1/documents/doc_abc', 'GET /v1/documents/doc_abc'],
            self::lines($requests),
        );
    }

    private function server(): MockServer
    {
        $answer = json_decode(json_encode(['status' => 200, 'json' => DocumentWire::descriptor()], JSON_THROW_ON_ERROR), false);

        return new MockServer([$answer, (object) ['fault' => 'drop'], $answer], keepAlive: true);
    }

    private function client(MockServer $server, string $httpClient): PoliPage
    {
        return new PoliPage(
            apiKey: 'pp_sandbox_x',
            baseUrl: $server->origin,
            maxRetries: 0,
            timeout: 5.0,
            httpClient: $this->httpClient($httpClient),
        );
    }

    private function httpClient(string $name): ClientInterface
    {
        return $name === 'guzzle' ? new GuzzleClient() : new Psr18Client(HttpClient::create(['timeout' => 5]));
    }

    /**
     * @param list<RecordedRequest> $requests
     *
     * @return list<string>
     */
    private static function lines(array $requests): array
    {
        return array_map(static fn (RecordedRequest $r): string => $r->method . ' ' . $r->target, $requests);
    }
}
