<?php

declare(strict_types=1);

namespace PoliPage\Internal\Http;

use PoliPage\PoliPageException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Per-request transport options on top of a PSR-18 client: the per-attempt
 * timeout, and no hidden resend of a POST.
 *
 * PSR-18 deliberately does not standardise per-request options (the spec
 * defers to the implementation). To honour the cross-SDK contract
 * (`timeout` is a per-attempt client option that also bounds the PDF
 * download), this helper detects the underlying client and routes through
 * its native per-request options API:
 *
 *  - **Guzzle 7 / 8** — `$client->send($request, ['timeout' => $s])`
 *    (Guzzle's pre-PSR-18 path), which honours per-request options.
 *
 *  - **Symfony HttpClient** (`Psr18Client`, 6.2 or later) — a clone made by
 *    `withOptions()` with `max_duration` (the total bound, like Guzzle's
 *    `timeout`) and `timeout` (Symfony's idle timeout) set to the attempt's
 *    timeout, made once per client and option set.
 *
 * **Idle timeout.** The body of `render->pdfStream()` is read at the
 * caller's pace, so its download is bounded by an idle timeout instead of a
 * total: the wait for the first byte, then each gap between two chunks. The
 * wait for the headers and the wait for the first byte of the body share
 * one budget, from the start of the request ({@see FirstByteDeadline}).
 * Symfony gets `timeout` without `max_duration`. Guzzle gets `stream` (its
 * default handler stack then reads the body lazily, through its stream
 * handler) and `timeout`, the wait for the answer; Guzzle 8 also gets
 * `read_timeout` for each gap, which Guzzle 7's stream handler bounds with
 * `timeout` itself. A Guzzle handler stack without the stream handler (PHP's
 * `allow_url_fopen` off, say) buffers the whole body before `send()`
 * returns, and `timeout` then bounds that whole download. Through the
 * stream handler, cURL options of the client do not apply.
 *
 * With a Guzzle stack that has the stream handler only (no cURL), the
 * whole-download bound is per read rather than a total on Guzzle 7, whose
 * stream handler has no total timeout.
 *
 * A timeout of 0 or less: the SDK sets no timeout and the client's own
 * default applies (none for Guzzle over cURL, nor for Guzzle 8's stream
 * handler; PHP's `default_socket_timeout` for Symfony and for Guzzle 7's
 * stream handler).
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

    /**
     * Guzzle 8's `stream_factory` request option, by name: Guzzle 7's
     * `RequestOptions` has no such constant.
     */
    private const GUZZLE_STREAM_FACTORY = 'stream_factory';

    /** PSR-7 streams that hold a PHP stream resource and nothing else. */
    private const PLAIN_STREAMS = ['GuzzleHttp\\Psr7\\Stream', 'Nyholm\\Psr7\\Stream'];

    /** The most `withOptions()` clones kept per Symfony client. */
    public const MAX_SYMFONY_CLONES = 16;

    /** @var \WeakMap<object, mixed>|null client => (serialized options => clone) */
    private static ?\WeakMap $symfonyClones = null;

    /**
     * @param bool                        $idle     `$timeoutSeconds` is an idle timeout (the wait for
     *                                              the answer and each gap between two chunks)
     *                                              instead of a bound on the whole exchange: for a
     *                                              body the caller streams
     * @param StreamResourceRecorder|null $recorder with `$idle`, gets the PHP stream under the body
     *                                              from Guzzle 8 ({@see streamedBody()})
     */
    public static function send(
        ClientInterface $client,
        RequestInterface $request,
        ?float $timeoutSeconds,
        bool $idle = false,
        ?StreamResourceRecorder $recorder = null,
    ): ResponseInterface {
        $freshConnection = $request->getMethod() === 'POST' && \defined('CURLOPT_FRESH_CONNECT');
        // A timeout of 0 or less: no SDK timeout. Guzzle reads 0 as none, and
        // Symfony gets no timeout option at all (it would read 0 as an
        // immediate timeout).
        if ($timeoutSeconds !== null && $timeoutSeconds <= 0) {
            $timeoutSeconds = null;
        }

        if (class_exists(\GuzzleHttp\Client::class) && $client instanceof \GuzzleHttp\Client) {
            // Guzzle's native send() accepts per-request options. The PSR-18
            // path (sendRequest) ignores them, so use send() directly here.
            $options = [
                \GuzzleHttp\RequestOptions::TIMEOUT => $timeoutSeconds ?? 0,
                \GuzzleHttp\RequestOptions::HTTP_ERRORS => false,
                \GuzzleHttp\RequestOptions::ALLOW_REDIRECTS => false,
            ];
            if ($idle) {
                $options[\GuzzleHttp\RequestOptions::STREAM] = true;
                // Why not on Guzzle 7: its stream handler applies only a tenth
                // of `read_timeout`'s fractional part (0.5 s waits 50 ms). Its
                // `timeout` there is the socket's own timeout, which bounds
                // each read already.
                if (self::guzzleMajorVersion() >= 8) {
                    $options[\GuzzleHttp\RequestOptions::READ_TIMEOUT] = $timeoutSeconds ?? 0;
                    if ($recorder !== null) {
                        $options[self::GUZZLE_STREAM_FACTORY] = $recorder->around(self::guzzleStreamFactory($client));
                    }
                }
            }
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
                if (!$idle) {
                    $options['max_duration'] = $timeoutSeconds;
                }
            }
            if ($freshConnection) {
                // `extra.curl` is read by CurlHttpClient only. Symfony gives it
                // precedence over an `extra.curl` default of the client, which
                // it does not expose.
                $options['extra'] = ['curl' => [\CURLOPT_FRESH_CONNECT => true]];
            }
            if ($options !== []) {
                $client = self::symfonyClient($client, $options);
            }
        }

        return $client->sendRequest($request);
    }

    /**
     * The body of a streamed download, with the PHP stream resource under it
     * when the client exposes one, so that {@see FirstByteDeadline} can bound
     * the wait for its first byte: only for the clients the SDK applies its
     * timeout to (Guzzle and Symfony).
     *
     * - Guzzle 8 gives it to the recorder passed to {@see send()}.
     * - Guzzle 7 and Symfony's `Psr18Client` wrap it in a plain PSR-7 stream
     *   (guzzlehttp/psr7's or nyholm/psr7's). The body is then rebuilt around
     *   the same resource with `$streamFactory`, so nothing is read or lost.
     *   A body these clients decorate (Guzzle 7's decoding of a compressed
     *   answer, say) is left as it is: its first byte keeps the idle timeout.
     *
     * @return array{StreamInterface, resource|null}
     */
    public static function streamedBody(
        ClientInterface $client,
        StreamInterface $body,
        StreamResourceRecorder $recorder,
        StreamFactoryInterface $streamFactory,
    ): array {
        $recorded = $recorder->resource();
        if (\is_resource($recorded)) {
            return [$body, $recorded];
        }
        if (!self::appliesTimeout($client) || !\in_array($body::class, self::PLAIN_STREAMS, true)) {
            return [$body, null];
        }
        $resource = $body->detach();
        if (!\is_resource($resource)) {
            return [$body, null];
        }

        return [$streamFactory->createStreamFromResource($resource), $resource];
    }

    /**
     * Whether {@see send()} applies the timeout to this client: Guzzle and
     * Symfony's `Psr18Client`. Any other PSR-18 client gets none from the SDK.
     */
    public static function appliesTimeout(ClientInterface $client): bool
    {
        return (class_exists(\GuzzleHttp\Client::class) && $client instanceof \GuzzleHttp\Client)
            || (class_exists(\Symfony\Component\HttpClient\Psr18Client::class)
                && $client instanceof \Symfony\Component\HttpClient\Psr18Client);
    }

    /**
     * Rejects, at construction, an HTTP client the SDK cannot apply its
     * per-request options to: a Symfony `Psr18Client` without `withOptions()`
     * (before 6.2). composer.json conflicts with those versions; an install
     * that bypassed the conflict gets `invalid_options` (SDK spec §4.1), never
     * a raw `\Error` later or a request without its timeout.
     */
    public static function checkClient(ClientInterface $client): void
    {
        if (class_exists(\Symfony\Component\HttpClient\Psr18Client::class)
            && $client instanceof \Symfony\Component\HttpClient\Psr18Client
            && !self::hasMethod($client, 'withOptions')
        ) {
            throw new PoliPageException(
                'symfony/http-client 6.2 or later is required: Psr18Client::withOptions() is missing',
                PoliPageException::INVALID_OPTIONS,
            );
        }
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
     * pool across the SDK's calls. Each client keeps at most
     * {@see self::MAX_SYMFONY_CLONES}, the least recently used evicted first:
     * per-call timeouts that keep changing (the time left before a deadline,
     * say) would otherwise pile up clones, and on 6.x each holds its own
     * pool and keep-alive sockets.
     *
     * @internal public for its unit test
     *
     * @param array<string, mixed> $options
     */
    public static function symfonyClient(
        \Symfony\Component\HttpClient\Psr18Client $client,
        array $options,
    ): \Symfony\Component\HttpClient\Psr18Client {
        $map = self::$symfonyClones ?? new \WeakMap();
        self::$symfonyClones = $map;
        $key = serialize($options);
        $clones = self::clones($map[$client] ?? null);
        $clone = $clones[$key] ?? $client->withOptions($options);
        // Most recently used last: re-insert the key at the end.
        unset($clones[$key]);
        $clones[$key] = $clone;
        if (count($clones) > self::MAX_SYMFONY_CLONES) {
            array_shift($clones);
        }
        $map[$client] = $clones;

        return $clone;
    }

    /**
     * How many clones a client holds.
     *
     * @internal for the unit test of {@see symfonyClient()}
     */
    public static function symfonyCloneCount(\Symfony\Component\HttpClient\Psr18Client $client): int
    {
        return count(self::clones(self::$symfonyClones[$client] ?? null));
    }

    /**
     * @return array<string, \Symfony\Component\HttpClient\Psr18Client>
     */
    private static function clones(mixed $entry): array
    {
        $clones = [];
        foreach (is_array($entry) ? $entry : [] as $key => $clone) {
            if ($clone instanceof \Symfony\Component\HttpClient\Psr18Client) {
                $clones[(string) $key] = $clone;
            }
        }

        return $clones;
    }

    /**
     * The stream factory a Guzzle client builds its response bodies with:
     * its own `stream_factory` option, else Guzzle's default.
     */
    private static function guzzleStreamFactory(\GuzzleHttp\Client $client): StreamFactoryInterface
    {
        $configured = self::hasMethod($client, 'getConfig')
            ? $client->getConfig(self::GUZZLE_STREAM_FACTORY)
            : null;

        return $configured instanceof StreamFactoryInterface ? $configured : new \GuzzleHttp\Psr7\HttpFactory();
    }

    /**
     * Guzzle's major version, read at run time so the check holds whatever
     * Guzzle version static analysis sees.
     */
    private static function guzzleMajorVersion(): int
    {
        $version = self::constantValue(\GuzzleHttp\ClientInterface::class . '::MAJOR_VERSION');

        return is_int($version) ? $version : 0;
    }

    private static function constantValue(string $name): mixed
    {
        return \defined($name) ? \constant($name) : null;
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
