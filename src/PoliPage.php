<?php

declare(strict_types=1);

namespace PoliPage;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use PoliPage\Events\RequestEvent;
use PoliPage\Events\ResponseEvent;
use PoliPage\Events\RetryEvent;
use PoliPage\Internal\Constants;
use PoliPage\Internal\ExceptionClassifier;
use PoliPage\Internal\Http\Backoff;
use PoliPage\Internal\Http\BodyReader;
use PoliPage\Internal\Http\DownloadStream;
use PoliPage\Internal\Http\ErrorBodyParser;
use PoliPage\Internal\Http\Headers;
use PoliPage\Internal\Http\RetryAfterParser;
use PoliPage\Internal\Http\SendOnceResult;
use PoliPage\Internal\Http\TextResponse;
use PoliPage\Internal\Http\TimeoutPolicy;
use PoliPage\Internal\Http\UrlBuilder;
use PoliPage\Internal\Transport;
use PoliPage\Internal\Uuid\Uuid4;
use PoliPage\Internal\Version;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Poli Page SDK client — entry point for the namespaced render API.
 *
 * @example
 * ```php
 * use PoliPage\PoliPage;
 * use PoliPage\RenderInput;
 *
 * $client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);
 * $pdf = $client->render->pdf(new RenderInput(
 *     project: 'billing',
 *     template: 'invoice',
 *     data: ['invoiceNumber' => 'INV-001'],
 * ));
 * ```
 */
final class PoliPage implements Transport
{
    /** RFC 7230 field-value; `\z` because `$` also matches before a final "\n". */
    private const HEADER_VALUE_PATTERN = '/^[\x20\x09\x21-\x7E\x80-\xFF]*\z/';

    public readonly Render $render;
    public readonly Documents $documents;

    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly int $maxRetries;
    private readonly float $retryDelay;
    private readonly float $defaultTimeout;
    private readonly ClientInterface $httpClient;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;
    private readonly LoggerInterface $logger;
    /** @var (\Closure(RetryEvent): void)|null */
    private readonly ?\Closure $onRetry;
    /** @var (\Closure(PoliPageException): void)|null */
    private readonly ?\Closure $onError;
    /** @var (\Closure(RequestEvent): void)|null */
    private readonly ?\Closure $onRequest;
    /** @var (\Closure(ResponseEvent): void)|null */
    private readonly ?\Closure $onResponse;
    private readonly string $userAgent;
    /** @var (\Closure(): float)|null */
    private readonly ?\Closure $jitterSource;

