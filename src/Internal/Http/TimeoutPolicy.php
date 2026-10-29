<?php

declare(strict_types=1);

namespace PoliPage\Internal\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Per-request transport options on top of a PSR-18 client: the per-attempt
 * timeout, and no hidden resend of a POST.
 *
 * PSR-18 deliberately does not standardise per-request options (the spec
 * defers to the implementation). To honour the cross-SDK contract
 * (`timeout` is a per-attempt client option, SDK spec §9.4), this helper
 * detects the underlying client and routes through its native per-request
 * options API where one exists:
 *
 *  - **Guzzle 7 / 8** — uses `$client->send($request, ['timeout' => $s])`
 *    (Guzzle's pre-PSR-18 path) which honours per-request options.
 *
 *  - **Other PSR-18 clients** — the timeout cannot be applied
 *    per-request through the PSR-18 contract. Users configure the
 *    timeout on their client at construction; the SDK option is a
 *    fallback documented in the README. The request still goes through
 *    `$client->sendRequest($request)`, so the client's own configured
 *    timeout still applies.
 *
 * **No hidden resend.** libcurl, under Guzzle's curl handler and under
 * Symfony's `CurlHttpClient`, silently sends a request again on a fresh
 * connection when it went out on a reused keep-alive connection that died
 * before any answer. For a POST that resend is invisible to the SDK: not
 * counted by `maxRetries`, not reported to `onRequest`, and, since the API
 * does not deduplicate on `Idempotency-Key`, able to store a render twice. A
 * POST therefore always opens a fresh connection (`CURLOPT_FRESH_CONNECT`)
 * with Guzzle and with Symfony's `Psr18Client`, so a dead connection
 * surfaces as `network_error` and goes through the SDK's own retry. A GET or
 * DELETE may still be resent once by libcurl, which stores nothing. Other
 * PSR-18 clients are sent the request as is. With Symfony, the option
 * replaces any `extra.curl` default of the client for the SDK's POSTs.
 *
 * Timeout-vs-network detection on caught exceptions is done by message
 * text. Across the clients we care about a transport timeout always
 * surfaces a "timed out"/"timeout" string in the exception message:
 * Guzzle (both 7's `ConnectException` and 8's typed timeout exceptions
 * carry "cURL error 28: Operation timed out" / "Connection timed out"),
 * Symfony's `TransportException` ("Idle timeout reached"), and
 * php-http/curl-client. Sniffing the message keeps this to one code path
 * that works identically on every supported client and Guzzle major, with
 * no version-specific API — the SDK's constraint spans
 * `guzzlehttp/guzzle ^7.8 || ^8.0`, which do not share a common
 * typed-timeout API.
 *
 * @internal
 */
final class TimeoutPolicy
{
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
                // `curl` options are carried over.
                $clientCurl = $client->getConfig('curl');
                $options['curl'] = [\CURLOPT_FRESH_CONNECT => true] + (is_array($clientCurl) ? $clientCurl : []);
            }

            return $client->send($request, $options);
        }

        if ($freshConnection
            && class_exists(\Symfony\Component\HttpClient\Psr18Client::class)
            && $client instanceof \Symfony\Component\HttpClient\Psr18Client
        ) {
            // A clone sharing the client's connection pool; `extra.curl` is
            // read by CurlHttpClient only. Symfony gives this `extra.curl`
            // precedence over an `extra.curl` default of the client, which it
            // does not expose, so such a default does not reach the SDK's POSTs.
            $client = $client->withOptions(['extra' => ['curl' => [\CURLOPT_FRESH_CONNECT => true]]]);
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
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'timed out')
            || str_contains($msg, 'timeout');
    }
}
