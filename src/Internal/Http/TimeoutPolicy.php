<?php

declare(strict_types=1);

namespace PoliPage\Internal\Http;

use PoliPage\PoliPageException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Per-request transport options on top of a PSR-18 client: the per-attempt
 * timeout, and no hidden resend of a POST.
 *
 * PSR-18 deliberately does not standardise per-request options (the spec
 * defers to the implementation). To honour the cross-SDK contract
 * (`timeout` is a per-attempt client option, SDK spec §4.1 and §9.4), this
 * helper detects the underlying client and routes through its native
 * per-request options API:
 *
 *  - **Guzzle 7 / 8** — `$client->send($request, ['timeout' => $s])`
 *    (Guzzle's pre-PSR-18 path), which honours per-request options.
 *
 *  - **Symfony HttpClient** (`Psr18Client`, 6.2 or later) — a clone made by
 *    `withOptions()` with `max_duration` (the total bound, like Guzzle's
 *    `timeout`) and `timeout` (Symfony's idle timeout) set to the attempt's
 *    timeout, made once per client and option set.
 *
 *  - **Other PSR-18 clients** — the timeout cannot be applied per request
 *    through the PSR-18 contract: configure it on the client you inject.
 *    The request goes through `$client->sendRequest($request)`.
 *
 * **No hidden resend.** libcurl, under Guzzle's curl handler and under
 * Symfony's `CurlHttpClient`, silently sends a request again on a fresh
 * connection when it went out on a reused keep-alive connection that died
 * before any answer. For a POST that resend is invisible to the SDK: not
 * counted by `maxRetries`, not reported to `onRequest`, and, since the API
 * does not deduplicate on `Idempotency-Key`, able to store a render twice. A
 * POST therefore always opens a fresh connection (`CURLOPT_FRESH_CONNECT`)
 * with Guzzle and with Symfony, so a dead connection surfaces as
 * `network_error` and goes through the SDK's own retry. A GET or DELETE may
 * still be resent once by libcurl, which stores nothing. With Symfony, the
 * option replaces any `extra.curl` default of the client for the SDK's POSTs.
 *
 * Timeout-vs-network detection: an exception, or one it wraps, whose class
 * name says `Timeout` (Guzzle 8's typed exceptions, Symfony's
 * `TimeoutException`), or whose message carries a timeout phrase of the
 * supported clients: "timed out" (cURL error 28, under Guzzle and Symfony's
 * curl client), "timeout reached" (Symfony's idle timeout), "max duration"
 * (Symfony's total bound). The SDK's constraint spans
 * `guzzlehttp/guzzle ^7.8 || ^8.0`, which do not share a typed-timeout API.
 *
 * @internal
 */
final class TimeoutPolicy
{
    private const TIMEOUT_PHRASES = ['timed out', 'timeout reached', 'max duration'];

    /** @var \WeakMap<object, mixed>|null client => (serialized options => clone) */
    private static ?\WeakMap $symfonyClones = null;

    public static function send(
        ClientInterface $client,
        RequestInterface $request,
        ?float $timeoutSeconds,
    ): ResponseInterface {
        $freshConnection = $request->getMethod() === 'POST' && \defined('CURLOPT_FRESH_CONNECT');

        if ($timeoutSeconds !== null
            && class_exists(\GuzzleHttp\Client::class)
            && $client instanceof \GuzzleHttp\Client
        ) {
            // Guzzle's native send() accepts per-request options. The PSR-18
            // path (sendRequest) ignores them, so use send() directly here.
            $options = [
                \GuzzleHttp\RequestOptions::TIMEOUT => $timeoutSeconds,
                \GuzzleHttp\RequestOptions::HTTP_ERRORS => false,
                \GuzzleHttp\RequestOptions::ALLOW_REDIRECTS => false,
            ];
            if ($freshConnection) {
                // Read by Guzzle's curl handler only; its stream handler opens
                // a connection per request anyway. Guzzle merges request
                // options over the client's shallowly, so the client's own
                // `curl` options are carried over. Why the method check:
                // Guzzle 7 marks getConfig() deprecated for removal.
                $clientCurl = self::hasMethod($client, 'getConfig') ? $client->getConfig('curl') : null;
                $options['curl'] = [\CURLOPT_FRESH_CONNECT => true] + (is_array($clientCurl) ? $clientCurl : []);
            }

            return $client->send($request, $options);
        }

        if (class_exists(\Symfony\Component\HttpClient\Psr18Client::class)
            && $client instanceof \Symfony\Component\HttpClient\Psr18Client
        ) {
            $options = [];
            if ($timeoutSeconds !== null) {
                $options['timeout'] = $timeoutSeconds;
                $options['max_duration'] = $timeoutSeconds;
            }
            if ($freshConnection) {
                // `extra.curl` is read by CurlHttpClient only. Symfony gives it
                // precedence over an `extra.curl` default of the client, which
                // it does not expose.
                $options['extra'] = ['curl' => [\CURLOPT_FRESH_CONNECT => true]];
            }
            if ($options !== []) {
                if (!self::hasMethod($client, 'withOptions')) {
                    // composer.json conflicts with symfony/http-client < 6.2;
                    // an install that bypassed it gets the SDK's error, never
                    // a raw \Error, and never a request without its timeout.
                    throw new PoliPageException(
                        'symfony/http-client 6.2 or later is required: Psr18Client::withOptions() is missing',
                        PoliPageException::INVALID_OPTIONS,
                    );
                }
                $client = self::symfonyClient($client, $options);
            }
        }

        return $client->sendRequest($request);
    }

    /**
     * Heuristic: detect whether a caught transport exception, or a failure
     * while the body was read, represents a timeout vs a generic network
     * failure. Used by the retry loop + exception classifier to throw the
     * right `PoliPageException` subclass.
     */
    public static function isTimeout(\Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            // An anonymous class's name holds its file path: not a type name.
            $class = new \ReflectionClass($cause);
            if (!$class->isAnonymous() && str_contains($class->getShortName(), 'Timeout')) {
                return true;
            }
            $message = strtolower($cause->getMessage());
            foreach (self::TIMEOUT_PHRASES as $phrase) {
                if (str_contains($message, $phrase)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The client's `withOptions()` clone for these options, made once.
     *
     * Why: symfony/http-client 6.x creates a client's connection pool on its
     * first request, so a clone made per request would get a new pool each
     * time and never reuse a connection. One clone per option set keeps its
     * pool across the SDK's calls.
     *
     * @param array<string, mixed> $options
     */
    private static function symfonyClient(
        \Symfony\Component\HttpClient\Psr18Client $client,
        array $options,
    ): \Symfony\Component\HttpClient\Psr18Client {
        $map = self::$symfonyClones ?? new \WeakMap();
        self::$symfonyClones = $map;
        $key = serialize($options);
        $clones = $map[$client] ?? null;
        $clones = is_array($clones) ? $clones : [];
        $clone = $clones[$key] ?? null;
        if (!$clone instanceof \Symfony\Component\HttpClient\Psr18Client) {
            $clone = $client->withOptions($options);
            $clones[$key] = $clone;
            $map[$client] = $clones;
        }

        return $clone;
    }

    /**
     * Whether `$object` has the method; the parameter is untyped on purpose,
     * so the check holds whatever library version static analysis sees.
     */
    private static function hasMethod(object $object, string $method): bool
    {
        return method_exists($object, $method);
    }
}
