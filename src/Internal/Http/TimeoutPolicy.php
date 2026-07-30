<?php

declare(strict_types=1);

namespace PoliPage\Internal\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Per-request timeout enforcement on top of a PSR-18 client.
 *
 * PSR-18 deliberately does not standardise per-request timeouts (the
 * spec defers to the implementation). To honour the cross-SDK contract
 * (`timeout` is a required client option per spec v1.3 §9.4), this
 * helper detects the underlying client and routes through its native
 * per-request options API where one exists:
 *
 *  - **Guzzle 7** — uses `$client->send($request, ['timeout' => $s])`
 *    (Guzzle's pre-PSR-18 path) which honours per-request options.
 *
 *  - **Other PSR-18 clients** — the timeout cannot be applied
 *    per-request through the PSR-18 contract. Users configure the
 *    timeout on their client at construction; the SDK option is a
 *    fallback documented in the README. The request still goes through
 *    `$client->sendRequest($request)`, so the client's own configured
 *    timeout still applies.
 *
 * Timeout-vs-network detection on caught exceptions is done by message
 * text. Across the clients we care about a transport timeout always
 * surfaces a "timed out"/"timeout" string in the exception message:
 * Guzzle (both 7's `ConnectException` and 8's typed timeout exceptions
 * carry "cURL error 28: Operation timed out" / "Connection timed out"),
 * Symfony's `TransportException`, and php-http/curl-client. Sniffing the
 * message keeps this to one code path that works identically on every
 * supported client and Guzzle major, with no version-specific API — the
 * SDK's constraint spans `guzzlehttp/guzzle ^7.8 || ^8.0`, which do not
 * share a common typed-timeout API.
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
        if ($timeoutSeconds !== null
            && class_exists(\GuzzleHttp\Client::class)
            && $client instanceof \GuzzleHttp\Client
        ) {
            // Guzzle's native send() accepts per-request options. The PSR-18
            // path (sendRequest) ignores them, so use send() directly here.
            return $client->send($request, [
                \GuzzleHttp\RequestOptions::TIMEOUT => $timeoutSeconds,
                \GuzzleHttp\RequestOptions::HTTP_ERRORS => false,
                \GuzzleHttp\RequestOptions::ALLOW_REDIRECTS => false,
            ]);
        }

        return $client->sendRequest($request);
    }

    /**
     * Heuristic: detect whether a caught transport exception represents
     * a timeout vs a generic network failure. Used by the retry loop +
     * exception classifier to throw the right `PoliPageException`
     * subclass.
     */
    public static function isTimeout(ClientExceptionInterface $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'timed out')
            || str_contains($msg, 'timeout');
    }
}
