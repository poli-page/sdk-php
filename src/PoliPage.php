<?php

declare(strict_types=1);

namespace PoliPage;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use PoliPage\Events\RetryEvent;
use PoliPage\Internal\Constants;
use PoliPage\Internal\ExceptionClassifier;
use PoliPage\Internal\Http\Backoff;
use PoliPage\Internal\Http\ErrorBodyParser;
use PoliPage\Internal\Http\Headers;
use PoliPage\Internal\Http\RetryAfterParser;
use PoliPage\Internal\Http\SendOnceResult;
use PoliPage\Internal\Http\UrlBuilder;
use PoliPage\Internal\Transport;
use PoliPage\Internal\Uuid\Uuid4;
use PoliPage\Internal\Version;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Poli Page SDK client — entry point for the namespaced render API.
 *
 * @example
 * ```php
 * use PoliPage\PoliPage;
 * use PoliPage\ProjectModeInput;
 *
 * $client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);
 * $preview = $client->render->preview(new ProjectModeInput(
 *     project: 'billing',
 *     template: 'invoice',
 *     version: '1.0.0',
 *     data: ['invoiceNumber' => 'INV-001'],
 * ));
 * ```
 */
final class PoliPage implements Transport
{
    public readonly Render $render;

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
    private readonly string $userAgent;
    /** @var (\Closure(): float)|null */
    private readonly ?\Closure $jitterSource;

    /**
     * @param (\Closure(RetryEvent): void)|null     $onRetry      fires before each retry sleep
     * @param (\Closure(PoliPageException): void)|null $onError   fires once at terminal failure
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
        ?\Closure $jitterSource = null,
    ) {
        if ($apiKey === '') {
            throw new PoliPageException('apiKey is required', PoliPageException::INVALID_OPTIONS);
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
        $this->userAgent = Constants::USER_AGENT_PREFIX . Version::VERSION;
        $this->jitterSource = $jitterSource;
        $this->render = new Render($this);
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
            $payload = json_encode($body, flags: JSON_THROW_ON_ERROR);
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

            if (!$result->retryable || $attempt >= $this->maxRetries) {
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
            usleep((int) round($delay * 1_000_000));
        }
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
        $request = $this->requestFactory->createRequest($method, $url);
        foreach (Headers::build($method, $this->apiKey, $idempotencyKey, $this->userAgent) as $name => $value) {
            $request = $request->withHeader($name, $value);
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
        $startedAt = microtime(true);

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $error = ExceptionClassifier::networkError($e->getMessage(), $e);

            return new SendOnceResult(null, $error, null, true);
        }

        $status = $response->getStatusCode();
        $requestIdHeader = $response->getHeaderLine(Constants::HEADER_REQUEST_ID);
        $requestId = $requestIdHeader !== '' ? $requestIdHeader : null;
        $durationMs = (microtime(true) - $startedAt) * 1000.0;

        if ($status >= 200 && $status < 300) {
            $this->logger->info('polipage: response ok', [
                'method' => $method,
                'path' => $path,
                'status' => $status,
                'request_id' => $requestId,
                'duration_ms' => $durationMs,
            ]);

            return new SendOnceResult($response, null, null, false);
        }

        $retryable = $status >= 500 || $status === 429;
        $retryAfterHeader = $response->getHeaderLine(Constants::HEADER_RETRY_AFTER);
        $retryAfter = $retryable
            ? RetryAfterParser::parse($retryAfterHeader !== '' ? $retryAfterHeader : null)
            : null;

        $errorBody = (string) $response->getBody();
        ['code' => $code, 'message' => $message] = ErrorBodyParser::parse($errorBody, $status);
        $error = ExceptionClassifier::fromStatus($code, $status, $message, $requestId);

        return new SendOnceResult(null, $error, $retryAfter, $retryable);
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
