<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use GuzzleHttp\Client as GuzzleClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoliPage\Exception\TimeoutException;
use PoliPage\Internal\Http\TimeoutPolicy;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\Tests\Contract\MockServer;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * The SDK's `timeout` bounds each attempt whatever the HTTP client (SDK spec
 * §4.1, §9.4): against a local server that never answers, an attempt with a
 * 0.2 s timeout ends at once, through clients built with no timeout of their
 * own. The contract cases check timing as lower bounds only, so they cannot
 * tell an applied timeout from the client's own 60 s default.
 *
 * In the unit suite although it starts a server process (the contract
 * suite's MockServer): it guards SDK behaviour and needs no contract file.
 */
#[CoversClass(TimeoutPolicy::class)]
final class AttemptTimeoutTest extends TestCase
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
    public function testTheSdkTimeoutEndsAHungAttempt(string $httpClient): void
    {
        $server = new MockServer([(object) ['fault' => 'hang'], (object) ['fault' => 'hang']]);
        $client = new PoliPage(
            apiKey: 'pp_sandbox_x',
            baseUrl: $server->origin,
            maxRetries: 0,
            timeout: 0.2,
            httpClient: self::httpClient($httpClient),
        );

        $startedAt = hrtime(true);
        try {
            $client->documents->get('doc_abc');
            self::fail('Expected a timeout');
        } catch (TimeoutException $e) {
            self::assertSame(PoliPageException::TIMEOUT, $e->errorCode);
            self::assertSame('Request timed out after 200ms', $e->getMessage());
        } finally {
            $elapsedSeconds = (hrtime(true) - $startedAt) / 1e9;
            $requests = $server->stop()['requests'];
        }

        self::assertLessThan(3.0, $elapsedSeconds, 'the 0.2 s SDK timeout ended the attempt');
        self::assertCount(1, $requests);
    }

    private static function httpClient(string $name): ClientInterface
    {
        return $name === 'guzzle' ? new GuzzleClient() : new Psr18Client();
    }
}
