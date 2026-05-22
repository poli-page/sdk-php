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
 * Timeout-vs-network detection on caught exceptions runs on the same
 * client-specific knowledge: Guzzle's `ConnectException` carries a
 * `getHandlerContext()['errno']` of 28 (CURLE_OPERATION_TIMEDOUT) when
 * cURL itself reports a timeout.
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
        // Guzzle: cURL error 28 = CURLE_OPERATION_TIMEDOUT
        if (class_exists(\GuzzleHttp\Exception\ConnectException::class)
            && $e instanceof \GuzzleHttp\Exception\ConnectException
        ) {
            $context = $e->getHandlerContext();
            $errno = $context['errno'] ?? null;
            if (is_int($errno) && $errno === 28) {
                return true;
            }
        }
        // Fallback: message-text sniffing covers Symfony's TransportException,
        // php-http/curl-client, and any client that surfaces a recognisable
        // timeout string. Best-effort only.
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'timed out')
            || str_contains($msg, 'timeout')
            || str_contains($msg, 'operation timed out');
    }
}