    /**
     * @param (\Closure(RetryEvent): void)|null     $onRetry      fires before each retry sleep
     * @param (\Closure(PoliPageException): void)|null $onError   fires once at terminal failure
     * @param (\Closure(RequestEvent): void)|null   $onRequest    fires just before each HTTP attempt
     * @param (\Closure(ResponseEvent): void)|null  $onResponse   fires after each 2xx response, once its body is read
     * @param (\Closure(): float)|null              $jitterSource test hook; default uses mt_rand
     */
    public function __construct(
        string $apiKey,
        ?string $baseUrl = null,
        ?int $maxRetries = null,
        ?float $retryDelay = null,
        ?float $timeout = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
        ?\Closure $onRetry = null,
        ?\Closure $onError = null,
        ?\Closure $onRequest = null,
        ?\Closure $onResponse = null,
        ?\Closure $jitterSource = null,
    ) {
        if ($apiKey === '') {
            throw new PoliPageException(Constants::MESSAGE_INVALID_OPTIONS, PoliPageException::INVALID_OPTIONS);
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl ?? Constants::DEFAULT_BASE_URL;
        $this->maxRetries = $maxRetries ?? Constants::DEFAULT_MAX_RETRIES;
        $this->retryDelay = $retryDelay ?? Constants::DEFAULT_RETRY_DELAY_SECONDS;
        $this->defaultTimeout = $timeout ?? Constants::DEFAULT_TIMEOUT_SECONDS;
        $this->httpClient = $httpClient ?? self::discoverHttpClient();
        $this->requestFactory = $requestFactory ?? self::discoverRequestFactory();
        $this->streamFactory = $streamFactory ?? self::discoverStreamFactory();
        $this->logger = $logger ?? new NullLogger();
        $this->onRetry = $onRetry;
        $this->onError = $onError;
        $this->onRequest = $onRequest;
        $this->onResponse = $onResponse;
        $this->userAgent = Constants::USER_AGENT_PREFIX . Version::VERSION;
        $this->jitterSource = $jitterSource;
        $this->render = new Render($this);
        $this->documents = new Documents($this);
    }

    /**
     * Static factory — the ergonomic one-liner constructor matching
     * stripe-php / openai-php conventions. Uses every default.
     */
    public static function client(string $apiKey): self
    {
        return new self($apiKey);
    }

    public function post(string $path, array $body, ?string $idempotencyKey, ?float $timeout): array
    {
        $key = $idempotencyKey ?? Uuid4::generate();
        try {
            // Why: the API takes a JSON object body; PHP encodes an empty array as `[]`.
            $payload = json_encode($body === [] ? new \stdClass() : $body, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PoliPageException(
                'Failed to JSON-encode request body: ' . $e->getMessage(),
                PoliPageException::INVALID_OPTIONS,
                null,
                null,
                $e,
            );
        }

        $response = $this->runWithRetry('POST', $path, $key, $payload, $timeout);

        return $this->decodeJsonBody($response);
    }

    public function get(string $path, ?float $timeout): array
    {
        $response = $this->runWithRetry('GET', $path, null, null, $timeout);

        return $this->decodeJsonBody($response);
    }

    public function getText(string $path, ?float $timeout): TextResponse
    {
        $response = $this->runWithRetry('GET', $path, null, null, $timeout);
        // PSR-7 stubs type getHeaders() loosely (outer key as array-key); at
        // runtime every implementation we care about ships string header
        // names. Narrow explicitly via @var so TextResponse's strict shape
        // is preserved.
        /** @var array<string, array<int, string>> $headers */
        $headers = $response->getHeaders();

        return new TextResponse(
            body: (string) $response->getBody(),
            headers: $headers,
        );
    }

    public function delete(string $path, ?float $timeout): void
    {
        $this->runWithRetry('DELETE', $path, null, null, $timeout);
    }

    public function fetchBytes(string $url, ?float $timeout): string
    {
        $response = $this->sendUnauthenticatedGet($url, $timeout);
        try {
            $body = BodyReader::read($response->getBody());
        } catch (\Exception $e) {
            throw DownloadStream::failure($e);
        }
        if ($body === '') {
            throw new PoliPageException(
                'PDF download response had an empty body',
                PoliPageException::INTERNAL_ERROR,
                $response->getStatusCode(),
            );
        }

        return $body;
    }

    public function streamBytes(string $url, ?float $timeout): StreamInterface
    {
        $response = $this->sendUnauthenticatedGet($url, $timeout);

        // No size sanity check: PSR-7 streams may report `getSize() === null`
        // (chunked transfer, unknown content-length) and zero-length 2xx
        // responses would surface as `eof()` immediately on the caller side
        // anyway. A failure while the caller reads it is DOWNLOAD_FAILED.
        return new DownloadStream($response->getBody());
    }

    /**
     * Single-shot, unauthenticated GET — used for the presigned S3 URLs
     * returned by /v1/render. The SDK's auth headers, retry policy, and
     * idempotency are deliberately bypassed because the URL is already
     * signed and the storage endpoint does not honour any of them. The
     * caller's `$timeout` is applied via {@see TimeoutPolicy::send}.
     *
     * Every failure is DOWNLOAD_FAILED (SDK spec §7.2): with the storage
     * status for a non-2xx answer, without one (and the HTTP library's error
     * as `previous`) when no answer came, a timeout included.
     */
    private function sendUnauthenticatedGet(string $url, ?float $timeout): ResponseInterface
    {
        $request = $this->requestFactory->createRequest('GET', $url);
        try {
            $response = TimeoutPolicy::send($this->httpClient, $request, $timeout);
        } catch (ClientExceptionInterface $e) {
            throw DownloadStream::failure($e);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new PoliPageException(
                str_replace('{status}', (string) $status, Constants::MESSAGE_DOWNLOAD_FAILED),
                PoliPageException::DOWNLOAD_FAILED,
                $status,
            );
        }

        return $response;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeJsonBody(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        try {
            $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PoliPageException(
                'API returned malformed JSON: ' . $e->getMessage(),
                PoliPageException::INTERNAL_ERROR,
                $response->getStatusCode(),
                $response->getHeaderLine(Constants::HEADER_REQUEST_ID) !== ''
                    ? $response->getHeaderLine(Constants::HEADER_REQUEST_ID)
                    : null,
                $e,
            );
        }
        if (!is_array($decoded)) {
            throw new PoliPageException(
                'API returned non-object JSON',
                PoliPageException::INTERNAL_ERROR,
                $response->getStatusCode(),
            );
        }

        return $decoded;
    }

    /**
     * @param 'GET'|'POST'|'DELETE' $method
     */
    private function runWithRetry(
        string $method,
        string $path,
        ?string $idempotencyKey,
        ?string $bodyJson,
        ?float $timeout,
    ): ResponseInterface {
        $url = UrlBuilder::build($this->baseUrl, $path);
        $effectiveTimeout = $timeout ?? $this->defaultTimeout;
        $attempt = 0;

        while (true) {
            $result = $this->sendOnce($method, $url, $path, $idempotencyKey, $bodyJson, $effectiveTimeout, $attempt + 1);

            if ($result->isOk()) {
                return $result->response;
            }
            $lastError = $result->error;

            if (!$lastError->isRetryable() || $attempt >= $this->maxRetries) {
                $this->logger->error('polipage: terminal failure', [
                    'method' => $method,
                    'path' => $path,
                    'attempts' => $attempt + 1,
                    'error_code' => $lastError->errorCode,
                    'status' => $lastError->status,
                ]);
                $this->fireOnError($lastError);
                throw $lastError;
            }

            // Schedule the next attempt: increment, compute the backoff, fire the
            // hook with the cause, log, and sleep. By doing this *after* saving
            // $lastError, the cause is statically non-null at the hook call.
            ++$attempt;
            $delay = Backoff::compute($attempt, $this->retryDelay, $result->retryAfter, $this->jitterSource);
            $this->fireOnRetry(new RetryEvent(
                attempt: $attempt + 1,
                delayMs: $delay * 1000.0,
                reason: $lastError,
            ));
            $this->logger->warning('polipage: retrying request', [
                'attempt' => $attempt + 1,
                'delay_ms' => $delay * 1000.0,
                'method' => $method,
                'path' => $path,
                'previous_error_code' => $lastError->errorCode,
            ]);
            self::sleepAtLeast($delay);
        }
    }

    /**
     * Waits `$seconds`, never less (SDK spec §9.2): `usleep()` can return
     * early (a signal, a coarse timer), so it sleeps again for the rest,
     * measured on the monotonic clock.
     */
    private static function sleepAtLeast(float $seconds): void
    {
        $deadline = hrtime(true) + $seconds * 1e9;
        while (($left = $deadline - hrtime(true)) > 0) {
            usleep((int) ceil($left / 1000));
        }
    }

    /**
     * The error of an attempt that got no complete answer: `timeout` when the
     * HTTP library reports one, else `network_error`. The library's error is
     * `previous`; the message is the SDK specification's exact text.
     */
    private static function transportError(\Throwable $e, float $timeout): PoliPageException
    {
        if (TimeoutPolicy::isTimeout($e)) {
            return ExceptionClassifier::timeout(
                str_replace('{timeoutMs}', (string) (int) round($timeout * 1000), Constants::MESSAGE_TIMEOUT),
                $e,
            );
        }

        return ExceptionClassifier::networkError(Constants::MESSAGE_NETWORK_ERROR, $e);
    }

    /**
     * @param 'GET'|'POST'|'DELETE' $method
     */
    private function sendOnce(
        string $method,
        string $url,
        string $path,
        ?string $idempotencyKey,
        ?string $bodyJson,
        float $timeout,
        int $attemptNumber,
    ): SendOnceResult {
        // Why: PSR-7 and transport errors about a bad header value echo the
        // value, which for Authorization is the key. Some PSR-7 versions let
        // a trailing "\n" through withHeader() and the transport then fails
        // with a retryable error that echoes it. These errors carry a fixed
        // message and no previous exception so the value never reaches
        // logs, onError or toPayload().
        foreach (['apiKey' => $this->apiKey, 'idempotencyKey' => $idempotencyKey] as $option => $value) {
            if ($value !== null && preg_match(self::HEADER_VALUE_PATTERN, $value) !== 1) {
                return new SendOnceResult(null, new PoliPageException(
                    $option . ' contains characters that are not allowed in an HTTP header '
                    . '(such as a trailing newline or another control character)',
                    PoliPageException::INVALID_OPTIONS,
                ));
            }
        }
        $request = $this->requestFactory->createRequest($method, $url);
        try {
            foreach (Headers::build($method, $this->apiKey, $idempotencyKey, $this->userAgent) as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
        } catch (\InvalidArgumentException) {
            // Why: a PSR-7 implementation stricter than RFC 7230 (one that
            // rejects bytes 0x80-0xFF, say) still echoes the value here.
            return new SendOnceResult(null, new PoliPageException(
                'A request header value was rejected by the PSR-7 implementation '
                . '(apiKey or idempotencyKey contains a byte it does not accept)',
                PoliPageException::INVALID_OPTIONS,
            ));
        }
        if ($bodyJson !== null) {
            $request = $request->withBody($this->streamFactory->createStream($bodyJson));
        }

        $this->logger->debug('polipage: send', [
            'method' => $method,
            'path' => $path,
            'attempt' => $attemptNumber,
            'timeout_s' => $timeout,
        ]);
        $this->fireOnRequest(new RequestEvent(
            method: $method,
            url: $url,
            attempt: $attemptNumber,
        ));
        $startedAt = hrtime(true);

        try {
            $response = TimeoutPolicy::send($this->httpClient, $request, $timeout);
        } catch (ClientExceptionInterface $e) {
            return new SendOnceResult(null, self::transportError($e, $timeout));
        } catch (PoliPageException $e) {
            return new SendOnceResult(null, $e);
        }

        $status = $response->getStatusCode();
        $requestIdHeader = $response->getHeaderLine(Constants::HEADER_REQUEST_ID);
        $requestId = $requestIdHeader !== '' ? $requestIdHeader : null;
        $ok = $status >= 200 && $status < 300;

        // The whole body is read inside the attempt (SDK spec §9.1), so a
        // failure mid-body belongs to this attempt and goes through the retry
        // policy. Clients that stream the body lazily would otherwise fail
        // later, outside the retry loop.
        try {
            $body = BodyReader::read($response->getBody());
        } catch (\Exception $e) {
            if ($ok) {
                return new SendOnceResult(null, self::transportError($e, $timeout));
            }

            // A malformed error body (SDK spec §8.2): never a code from the
            // bytes received so far. Retried by its status.
            return self::errorResult(
                $response,
                ExceptionClassifier::fromStatus(PoliPageException::INTERNAL_ERROR, $status, "HTTP $status", $requestId, $e),
            );
        }

        if (!$ok) {
            ['code' => $code, 'message' => $message] = ErrorBodyParser::parse($body, $status);

            return self::errorResult($response, ExceptionClassifier::fromStatus($code, $status, $message, $requestId));
        }

        $durationMs = (hrtime(true) - $startedAt) / 1e6;
        $this->logger->info('polipage: response ok', [
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'request_id' => $requestId,
            'duration_ms' => $durationMs,
        ]);
        $this->fireOnResponse(new ResponseEvent(
            status: $status,
            requestId: $requestId,
            durationMs: (int) round($durationMs),
        ));

        return new SendOnceResult($response->withBody($this->streamFactory->createStream($body)), null);
    }

    /**
     * A failed attempt with an HTTP answer, and the server's `Retry-After`
     * when the error is retried.
     */
    private static function errorResult(ResponseInterface $response, PoliPageException $error): SendOnceResult
    {
        $retryAfterHeader = $response->getHeaderLine(Constants::HEADER_RETRY_AFTER);
        $retryAfter = $error->isRetryable()
            ? RetryAfterParser::parse($retryAfterHeader !== '' ? $retryAfterHeader : null)
            : null;

        return new SendOnceResult(null, $error, $retryAfter);
    }

    private function fireOnRetry(RetryEvent $event): void
    {
        if ($this->onRetry === null) {
            return;
        }
        try {
            ($this->onRetry)($event);
        } catch (\Throwable) {
            // Hooks must never break the request — match Node's #fireHook.
        }
    }

    private function fireOnError(PoliPageException $error): void
    {
        if ($this->onError === null) {
            return;
        }
        try {
            ($this->onError)($error);
        } catch (\Throwable) {
            // Hooks must never break the request — match Node's #fireHook.
        }
    }

    private function fireOnRequest(RequestEvent $event): void
    {
        if ($this->onRequest === null) {
            return;
        }
        try {
            ($this->onRequest)($event);
        } catch (\Throwable) {
            // Hooks must never break the request — match Node's #fireHook.
        }
    }

    private function fireOnResponse(ResponseEvent $event): void
    {
        if ($this->onResponse === null) {
            return;
        }
        try {
            ($this->onResponse)($event);
        } catch (\Throwable) {
            // Hooks must never break the request — match Node's #fireHook.
        }
    }

    private static function discoverHttpClient(): ClientInterface
    {
        try {
            return Psr18ClientDiscovery::find();
        } catch (\Throwable $e) {
            throw new PoliPageException(
                'No PSR-18 HTTP client could be discovered. Install guzzlehttp/guzzle or symfony/http-client, '
                . 'or pass an explicit httpClient: argument to the constructor.',
                PoliPageException::INVALID_OPTIONS,
                null,
                null,
                $e,
            );
        }
    }

    private static function discoverRequestFactory(): RequestFactoryInterface
    {
        try {
            return Psr17FactoryDiscovery::findRequestFactory();
        } catch (\Throwable $e) {
            throw new PoliPageException(
                'No PSR-17 RequestFactory could be discovered. Install nyholm/psr7, guzzlehttp/psr7, or pass an '
                . 'explicit requestFactory: argument to the constructor.',
                PoliPageException::INVALID_OPTIONS,
                null,
                null,
                $e,
            );
        }
    }

    private static function discoverStreamFactory(): StreamFactoryInterface
    {
        try {
            return Psr17FactoryDiscovery::findStreamFactory();
        } catch (\Throwable $e) {
            throw new PoliPageException(
                'No PSR-17 StreamFactory could be discovered. Install nyholm/psr7, guzzlehttp/psr7, or pass an '
                . 'explicit streamFactory: argument to the constructor.',
                PoliPageException::INVALID_OPTIONS,
                null,
                null,
                $e,
            );
        }
    }
}
