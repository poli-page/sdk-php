# Poli Page SDK for PHP

[![Packagist](https://img.shields.io/packagist/v/poli-page/sdk?style=flat&logo=php&logoColor=ffffff&label=Packagist)](https://packagist.org/packages/poli-page/sdk)
[![Downloads](https://img.shields.io/packagist/dt/poli-page/sdk?style=flat&logo=php&logoColor=ffffff&label=Downloads)](https://packagist.org/packages/poli-page/sdk)
[![Ci](https://img.shields.io/github/actions/workflow/status/poli-page/sdk-php/ci.yml?branch=main&style=flat&logo=githubactions&logoColor=ffffff&label=Ci)](https://github.com/poli-page/sdk-php/actions/workflows/ci.yml)
[![Codeql](https://img.shields.io/github/actions/workflow/status/poli-page/sdk-php/codeql.yml?branch=main&style=flat&logo=github&logoColor=ffffff&label=Codeql)](https://github.com/poli-page/sdk-php/actions/workflows/codeql.yml)
[![Coverage](https://img.shields.io/codecov/c/github/poli-page/sdk-php?style=flat&logo=codecov&logoColor=ffffff&label=Coverage)](https://codecov.io/gh/poli-page/sdk-php)
[![Php](https://img.shields.io/packagist/php-v/poli-page/sdk?style=flat&logo=php&logoColor=ffffff&label=Php)](https://packagist.org/packages/poli-page/sdk)
[![Phpstan](https://img.shields.io/badge/Phpstan-max-blue?style=flat&logo=php&logoColor=ffffff)](phpstan.neon)
[![Deps](https://img.shields.io/badge/Deps-up%20to%20date-brightgreen?style=flat&logo=php&logoColor=ffffff)](https://github.com/poli-page/sdk-php/network/dependencies)
[![Docs](https://img.shields.io/badge/Docs-online-brightgreen?style=flat&logo=readthedocs&logoColor=ffffff)](https://poli-page.github.io/sdk-php/)
[![License](https://img.shields.io/badge/License-MIT-blue?style=flat&logo=gnu&logoColor=ffffff)](LICENSE)

Official PHP SDK for [Poli Page](https://poli.page) — render polished PDFs from HTML templates via the Poli Page API.

→ Docs (auto-generated from source): **https://poli-page.github.io/sdk-php/**

## Install

The SDK declares only [PSR-18](https://www.php-fig.org/psr/psr-18/) / [PSR-17](https://www.php-fig.org/psr/psr-17/) / [PSR-3](https://www.php-fig.org/psr/psr-3/) interfaces plus [`php-http/discovery`](https://github.com/php-http/discovery) as hard dependencies — it deliberately ships **no** concrete HTTP client. You **must** install a PSR-18 client and a PSR-7 / PSR-17 implementation alongside it, or constructing the client will throw (discovery finds nothing to use). Install both in one command:

```bash
# Guzzle (~80% of PHP apps) — the canonical choice
composer require poli-page/sdk guzzlehttp/guzzle
```

Guzzle bundles its own PSR-7 / PSR-17 implementation, so that single line is everything you need. Prefer something else? Any PSR-18 client + PSR-7 pairing works:

```bash
# Symfony HTTP Client
composer require poli-page/sdk symfony/http-client nyholm/psr7

# Lightweight, no curl needed
composer require poli-page/sdk php-http/curl-client nyholm/psr7
```

Discovery auto-detects whichever you've installed; you never name it in code (or pass it explicitly via the `httpClient:` constructor argument — see [Configuration](#configuration)).

Requires PHP 8.3 or later.

## Quick start

### Render the `getting-started` template

```php
use PoliPage\PoliPage;
use PoliPage\RenderInput;

$client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);

$pdf = $client->render->pdf(new RenderInput(
    project: 'getting-started',
    template: 'welcome',
    data: ['name' => 'SDK Demo'],
));
// $pdf is a string of raw PDF bytes
```

Every Poli Page organization gets a `getting-started` project, published into its `sandbox` environment when the organization is created, with a `welcome` template. With a sandbox key (`pp_sandbox_…`) in `POLI_PAGE_API_KEY`, the snippet above runs as-is on a fresh organization — no project setup needed. `getting-started` is a normal, editable project: if your organization renamed it, deleted it or removed it from `sandbox`, the API answers `404 PROJECT_NOT_FOUND` or `404 PROJECT_NOT_IN_ENVIRONMENT`, so use a fresh organization.

There is no version to pick: a render uses the project's copy published in the key's environment. For your own templates, publish the project to the key's environment, then swap the slugs:

```php
$pdf = $client->render->pdf(new RenderInput(
    project: 'billing',
    template: 'invoice',
    data: ['invoiceNumber' => 'INV-001', 'total' => 1280],
));
```

To preview a template or try inline HTML, use the playground at [play.poli.page](https://play.poli.page): the API renders only published templates.

### Write a PDF to disk

```php
use PoliPage\PoliPage;
use PoliPage\RenderInput;

use function PoliPage\renderToFile;

$client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);
renderToFile(
    $client,
    new RenderInput(
        project: 'getting-started',
        template: 'welcome',
        data: ['name' => 'SDK Demo'],
    ),
    './welcome.pdf',
);
```

`renderToFile` streams response bytes directly to disk in 8 KB chunks (bounded memory regardless of document size).

### Try it locally — runnable demo

The repo ships a single end-to-end demo that exercises every public method against the real API:

```bash
composer install
php examples/demo.php
```

The demo reads `POLI_PAGE_API_KEY` and the optional `POLI_PAGE_BASE_URL` from your shell or the repo-root `.env`. Without a key, the first run asks for a sandbox key (`pp_sandbox_…`), checks its shape against the full-key regex and saves it to `.env`; later runs are silent. Use a key of a fresh organization, whose `getting-started` project is untouched.

It renders `getting-started`/`welcome` and walks nine steps, writing its output to `examples/output/`:

1. `render->pdf()` — PDF bytes in memory
2. `render->pdfStream()` — PSR-7 stream of PDF bytes
3. `renderToFile()` — render straight to disk
4. `render->document()` — store the document, return the descriptor
5. `documents->get($id)` — re-fetch with a fresh presigned URL
6. `documents->thumbnails($id)` — page thumbnails (Starter+ tier; skipped otherwise)
7. `documents->preview($id)` — stored HTML of the rendered document
8. `documents->delete($id)` — soft-delete the document
9. not-found — renders the unknown template `does-not-exist`, catches the `404 TEMPLATE_NOT_FOUND` and prints its `code`, `status`, `message` and `requestId`

See `examples/demo.php` for the annotated source.

### Stream — for large PDFs or piping to S3 / HTTP responses

```php
$stream = $client->render->pdfStream(new RenderInput(
    project: 'billing',
    template: 'invoice',
    data: ['invoiceNumber' => 'INV-001'],
));
// $stream is a PSR-7 StreamInterface

$file = fopen('invoice.pdf', 'wb');
while (!$stream->eof()) {
    fwrite($file, $stream->read(8192));
}
fclose($file);
$stream->close();
```

Any PSR-7-aware destination works — write to a file, an HTTP response body, or an S3 multipart upload that accepts a stream.

## Working with stored documents

Every render produces a stored document, accessible via `documentId` for later download or thumbnails. `render->pdf` and `render->pdfStream` are conveniences that chain a presigned-URL fetch internally to return bytes; `render->document` returns just the descriptor (skip the auto-download when you'll fetch the bytes later).

```php
use PoliPage\RenderMetadata;
use PoliPage\ThumbnailOptions;

// 1. Render and store
$doc = $client->render->document(new RenderInput(
    project: 'billing',
    template: 'invoice',
    data: ['invoiceNumber' => 'INV-001'],
    metadata: new RenderMetadata(['customerId' => 'cust_123']),  // your own audit data
));
// $doc->documentId, $doc->pageCount, $doc->sizeBytes, $doc->presignedPdfUrl, $doc->metadata, ...

// 2. Save $doc->documentId in your database
$db->invoices->update(['id' => 'INV-001'], ['documentId' => $doc->documentId]);

// 3. Later, fetch a fresh presigned URL + download
$fresh = $client->documents->get($doc->documentId);
$pdf = $fresh->downloadPdf();

// 4. Generate thumbnails (Starter+ tier)
$thumbs = $client->documents->thumbnails(
    $doc->documentId,
    new ThumbnailOptions(width: 320, format: 'png'),
);

// 5. When done, soft-delete
$client->documents->delete($doc->documentId);
```

The presigned URL has a 15-minute TTL. If `downloadPdf()` fails with `errorCode: 'DOWNLOAD_FAILED'` (HTTP 403 from S3), call `documents->get($id)` to refresh and retry.

## Authentication & environments

An API key belongs to exactly one environment of your organization, and every render uses the project's copy published in that environment. A key is `pp_{env}_{32 lowercase hex}`, where `{env}` is the environment's name; the full key matches:

```text
^pp_(?=[a-z][a-z0-9-]{0,19}_)[a-z][a-z0-9]*(?:-[a-z0-9]+)*_[0-9a-f]{32}$
```

| Environment                           | Key            | Watermark | Counted |
| ------------------------------------- | -------------- | --------- | ------- |
| `sandbox` (every org)                 | `pp_sandbox_…` | yes       | no      |
| `live` (every org, deletable on Pro+) | `pp_live_…`    | no        | yes     |
| created on Pro+ (e.g. `staging`)      | `pp_staging_…` | no        | yes     |

"Counted" means the render counts toward the organization's monthly PDF quota. `sandbox` renders are watermarked and not counted, and `sandbox` documents expire 30 days after creation. There is no watermark option: a template that wants a watermark outside `sandbox` uses the `poli-watermark` class.

Every key hits the same endpoint (`https://api.poli.page`). Standard and service keys share this one format. The SDK passes the key through as a Bearer token and does not check its format — the API answers `401 INVALID_API_KEY` to a key it does not know. The one client-side check is that the key is a valid HTTP header value: a key with CR, LF, NUL or another control character other than tab (often a trailing newline from a `.env` value or a secret file) fails before any request is built with a `PoliPageException` (`invalid_options`) whose message never contains the key.

## Methods

| Method | Returns | Description |
| ------ | ------- | ----------- |
| `$client->render->pdf($input)` | `string` | Render a PDF, return raw bytes |
| `$client->render->pdfStream($input)` | `Psr\Http\Message\StreamInterface` | Render and stream the response |
| `$client->render->document($input)` | `DocumentDescriptor` | Render and return descriptor (skip auto-download) |
| `$client->documents->get($id)` | `DocumentDescriptor` | Retrieve a stored document |
| `$client->documents->preview($id)` | `DocumentPreviewResult` | Stored document's paginated HTML |
| `$client->documents->thumbnails($id, $options)` | `list<Thumbnail>` | Page thumbnails (PNG/JPEG, base64) |
| `$client->documents->delete($id)` | `void` | Soft-delete a stored document |
| `PoliPage\renderToFile($client, $input, $path)` | `void` | Render and stream to disk |

## Configuration

Construct via the static factory for the common case, or use the named-argument constructor when you want to override anything:

```php
// Static factory — uses every default.
$client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);

// Named-arg constructor — override what you need.
$client = new PoliPage(
    apiKey: $_ENV['POLI_PAGE_API_KEY'],
    baseUrl: 'https://api.poli.page',
    maxRetries: 3,
    timeout: 60.0,
    logger: $monolog,
);
```

| Option | Type | Default | Description |
| ------ | ---- | ------- | ----------- |
| `apiKey` | `string` | (required) | API key of the environment to render in (`pp_{env}_…`, see [Authentication & environments](#authentication--environments)) |
| `baseUrl` | `?string` | `https://api.poli.page` | API base URL |
| `maxRetries` | `?int` | `2` | Max retry attempts on retryable errors |
| `retryDelay` | `?float` (seconds) | `0.5` | Base delay before the first retry |
| `timeout` | `?float` (seconds) | `60.0` | Per-attempt timeout, applied per request with Guzzle and Symfony HTTP Client |
| `httpClient` | `?Psr\Http\Client\ClientInterface` | (auto-discovered) | Override the discovered PSR-18 client |
| `requestFactory` | `?Psr\Http\Message\RequestFactoryInterface` | (auto-discovered) | Override the discovered PSR-17 request factory |
| `streamFactory` | `?Psr\Http\Message\StreamFactoryInterface` | (auto-discovered) | Override the discovered PSR-17 stream factory |
| `logger` | `?Psr\Log\LoggerInterface` | `NullLogger` | PSR-3 logger for SDK debug / retry / error events |
| `onRetry` | `?\Closure(RetryEvent): void` | — | Called before each retry sleep |
| `onError` | `?\Closure(PoliPageException): void` | — | Called when a call terminates in error |

Per-call overrides live on the input object itself: pass `timeout:` and/or `idempotencyKey:` to `RenderInput` to override the client-level defaults for that one call.

## Error handling

The SDK ships a small exception hierarchy under `PoliPage\Exception`, all rooted in `PoliPageException`. Idiomatic PHP usage is `catch` by subclass:

```php
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\Exception\AuthenticationException;
use PoliPage\Exception\BadRequestException;
use PoliPage\Exception\ConnectionException;
use PoliPage\Exception\RateLimitException;

try {
    $pdf = $client->render->pdf(new RenderInput(...));
} catch (AuthenticationException $e) {
    return refreshCredentials();                 // 401 / 403
} catch (RateLimitException $e) {
    return queueForLater();                      // 429 after retries exhausted
} catch (BadRequestException $e) {
    error_log("bad input: {$e->errorCode} {$e->getMessage()}");  // 400
} catch (ConnectionException $e) {
    error_log("network / timeout: {$e->getMessage()}");
} catch (PoliPageException $e) {
    // catch-all for any SDK-raised exception
    error_log("poli error: {$e->errorCode} status={$e->status} requestId={$e->requestId}");
    throw $e;
}
```

The hierarchy:

```
PoliPageException                      (base, extends \RuntimeException)
├── Exception\ConnectionException      (network / DNS / TLS — no $status)
│   └── Exception\TimeoutException     (per-request deadline exceeded)
└── Exception\ApiStatusException       (any non-2xx — carries $status)
    ├── Exception\BadRequestException        (400)
    ├── Exception\AuthenticationException    (401)
    ├── Exception\PermissionDeniedException  (403)
    ├── Exception\NotFoundException          (404)
    ├── Exception\GoneException              (410 — document deleted or expired)
    ├── Exception\RateLimitException         (429)
    └── Exception\InternalServerException    (5xx)
```

Predicate helpers are kept for spec parity across SDK languages. They read `status` and `errorCode`, so a `DOWNLOAD_FAILED` with the storage's 403 is an auth error:

```php
if ($e->isAuthError())       { /* status 401 or 403 */ }
if ($e->isRateLimitError())  { /* status 429 */ }
if ($e->isValidationError()) { /* status 400 */ }
if ($e->isNetworkError())    { /* errorCode network_error or timeout */ }
if ($e->isRetryable())       { /* 5xx, 429, network, timeout — SDK already retried;
                                  never QUOTA_EXCEEDED, OVERAGE_CAP_EXCEEDED, STORAGE_ACCESS_DENIED */ }
```

For lifecycle and billing failures, route the user to actionable messages rather than treating them as opaque errors:

```php
try {
    $doc = $client->render->document(new RenderInput(...));
} catch (PoliPageException $e) {
    if ($e->errorCode === PoliPageException::ORGANIZATION_PAYMENT_GRACE) {
        return showBanner('Subscription has unpaid invoices.');
    }
    if ($e->errorCode === PoliPageException::ORGANIZATION_CANCELLED) {
        return showBanner('Subscription cancelled — service is read-only.');
    }
    if ($e->errorCode === PoliPageException::DOCUMENT_NOT_FOUND) {
        return show404();
    }
    if ($e->errorCode === PoliPageException::DOCUMENT_GONE) {
        return show410();    // deleted, or a sandbox document past its 30 days
    }
    throw $e;
}
```

→ Full error reference: https://poli-page.github.io/sdk-php/reference/errors/

## Cancellation

PHP has no first-class cancellation primitive (no `AbortSignal`, no `context.Context`). The SDK exposes timeouts instead:

- `timeout:` on the constructor — applied as the per-request deadline for every call.
- `timeout:` on the input object (`RenderInput`) — overrides the client default for that one call.

PSR-18 does not standardise per-request timeouts, so the SDK applies the value through the client's own per-request options: Guzzle's request options, and Symfony HTTP Client's `withOptions()` (`max_duration` and the idle `timeout`; symfony/http-client 6.2 or later). With any other PSR-18 client the value cannot reach the client: configure the timeout on the client you inject.

## Observability

### PSR-3 logger (`logger:` constructor argument)

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('polipage');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

$client = new PoliPage(
    apiKey: $_ENV['POLI_PAGE_API_KEY'],
    logger: $logger,    // any PSR-3 LoggerInterface
);
```

Works with Laravel's `Log` facade, Symfony's `LoggerInterface` autowiring, Monolog standalone, or any custom PSR-3 implementation. The SDK emits one `DEBUG` line per HTTP attempt, one `INFO` per success, one `WARN` per retry, and one `ERROR` per terminal failure. The `Authorization` header is never logged.

### SDK-level hooks (`onRetry`, `onError`)

Hooks fire at well-defined points; they are sync, optional, and never break the request:

```php
use PoliPage\Events\RetryEvent;
use PoliPage\PoliPageException;

$client = new PoliPage(
    apiKey: $_ENV['POLI_PAGE_API_KEY'],
    onRetry: fn(RetryEvent $e) => $log->warn(
        "retry {$e->attempt} after {$e->delayMs}ms: {$e->reason->errorCode}",
    ),
    onError: fn(PoliPageException $e) => $sentry->capture($e),
);
```

For per-request / per-response inspection, install middleware on your injected PSR-18 client (Guzzle handler stack, Symfony HTTP Client decoration, etc.) — that's the cross-framework PHP convention and the SDK deliberately doesn't reinvent it.

## Retries & idempotency

The SDK retries on **5xx**, **429**, **network errors**, and **timeouts**, except `QUOTA_EXCEEDED`, `OVERAGE_CAP_EXCEEDED` (429) and `STORAGE_ACCESS_DENIED` (502), which waiting cannot fix: those are thrown at once. Before retry `n` (1-based) the SDK waits `retryDelay × 2^(n-1) × j`, with `j` a random factor in `[0.5, 1.5)`; a `Retry-After` header (integer seconds or HTTP-date, clamped to 30 s) replaces it when the server sends one. `Retry-After-Ms` is ignored. The presigned-URL download is never retried. With Guzzle or Symfony HttpClient a POST always opens a fresh connection, so libcurl never resends it behind the SDK's back (with Symfony, that request option takes the place of any `extra.curl` default of your client on the SDK's POSTs). Every `POST` sends an auto-generated `Idempotency-Key` (UUID v4), the same on every retry of that call; pass `idempotencyKey:` in the input to override. The API does not deduplicate on it, so a render retried after a late 5xx, a `timeout` or a `network_error` can store a second document.

## Type system

The SDK is fully type-annotated and tested at **PHPStan level max** with `phpstan-strict-rules`. Every public method has explicit parameter and return types; PHPDoc array shapes are provided wherever native PHP types are insufficient.

`RenderInput` is the single render input: `project`, `template` and `data`, plus optional `format`, `orientation`, `locale`, `metadata`, `idempotencyKey` and `timeout`. It has no `version` parameter, so passing one fails at compile-time (PHPStan) and at runtime (PHP rejects the unknown named argument).

`final readonly class` is used throughout for input/output DTOs. Mutations require constructing a new instance — pair with PHP 8.4's `clone with` syntax if you need a one-field tweak.

## Concurrency & thread-safety

PHP's per-request execution model means client instances are scoped to a single request — there is no shared mutable state to coordinate across requests. For long-running workers (ReactPHP, Swoole, RoadRunner, FrankenPHP), construct one client per worker rather than sharing across requests, since the underlying PSR-18 client may not be reentrant.

## Runtime support

| Runtime | Status |
| ------- | ------ |
| PHP 8.3 | Supported |
| PHP 8.4 | Supported |
| PHP 8.5 | Supported |
| PHP 8.2 and earlier | Not supported (reached EOL Dec 2025) |

The SDK is sync-only. PHP request lifecycles are typically short-lived; concurrent rendering — if you need it — is handled at the application layer (Symfony Process, ReactPHP / Amp, PHP-FPM workers).

## Requirements

- PHP 8.3 or later
- A PSR-18 HTTP client + PSR-17 factories (Guzzle, Symfony HTTP Client, etc.)

## Documentation & support

- Platform docs: [docs.poli.page](https://docs.poli.page)
- SDK docs site: [poli-page.github.io/sdk-php](https://poli-page.github.io/sdk-php/)
- Sign up & generate API keys: [app.poli.page](https://app.poli.page)
- Issues: [github.com/poli-page/sdk-php/issues](https://github.com/poli-page/sdk-php/issues)

## License

[MIT](LICENSE) © Poli Page
