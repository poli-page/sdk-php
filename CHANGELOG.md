# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Conformance with the Poli Page API contract v1.3.0
([poli-page/api-contract](https://github.com/poli-page/api-contract)). See
[MIGRATION.md](MIGRATION.md#from-0100-to-the-next-release-unreleased).

### Changed

- **Breaking:** `DOWNLOAD_FAILED` is never retryable, whatever the storage status
  (was retryable for a storage 5xx or 429). Contract v1.3.0.
- **Breaking:** `QUOTA_EXCEEDED` and `OVERAGE_CAP_EXCEEDED` (429) and
  `STORAGE_ACCESS_DENIED` (502) are no longer retried, even with a
  `Retry-After` header, and `isRetryable()` now returns `false` for them
  (it returned `true` for any 429 or 5xx). They last until the monthly
  reset or until the bucket access is restored, so the SDK used to wait
  up to 30 s per retry for nothing. `RATE_LIMIT_EXCEEDED` and every other
  429 and 5xx are still retried. Code that requeues on `isRetryable()`
  stops requeueing these three.
- **Breaking:** the error predicates read `status` and `errorCode`, as the
  SDK specification defines them, instead of the exception class:
  `isAuthError()` is `status` 401 or 403, `isRateLimitError()` 429,
  `isValidationError()` 400, and `isNetworkError()` is `errorCode`
  `network_error` or `timeout`. For API errors only the never-retried
  codes above change. A `DOWNLOAD_FAILED` now follows the storage's
  status: a 401 or 403 is an auth error (an expired presigned URL gets
  403), a 429 a rate-limit error and a 400 a validation error; all three
  were `false` for every `DOWNLOAD_FAILED`. `isRetryable()` of a
  `DOWNLOAD_FAILED` is always `false`, and the SDK never retries the
  download itself.
- **Breaking:** a timeout of the presigned-URL download (`downloadPdf()`,
  the second hop of `render->pdf()` / `render->pdfStream()`) raises
  `DOWNLOAD_FAILED` (`Failed to download PDF: network error`, no status,
  not retryable) instead of a `TimeoutException` (`timeout`, retryable).
  Every download failure is now `DOWNLOAD_FAILED`.
- `Retry-After` is read as integer seconds or an HTTP-date (IMF-fixdate,
  RFC 850 or asctime) only. Any other value (`1.5`, `-1`, `soon`,
  `tomorrow`, `+1 hour`) falls back to the backoff instead of being parsed
  as a float or by `strtotime()`; an integer too large for PHP's `int`
  clamps to the 30 s cap; an HTTP-date's delay keeps sub-second precision.
  `Retry-After-Ms` is ignored, as before.
- **Breaking:** the package conflicts with symfony/http-client older than
  6.2. The SDK applies its per-request options through
  `Psr18Client::withOptions()`, which 6.2 added; without it, an install
  that bypassed the conflict gets `invalid_options` from the `PoliPage`
  constructor rather than requests without their timeout. Symfony 5.4 to 6.1 users upgrade
  symfony/http-client, or inject Guzzle.
- The SDK's `timeout` now applies with Symfony HttpClient: each attempt
  gets `max_duration` (the total bound) and Symfony's idle `timeout` set
  to it, through a `withOptions()` copy of your client kept per option set
  (at most 16 per client, the least recently used dropped first). Before, the option had no effect with Symfony: an attempt waited
  the client's own idle timeout (`default_socket_timeout`, 60 s by default)
  with no total bound, and the `timeout` error still named the SDK's
  value. A timeout is now also recognised by the exception's class
  (Guzzle 8's typed exceptions, Symfony's `TimeoutException`) and by
  Symfony's "Max duration" message, and no longer by any message that
  merely contains "timeout" (a host named `timeout.example.com`).
- The backoff's random factor is in `[0.5, 1.5)` (it could reach 1.5).
- SDK error messages are the exact texts of the SDK specification:
  - `network_error`: `Network request failed` (was the HTTP library's
    exception text); that exception is still `getPrevious()`.
  - `timeout`: `Request timed out after <ms>ms`, the attempt's timeout in
    milliseconds (was `Request timed out after <s>s: <library text>`).
  - `DOWNLOAD_FAILED`: `Failed to download PDF: HTTP <status>` (was
    `Failed to download PDF: <status> <reason phrase>`), and
    `Failed to download PDF: network error` when the download gets no
    complete answer (was the HTTP library's text, now `getPrevious()`).
  - An error answer whose body is not JSON: `HTTP <status>` (was
    `HTTP <status>: response body was not valid JSON`); the code is still
    `INTERNAL_ERROR` with the real status.
  - `PROJECT_REQUIRED_FOR_DOCUMENT` raised before any request:
    `project is required` (was
    `project is required for render.document / render.pdf / render.pdfStream`).
- An API answer's whole body is read inside its attempt. With a client
  that streams the body lazily (Symfony HttpClient), a 2xx cut mid-body
  used to surface after the retry loop, as a PHP warning followed by the
  PSR-7 stream's raw `RuntimeException` (not a `PoliPageException`, not
  retried); it is now a
  `network_error` (`Network request failed`, the read error as
  `getPrevious()`), or a `timeout` when the body stalls past the client's
  timeout, and both are retried. A render whose 2xx answer is cut was
  already stored by the API, so its retry can store a second document.
  With Guzzle the body was already read within the attempt.
- An error answer whose body fails while it is read is handled as a body
  that is not JSON: `INTERNAL_ERROR`, `HTTP <status>`, the real status and
  `x-request-id`, the read error as `getPrevious()`, and retried by its
  status (a 503 yes, a 400 no). It never takes a code from the bytes
  received so far.
- `onResponse` fires once the 2xx body is read, and its `durationMs`
  covers the body; it never fires for a 2xx whose body failed.

### Fixed

- A POST (`render->*`, `documents->thumbnails()`) is never resent behind
  the SDK's back. libcurl, under Guzzle's curl handler and Symfony's
  `CurlHttpClient`, sent it again on a fresh connection when it went out
  on a reused keep-alive connection that died before any answer: that
  attempt was not counted by `maxRetries` nor reported to `onRequest`,
  and as the API does not deduplicate on `Idempotency-Key`, a render
  could be stored twice. A POST now always opens a fresh connection with
  Guzzle and Symfony HttpClient, so the failure surfaces as
  `network_error` and goes through the SDK's own retry. A GET or DELETE
  may still be resent once by libcurl, which stores nothing. With Guzzle
  the client's own `curl` options still apply; with Symfony HttpClient the
  SDK's POSTs carry their own `extra.curl`, which Symfony puts ahead of an
  `extra.curl` default of your client.
- A failure while the PDF body is read no longer escapes as the HTTP
  library's exception or a PHP warning: `render->pdf()`, `downloadPdf()`
  and reads of the `render->pdfStream()` stream raise `DOWNLOAD_FAILED`
  with `Failed to download PDF: network error` and the read error as
  `getPrevious()`.
- A retry wait is never shorter than its delay: when `usleep()` returns
  early, the SDK sleeps again for the rest, on the monotonic clock.
- Docs: the retries page and the README no longer claim the API
  deduplicates on `Idempotency-Key`. It does not: the key is stable
  across the retries of one call, and a render retried after a late 5xx,
  a `timeout` or a `network_error` can store a second document. The
  `downloadPdf()` docblock no longer promises a `TimeoutException`, and
  says what a `null` timeout means: the injected client's own timeout,
  none at all with Guzzle's defaults.

### Added

- Known error code `TEMPLATE_UNSAFE` (400: the template, a partial or an
  SVG asset holds an injection attempt; the message names it; not
  retried), next to `TEMPLATE_ERROR`.
- `tests/Contract` runs every SDK case of the API contract against a
  local mock server, through Guzzle and Symfony HttpClient; CI pins the
  contract by commit (`poli-page/api-contract` action) and Dependabot
  proposes each new version. Run it locally with
  `POLI_CONTRACT=<path to contract.json> composer test`.

## [0.10.0] - 2026-10-27

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
