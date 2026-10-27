# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- An API key that is not a valid HTTP header value (a trailing newline
  from a `.env` value or a secret file, `\r\n` inside, NUL or another
  control character other than tab) no longer leaks into an exception
  message. Before any request is built, it now fails with a `PoliPageException`
  (`invalid_options`) whose fixed message never contains the key, with
  no previous exception, and `onError` fires once. Before, guzzlehttp/psr7
  2.x echoed the whole `Bearer` value in a raw `InvalidArgumentException`,
  and with nyholm/psr7 + Symfony HttpClient the key reached a
  `ConnectionException` message, its `toPayload()`, its previous chain
  and `onError`. A caller-supplied `idempotencyKey` is checked the same
  way: one with a trailing newline no longer reaches the transport as a
  retried `network_error` whose message echoes it. A PSR-7
  `withHeader()` rejection of a value that passes this check (by an
  implementation stricter than RFC 7230) is rethrown the same way,
  without the original message (#31).

## [0.10.0] - 2026-10-26

Environments: an API key belongs to one environment (`pp_sandbox_…`,
`pp_live_…`, `pp_staging_…`) and a render uses the project's copy
published in that environment. Breaking, with no deprecation window.

### Removed

- `ProjectModeInput::$version`: there is no version to pick and an API
  key cannot render the draft. The API answers `400 VALIDATION_ERROR` to
  a `version` field.
- `render->preview()`, `PreviewResult` and `InlineModeInput`: the API
  removed `POST /v1/render/preview` and inline-HTML rendering.
  `documents->preview($id)` (the stored HTML of a rendered document)
  stays.
- `DocumentDescriptor::$version`.
- Known error codes `VERSION_NOT_FOUND`, `INVALID_VERSION_FORMAT`,
  `VERSION_REQUIRED`, `INVALID_VERSION_FOR_KEY_ENV`,
  `MISSING_PROJECT_OR_TEMPLATE`, `GONE` (the API answers
  `410 DOCUMENT_GONE`) and `PAYMENT_REQUIRED` (payment grace is
  `403 ORGANIZATION_PAYMENT_GRACE`).

### Changed

- `ProjectModeInput` is renamed `RenderInput` and is the only render
  input; the abstract `RenderInput` base is gone. The request body holds
  only `project`, `template`, `data`, `metadata`, `format`,
  `orientation` and `locale`, with unset optional fields omitted.
- `ThumbnailOptions`: every option is optional (`width` included),
  `format` accepts `jpeg`, `webp` or `png`, and an unset option is not
  sent. `documents->thumbnails($id)` takes the options as an optional
  argument and sends `{}` without them. The SDK does not check option
  bounds; the API answers `400 VALIDATION_ERROR`.
- `DocumentDescriptor::$environment` is documented as any environment
  name, returned verbatim.
- `documents->delete($id)` is idempotent: the API answers `204` for an
  already-deleted document too.
- The README, the docs site and `examples/demo.php` follow the
  environments contract: a `sandbox` key renders `getting-started` /
  `welcome` with no version, the README carries the key table, and
  previews point to the playground at https://play.poli.page. The demo
  checks a pasted key against the full-key regex, and its last step
  renders an unknown template and prints the caught
  `404 TEMPLATE_NOT_FOUND`. See `MIGRATION.md` for the upgrade from
  0.9.x.

### Added

- Known error codes `PUBLISH_IN_PROGRESS`, `ENVIRONMENT_MISMATCH`,
  `PROJECT_NOT_IN_ENVIRONMENT`, `TEMPLATE_NOT_FOUND`, `DOCUMENT_GONE`,
  `ENVIRONMENT_PROTECTED`, `ORGANIZATION_PAYMENT_GRACE`,
  `EMPTY_DOCUMENT`, `STORAGE_ACCESS_DENIED`, `ORGANIZATION_MIGRATING`,
  `PROJECT_NOT_FOUND`, `TEMPLATE_ERROR`, `THUMBNAILS_NOT_AVAILABLE`,
  `RATE_LIMIT_EXCEEDED`, `RENDER_BODY_TOO_LARGE`, `DOCUMENT_TOO_LARGE`
  and `RENDER_TIMEOUT`. Error codes stay an open set: an unknown code is
  passed through unchanged. A `503 PUBLISH_IN_PROGRESS` (a render that
  waited on a publish) is retried like any other 5xx, honouring
  `Retry-After`.

## [0.9.0] - 2026-06-22

The first public release. The API surface is complete but not yet frozen;
breaking changes may still land before 1.0.0.

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

[Unreleased]: https://github.com/poli-page/sdk-php/compare/v0.10.0...HEAD
[0.10.0]: https://github.com/poli-page/sdk-php/compare/v0.9.0...v0.10.0
[0.9.0]: https://github.com/poli-page/sdk-php/releases/tag/v0.9.0
