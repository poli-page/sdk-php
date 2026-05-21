# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - TBD

The first stable release.

### Added

- `PoliPage` client — entry point for the render and documents
  namespaces. Construct via `PoliPage::client($apiKey)` for the
  one-line ergonomic, or via the named-argument constructor for
  full configuration (`apiKey`, `baseUrl`, `maxRetries`,
  `retryDelay`, `timeout`, `httpClient`, `requestFactory`,
  `streamFactory`, `logger`, `onRetry`, `onError`).
- Render namespace — `$client->render`:
  - `pdf(ProjectModeInput): string` — render and return raw bytes.
  - `pdfStream(ProjectModeInput): StreamInterface` — same as `pdf`
    but returns a PSR-7 stream for memory-bounded piping to disk /
    HTTP responses / S3.
  - `preview(RenderInput): PreviewResult` — paginated HTML preview;
    accepts both project mode and inline HTML.
  - `document(ProjectModeInput): DocumentDescriptor` — render and
    store; returns the descriptor with `presignedPdfUrl` and a
    fluent `downloadPdf()` helper.
- Documents namespace — `$client->documents`:
  - `get($id): DocumentDescriptor` — refresh a stored document's
    presigned URL.
  - `preview($id): DocumentPreviewResult` — paginated HTML +
    `pageCount` (read from the `X-Document-Page-Count` response
    header).
  - `thumbnails($id, ThumbnailOptions): list<Thumbnail>` — generate
    page thumbnails in PNG or JPEG.
  - `delete($id): void` — soft-delete a stored document.
- `PoliPage\renderToFile($client, $input, $path): void` — free
  function, autoloaded via Composer's `"files"` entry. Streams
  bytes to disk in 8 KB chunks.
- Exception hierarchy under `PoliPage\Exception`, all rooted in
  `PoliPageException`:
  - `ConnectionException` → `TimeoutException` for transport
    failures.
  - `ApiStatusException` →
    `BadRequestException` / `AuthenticationException` /
    `PermissionDeniedException` / `NotFoundException` /
    `GoneException` / `RateLimitException` /
    `InternalServerException` for the well-known statuses; any other
    4xx falls through to `ApiStatusException`.
  - Predicate helpers (`isAuthError`, `isRateLimitError`,
    `isValidationError`, `isNetworkError`, `isRetryable`) kept for
    spec parity across SDK languages.
- Sealed input hierarchy: `RenderInput` (abstract readonly base)
  with `ProjectModeInput` and `InlineModeInput` as the only
  concrete subclasses; sealing enforced via `final` children plus
  the project-mode-only constraint on the document-producing
  methods.
- `RenderMetadata` wrapper with a primitives-only guard
  (`string|int|float|bool`); rejects arrays / objects / null with
  `PoliPageException(INVALID_OPTIONS)`.
- Retry policy ported byte-for-byte from the Node reference:
  retries on 5xx + 429 + network + timeout, exponential backoff
  with jitter in `[0.5, 1.5]`, server `Retry-After` honoured
  (capped at 30 s), `maxRetries` defaulting to 2.
- Idempotency: every POST sends an auto-generated UUID v4
  `Idempotency-Key`; override via the `idempotencyKey:` input
  field.
- PSR-3 `LoggerInterface` injection for structured debug / retry /
  error events; default `NullLogger`. Auth headers are never
  logged.
- PSR-18 + PSR-17 injection with auto-discovery via
  `php-http/discovery` — pick any concrete HTTP client and PSR-7
  implementation.
- Runnable end-to-end demo at `examples/demo.php` exercising every
  public method against the develop API.

### Tooling

- PHPUnit 11 unit + integration suites; integration tests gated on
  `POLI_PAGE_API_KEY` and the `integration` group.
- PHPStan max + `phpstan-strict-rules` clean.
- PHP-CS-Fixer (`@PSR12` + `declare_strict_types`) clean.
- CI matrix: PHP 8.3 / 8.4 / 8.5 on Ubuntu plus one job each on
  macOS and Windows for 8.5. `composer validate --strict`,
  `composer audit`, lint, static analysis, and unit tests all gated.

[Unreleased]: https://github.com/poli-page/sdk-php/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/poli-page/sdk-php/releases/tag/v1.0.0
