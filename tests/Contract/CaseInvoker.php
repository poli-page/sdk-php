<?php

declare(strict_types=1);

namespace PoliPage\Tests\Contract;

use GuzzleHttp\Client as GuzzleClient;
use PoliPage\DocumentDescriptor;
use PoliPage\DocumentPreviewResult;
use PoliPage\Events\RequestEvent;
use PoliPage\Events\RetryEvent;
use PoliPage\Orientation;
use PoliPage\PageFormat;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\RenderInput;
use PoliPage\RenderMetadata;
use PoliPage\Thumbnail;
use PoliPage\ThumbnailOptions;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Builds a client at the mock server with the case's options, calls the
 * case's SDK method through a real PSR-18 client and returns the normalized
 * result. Records the `onRequest` / `onRetry` events.
 *
 * The call runs in a Fiber: at the `stopAfterRetryEvents`-th retry event the
 * `onRetry` hook suspends it and the test abandons it. PHP has no
 * cancellation, and a hook cannot end the call by throwing (the SDK swallows
 * a hook's errors, SDK spec §4.1).
 */
final class CaseInvoker
{
    /** The client's key when a case sets no `apiKey`. */
    public const TEST_API_KEY = 'pp_sandbox_00000000000000000000000000000000';

    public const HTTP_CLIENTS = ['guzzle', 'symfony'];

    /** @var list<array<string, int|string>> */
    public array $events = [];

    private int $retries = 0;

    public function __construct(private readonly ContractCase $case, private readonly string $origin)
    {
    }

    public function apiKey(): string
    {
        return $this->case->apiKey ?? self::TEST_API_KEY;
    }

    /**
     * @return array<string, mixed>|null the normalized result, or null when the call was stopped
     */
    public function run(string $httpClient): ?array
    {
        $fiber = new \Fiber(fn (): array => $this->call($httpClient));
        $fiber->start();
        if ($fiber->isSuspended()) {
            return null;
        }
        $result = $fiber->getReturn();
        if (!is_array($result)) {
            throw new ContractException('The SDK call returned no result');
        }
        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function call(string $httpClient): array
    {
        try {
            $client = new PoliPage(
                apiKey: $this->apiKey(),
                baseUrl: $this->origin,
                maxRetries: $this->case->maxRetries,
                retryDelay: $this->case->retryDelayMs / 1000,
                timeout: $this->case->timeoutMs / 1000,
                httpClient: $this->httpClient($httpClient),
                onRequest: $this->onRequest(...),
                onRetry: $this->onRetry(...),
            );
            $value = $this->invoke($client);
        } catch (ContractException $e) {
            throw $e;
        } catch (PoliPageException $e) {
            return ['ok' => false, 'error' => self::normalizeError($e)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => self::notAPoliPageException($e)];
        }

        return ['ok' => true, 'value' => $value];
    }

    private function httpClient(string $name): ClientInterface
    {
        return match ($name) {
            'guzzle' => new GuzzleClient(),
            // The SDK applies its per-attempt timeout through Guzzle's request
            // options only; with any other PSR-18 client the timeout is set
            // on the client, as the README says.
            'symfony' => new Psr18Client(HttpClient::create(['timeout' => $this->case->timeoutMs / 1000])),
            default => throw new ContractException('Unknown HTTP client ' . $name),
        };
    }

    private function onRequest(RequestEvent $event): void
    {
        $this->events[] = ['type' => 'request', 'attempt' => $event->attempt];
    }

    private function onRetry(RetryEvent $event): void
    {
        // N-DURATION-MS: `delayMs` is a float.
        $this->events[] = ['type' => 'retry', 'attempt' => $event->attempt, 'delayMs' => (int) round($event->delayMs)];
        ++$this->retries;
        if ($this->retries === $this->case->stopAfterRetryEvents) {
            \Fiber::suspend();
        }
    }

    private function invoke(PoliPage $client): mixed
    {
        $args = $this->case->args;

        return match ($this->case->method) {
            'render.pdf' => self::bytes($client->render->pdf($this->renderInput($args))),
            'render.pdfStream' => self::bytes(self::readStream($client->render->pdfStream($this->renderInput($args)))),
            'render.document' => self::descriptor($client->render->document($this->renderInput($args))),
            'documents.get' => self::descriptor($client->documents->get($this->stringArg($args, 0))),
            'documents.preview' => self::preview($client->documents->preview($this->stringArg($args, 0))),
            'documents.thumbnails' => self::thumbnails(
                $client->documents->thumbnails($this->stringArg($args, 0), $this->thumbnailOptions($args)),
            ),
            'documents.delete' => self::deleted($client, $this->stringArg($args, 0)),
            default => throw new ContractException('Unknown SDK method in the contract: ' . $this->case->method),
        };
    }

    /**
     * @param list<mixed> $args
     */
    private function renderInput(array $args): RenderInput
    {
        $input = $this->objectArg($args, 0) ?? throw new ContractException('call.args[0] must be the render input');
        $known = ['project', 'template', 'data', 'format', 'orientation', 'locale', 'metadata'];
        $unknown = array_diff(array_keys($input), $known);
        if ($unknown !== []) {
            throw new ContractException('Render input fields this test does not map: ' . implode(', ', $unknown));
        }
        $template = $input['template'] ?? null;
        $data = $input['data'] ?? null;
        if (!is_string($template) || !is_array($data)) {
            throw new ContractException('The render input needs a string template and a data object');
        }
        /** @var array<string, mixed> $data */
        $metadata = $input['metadata'] ?? null;
        if ($metadata !== null && !is_array($metadata)) {
            throw new ContractException('metadata must be an object');
        }
        /** @var array<string, string|int|float|bool>|null $metadata */

        return new RenderInput(
            // RenderInput types `project` as a string; an omitted project is
            // the empty one the SDK rejects before any request.
            project: self::optionalString($input, 'project') ?? '',
            template: $template,
            data: $data,
            format: ($format = self::optionalString($input, 'format')) === null ? null : PageFormat::from($format),
            orientation: ($orientation = self::optionalString($input, 'orientation')) === null ? null : Orientation::from($orientation),
            locale: self::optionalString($input, 'locale'),
            metadata: $metadata === null ? null : new RenderMetadata($metadata),
        );
    }

    /**
     * @param list<mixed> $args
     */
    private function thumbnailOptions(array $args): ?ThumbnailOptions
    {
        $options = $this->objectArg($args, 1);
        if ($options === null) {
            return null;
        }
        $unknown = array_diff(array_keys($options), ['width', 'format', 'quality', 'pages']);
        if ($unknown !== []) {
            throw new ContractException('Thumbnail options this test does not map: ' . implode(', ', $unknown));
        }
        $format = self::optionalString($options, 'format');
        if ($format !== null && !in_array($format, ['jpeg', 'webp', 'png'], true)) {
            throw new ContractException('Unknown thumbnail format ' . $format);
        }
        $pages = $options['pages'] ?? null;
        if ($pages !== null && (!is_array($pages) || !array_is_list($pages) || array_filter($pages, 'is_int') !== $pages)) {
            throw new ContractException('Thumbnail pages must be a list of integers');
        }
        /** @var list<int>|null $pages */

        return new ThumbnailOptions(
            width: self::optionalInt($options, 'width'),
            format: $format,
            quality: self::optionalInt($options, 'quality'),
            pages: $pages,
        );
    }

    /**
     * @param list<mixed> $args
     *
     * @return array<string, mixed>|null
     */
    private function objectArg(array $args, int $index): ?array
    {
        $value = $args[$index] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new ContractException(sprintf('call.args[%d] must be an object', $index));
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /**
     * @param list<mixed> $args
     */
    private function stringArg(array $args, int $index): string
    {
        $value = $args[$index] ?? null;
        if (!is_string($value)) {
            throw new ContractException(sprintf('call.args[%d] must be a string', $index));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $object
     */
    private static function optionalString(array $object, string $name): ?string
    {
        $value = $object[$name] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new ContractException($name . ' must be a string');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $object
     */
    private static function optionalInt(array $object, string $name): ?int
    {
        $value = $object[$name] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new ContractException($name . ' must be an integer');
        }

        return $value;
    }

    private static function readStream(StreamInterface $stream): string
    {
        $bytes = '';
        while (!$stream->eof()) {
            $bytes .= $stream->read(8192);
        }
        $stream->close();

        return $bytes;
    }

    /**
     * N-BYTES.
     *
     * @return array{sha256: string, length: int}
     */
    private static function bytes(string $data): array
    {
        return ['sha256' => hash('sha256', $data), 'length' => strlen($data)];
    }

    /**
     * N-DESCRIPTOR: the §7.1 fields only, under their wire names.
     *
     * @return array<string, mixed>
     */
    private static function descriptor(DocumentDescriptor $d): array
    {
        return [
            'documentId' => $d->documentId,
            'organizationId' => $d->organizationId,
            'projectId' => $d->projectId,
            'projectSlug' => $d->projectSlug,
            'templateId' => $d->templateId,
            'templateSlug' => $d->templateSlug,
            'environment' => $d->environment,
            'apiKeyId' => $d->apiKeyId,
            'format' => $d->format,
            'orientation' => $d->orientation,
            'locale' => $d->locale,
            'pageCount' => $d->pageCount,
            'sizeBytes' => $d->sizeBytes,
            'createdAt' => $d->createdAt,
            'metadata' => $d->metadata->toArray(),
            'presignedPdfUrl' => $d->presignedPdfUrl,
            'expiresAt' => $d->expiresAt,
        ];
    }

    /**
     * N-PREVIEW.
     *
     * @return array{html: string, pageCount: int}
     */
    private static function preview(DocumentPreviewResult $preview): array
    {
        return ['html' => $preview->html, 'pageCount' => $preview->pageCount];
    }

    /**
     * N-THUMBS-ARRAY: the SDK already returns the unwrapped list.
     *
     * @param list<Thumbnail> $thumbnails
     *
     * @return list<array<string, int|string>>
     */
    private static function thumbnails(array $thumbnails): array
    {
        return array_map(static fn (Thumbnail $t): array => [
            'page' => $t->page,
            'width' => $t->width,
            'height' => $t->height,
            'contentType' => $t->contentType,
            'data' => $t->data,
        ], $thumbnails);
    }

    /**
     * N-DELETE-NULL.
     */
    private static function deleted(PoliPage $client, string $id): null
    {
        $client->documents->delete($id);

        return null;
    }

    /**
     * N-STATUS-NULL, N-REQID-NULL: `status` and `requestId` are already null when absent.
     *
     * @return array<string, mixed>
     */
    private static function normalizeError(PoliPageException $error): array
    {
        return [
            'code' => $error->errorCode,
            'status' => $error->status,
            'message' => $error->getMessage(),
            'requestId' => $error->requestId,
            'retryable' => $error->isRetryable(),
            'isAuthError' => $error->isAuthError(),
            'isRateLimitError' => $error->isRateLimitError(),
            'isValidationError' => $error->isValidationError(),
            'isNetworkError' => $error->isNetworkError(),
        ];
    }

    /**
     * Anything the SDK raises that is not a PoliPageException shows in the diff.
     *
     * @return array<string, mixed>
     */
    private static function notAPoliPageException(\Throwable $error): array
    {
        return [
            'code' => 'not a PoliPageException',
            'status' => null,
            'message' => $error::class . ': ' . $error->getMessage(),
            'requestId' => null,
            'retryable' => false,
            'isAuthError' => false,
            'isRateLimitError' => false,
            'isValidationError' => false,
            'isNetworkError' => false,
        ];
    }
}
