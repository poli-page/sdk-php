# Poli Page SDK for PHP

[![Packagist](https://img.shields.io/packagist/v/poli-page/sdk?style=flat&logo=php&logoColor=ffffff&label=Packagist)](https://packagist.org/packages/poli-page/sdk)
[![Downloads](https://img.shields.io/packagist/dt/poli-page/sdk?style=flat&logo=php&logoColor=ffffff&label=Downloads)](https://packagist.org/packages/poli-page/sdk)
[![Ci](https://img.shields.io/github/actions/workflow/status/poli-page/sdk-php/ci.yml?branch=main&style=flat&logo=githubactions&logoColor=ffffff&label=Ci)](https://github.com/poli-page/sdk-php/actions/workflows/ci.yml)
[![Codeql](https://img.shields.io/github/actions/workflow/status/poli-page/sdk-php/codeql.yml?branch=main&style=flat&logo=github&logoColor=ffffff&label=Codeql)](https://github.com/poli-page/sdk-php/actions/workflows/codeql.yml)
[![Coverage](https://img.shields.io/codecov/c/github/poli-page/sdk-php?style=flat&logo=codecov&logoColor=ffffff&label=Coverage)](https://codecov.io/gh/poli-page/sdk-php)
[![Php](https://img.shields.io/packagist/php-v/poli-page/sdk?style=flat&logo=php&logoColor=ffffff&label=Php)](https://packagist.org/packages/poli-page/sdk)
[![Phpstan](https://img.shields.io/badge/Phpstan-max-blue?style=flat&logo=php&logoColor=ffffff)](phpstan.neon)
[![Docs](https://img.shields.io/badge/Docs-online-brightgreen?style=flat&logo=readthedocs&logoColor=ffffff)](https://poli-page.github.io/sdk-php/)
[![License](https://img.shields.io/packagist/l/poli-page/sdk?style=flat&logo=gnu&logoColor=ffffff&label=License)](LICENSE)

Official PHP SDK for [Poli Page](https://poli.page) — render polished PDFs from HTML templates via the Poli Page API.

→ Docs (auto-generated from source): **https://poli-page.github.io/sdk-php/**

## Install

```bash
composer require poli-page/sdk
```

The SDK declares only [PSR-18](https://www.php-fig.org/psr/psr-18/) / [PSR-17](https://www.php-fig.org/psr/psr-17/) / [PSR-3](https://www.php-fig.org/psr/psr-3/) interfaces plus [`php-http/discovery`](https://github.com/php-http/discovery) as hard dependencies — pick any concrete HTTP client and PSR-7 implementation you like. The most common pairings:

```bash
# Guzzle (~80% of PHP apps)
composer require guzzlehttp/guzzle guzzlehttp/psr7

# Symfony HTTP Client
composer require symfony/http-client nyholm/psr7

# Lightweight, no curl needed
composer require php-http/curl-client nyholm/psr7
```

Discovery auto-detects whichever you've installed.

Requires PHP 8.3 or later.

## Quick start

### Project mode — render a published template by slug

```php
use PoliPage\PoliPage;
use PoliPage\ProjectModeInput;

$client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);

$pdf = $client->render->pdf(new ProjectModeInput(
    project: 'getting-started',
    template: 'welcome',
    version: '1.0.0',
    data: ['name' => 'World'],
));
// $pdf is a string of raw PDF bytes
```

Every Poli Page org comes pre-provisioned with a `getting-started/welcome` template, so the snippet above runs as-is the moment you have an API key — no project setup needed. For your own templates, swap the slugs once you've pushed a version with the `poli` CLI:

```php
$pdf = $client->render->pdf(new ProjectModeInput(
    project: 'billing',
    template: 'invoice',
    version: '1.0.0',
    data: ['invoiceNumber' => 'INV-001', 'total' => 1280],
));
```

### Preview inline HTML

`render->preview` accepts raw HTML for live editing and visual inspection without producing a stored document. Use this for editor previews or layout tests.

```php
use PoliPage\InlineModeInput;

$result = $client->render->preview(new InlineModeInput(
    template: '<h1>Hello {{ name }}</h1>',
    data: ['name' => 'World'],
));
echo "Rendered {$result->totalPages} page(s) in {$result->environment} mode\n";
```

**`render->pdf`, `render->pdfStream`, and `render->document` require project mode** — `project` + `template`, optionally pinned to a specific `version` (omit to render the current draft). Inline HTML is only accepted by `render->preview`. The SDK enforces this via PHP's type system: `ProjectModeInput` and `InlineModeInput` are final readonly classes extending a sealed `RenderInput` base; the three document-producing methods type-hint `ProjectModeInput`, so passing inline mode is a `TypeError`. PHPStan / Psalm also catch the mismatch statically.

### Write a PDF to disk

```php
use PoliPage\PoliPage;
use PoliPage\ProjectModeInput;

use function PoliPage\renderToFile;

$client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);
renderToFile(
    $client,
    new ProjectModeInput(
        project: 'getting-started',
        template: 'welcome',
        version: '1.0.0',
        data: ['name' => 'World'],
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

First run prompts for a `pp_test_*` key and saves it to `.env`. Subsequent runs are silent. See `examples/demo.php` for the full walkthrough.

### Stream — for large PDFs or piping to S3 / HTTP responses

```php
$stream = $client->render->pdfStream(new ProjectModeInput(
    project: 'billing',
    template: 'invoice',
    version: '1.0.0',
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
$doc = $client->render->document(new ProjectModeInput(
    project: 'billing',
    template: 'invoice',
    version: '1.0.0',
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

The mode is determined by the API key prefix:

- `pp_test_…` → sandbox mode (not billed, generous rate limits)
- `pp_live_…` → live mode (billed, production rate limits)
- `pp_sa_…` → service-account keys; environment matches the SA's configuration (sandbox or live)

All prefixes hit the same endpoint (`https://api.poli.page`). The SDK passes the key through as a Bearer token and never inspects the prefix — pick whichever fits your deploy model.

## Methods

| Method | Returns | Description |
| ------ | ------- | ----------- |
| `$client->render->pdf($input)` | `string` | Render a PDF, return raw bytes |
| `$client->render->pdfStream($input)` | `Psr\Http\Message\StreamInterface` | Render and stream the response |
| `$client->render->preview($input)` | `PreviewResult` | Paginated HTML preview |
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
    baseUrl: 'https://api-develop.poli.page',
    maxRetries: 3,
    timeout: 60.0,
    logger: $monolog,
);
```

| Option | Type | Default | Description |
| ------ | ---- | ------- | ----------- |
| `apiKey` | `string` | (required) | `pp_test_*` or `pp_live_*` API key |
| `baseUrl` | `?string` | `https://api.poli.page` | API base URL |
| `maxRetries` | `?int` | `2` | Max retry attempts on retryable errors |
| `retryDelay` | `?float` (seconds) | `0.5` | Base delay before the first retry |
| `timeout` | `?float` (seconds) | `60.0` | Per-request timeout hint forwarded to the PSR-18 client |
| `httpClient` | `?Psr\Http\Client\ClientInterface` | (auto-discovered) | Override the discovered PSR-18 client |
| `requestFactory` | `?Psr\Http\Message\RequestFactoryInterface` | (auto-discovered) | Override the discovered PSR-17 request factory |
| `streamFactory` | `?Psr\Http\Message\StreamFactoryInterface` | (auto-discovered) | Override the discovered PSR-17 stream factory |
| `logger` | `?Psr\Log\LoggerInterface` | `NullLogger` | PSR-3 logger for SDK debug / retry / error events |
| `onRetry` | `?\Closure(RetryEvent): void` | — | Called before each retry sleep |
| `onError` | `?\Closure(PoliPageException): void` | — | Called when a call terminates in error |

Per-call overrides live on the input object itself: pass `timeout:` and/or `idempotencyKey:` to `ProjectModeInput` / `InlineModeInput` to override the client-level defaults for that one call.

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
    $pdf = $client->render->pdf(new ProjectModeInput(...));
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
    ├── Exception\GoneException              (410 — document soft-deleted)
    ├── Exception\RateLimitException         (429)
    └── Exception\InternalServerException    (5xx)
```

Predicate helpers are kept for spec parity across SDK languages:

```php
if ($e->isAuthError())       { /* 401 or 403 */ }
if ($e->isRateLimitError())  { /* 429 */ }
if ($e->isValidationError()) { /* 400 */ }
if ($e->isNetworkError())    { /* transport-level */ }
if ($e->isRetryable())       { /* 5xx, 429, network — SDK already retried */ }
```

For lifecycle and billing failures, route the user to actionable messages rather than treating them as opaque errors:

```php
try {
    $doc = $client->render->document(new ProjectModeInput(...));
} catch (PoliPageException $e) {
    if ($e->errorCode === PoliPageException::PAYMENT_REQUIRED) {
        return showBanner('Subscription has unpaid invoices.');
    }
    if ($e->errorCode === PoliPageException::ORGANIZATION_CANCELLED) {
        return showBanner('Subscription cancelled — service is read-only.');
    }
    if ($e->errorCode === PoliPageException::DOCUMENT_NOT_FOUND) {
        return show404();
    }
    if ($e->errorCode === PoliPageException::GONE) {
        return show410();    // document was soft-deleted
    }
    throw $e;
}
```

→ Full error reference: https://poli-page.github.io/sdk-php/reference/errors/

## Cancellation

PHP has no first-class cancellation primitive (no `AbortSignal`, no `context.Context`). The SDK exposes timeouts instead:

- `timeout:` on the constructor — applied as the per-request deadline for every call.
- `timeout:` on the input object (`ProjectModeInput` / `InlineModeInput`) — overrides the client default for that one call.

PSR-18 does not standardise per-request timeouts, so the SDK forwards the value to the underlying client where possible (Guzzle, Symfony HTTP Client) and otherwise documents it as a best-effort hint. Configure connect / total timeouts on your injected PSR-18 client for guaranteed enforcement.

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

The SDK retries on **5xx**, **429**, **network errors**, and **timeouts**. Backoff is exponential (`retryDelay × 2^attempt`) with jitter in `[0.5, 1.5]`, capped by `Retry-After` when the server provides it (max 30 s). Every `POST` sends an auto-generated `Idempotency-Key` (UUID v4); pass `idempotencyKey:` in the input to override.

## Type system

The SDK is fully type-annotated and tested at **PHPStan level max** with `phpstan-strict-rules`. Every public method has explicit parameter and return types; PHPDoc array shapes are provided wherever native PHP types are insufficient.

`RenderInput` is a sealed-in-package abstract class with exactly two concrete subclasses — `ProjectModeInput` and `InlineModeInput`. The render methods type-hint the specific subclass they accept, so invalid combos (passing inline-mode HTML to `render->pdf`) fail at compile-time (PHPStan) and runtime (PHP `TypeError`).

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
