# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `tests/Contract` reads the contract's format 2 and checks `expect.minCallMs`:
  a case a timeout ends fails when the whole call, timed on `hrtime()`, lasts
  less than its bound. A case that sets the bound but is stopped at a retry
  event fails the run instead of skipping the check. CI pins the API contract
  v0.15.0-dev.1.

### Fixed

- A timeout never ends before its bound. The SDK hands Guzzle and Symfony
  HTTP Client its timeout rounded up to a whole millisecond: Guzzle and cURL
  drop the fraction of a millisecond, so a `timeout` of 1.003 s armed 1002 ms.
  The wait for the first byte of `render->pdfStream()` and `renderToFile()`
  arms what is left of its budget rounded up to a whole millisecond (PHP waits
  on a socket for whole milliseconds, so it could end up to 1 ms early), and
  a wait that still ends before the deadline waits again for the rest.
- A `timeout` that is not a finite number of seconds up to 1e9 (about 31
  years), such as `INF`, `NAN` or `1e16`, sets no timeout, as 0 does: your
  HTTP client's own default applies, with Guzzle and Symfony HTTP Client
  alike. Before, PHP wrapped it when it turned it into whole milliseconds or
  nanoseconds: with Guzzle the call failed at once with `network_error`, and
  with Symfony some of these values timed out at once.

## [0.13.1] - 2026-11-04

`renderToFile()` and the `render->pdfStream()` timeout now follow the same
rules as the other Poli Page SDKs. Still the Poli Page API contract v0.13.0.

### Changed

- **Breaking:** `renderToFile()` refuses, with `io_error` and before any API
  call, an existing target that is not a regular file (a directory, a FIFO,
  a socket, a device such as `/dev/stdout` when stdout is a pipe or a
  terminal), with the message
  `Failed to write PDF: <path>: not a regular file`. 0.13.0 rendered first,
  then replaced a FIFO or a device with a regular file, or failed on a
  directory after the render. To write the PDF to a pipe or to stdout, copy
  `render->pdfStream()` into it.
- **Breaking:** `renderToFile()` refuses, with `io_error` and before any API
  call, an existing target that is not writable (a read-only file), and
  leaves it untouched. 0.13.0 replaced it when its directory was writable.
  The check reads the file's attributes, so a process running as root may
  still replace a read-only file.
- `renderToFile()` follows a symbolic link (or a chain of them) to the file
  it points to, writes that file and leaves the link a link. A relative link
  is read against the real directory of the link, as the file system does.
  0.13.0 replaced the link itself with a regular file. A link loop is
  `io_error`.
- `renderToFile()` keeps the permission bits of an existing target. Not
  kept, since the PDF replaces the file with a new one: its owner and group
  (they become those of the calling process), its other hard links (they
  keep the old content), and its setuid, setgid and sticky bits. A new file
  gets the default mode of your umask, as before.
- `renderToFile()`'s `io_error` message names the path you passed, never the
  temporary file: `Failed to write PDF: <path>: <reason>`, the reason being
  the system's own text (`Permission denied`, say). PHP's full error is still
  `getPrevious()`.
- `render->pdfStream()` and `renderToFile()`: the wait for the answer's
  headers and the wait for the first byte of the PDF share one `timeout`,
  from the start of the download request; after that, the timeout bounds
  each gap between two chunks, as before. 0.13.0 gave each of the two first
  waits a whole timeout of its own. This applies with Guzzle and Symfony
  HTTP Client, with three exceptions:
  - with Guzzle, response headers that trickle in, each gap within the
    timeout, are reported as a timeout only once they are complete: Guzzle
    reads them inside PHP's HTTP stream wrapper, which bounds only each gap
    and offers no hook to stop it earlier. Symfony HTTP Client stops them
    once the budget is gone;
  - a body Guzzle 7 decodes (a compressed answer) and a Symfony
    `Psr18Client` whose PSR-7 streams come from another library than
    guzzlehttp/psr7 or nyholm/psr7 keep a whole timeout for the first byte.

## [0.13.0] - 2026-11-02

Conformance with the Poli Page API contract v0.13.0
([poli-page/api-contract](https://github.com/poli-page/api-contract)). The
SDK now shares its major.minor with the Poli Page API, so it moves from 0.11
to 0.13. See [MIGRATION.md](MIGRATION.md#from-0110-to-0130).

### Added

- `invalid_response`: a 2xx answer whose body is not JSON, from a method that
  expects JSON (the render call of `render->document()`, `render->pdf()` and
  `render->pdfStream()`, `documents->get()`, `documents->thumbnails()`), raises
  `PoliPage\Exception\InvalidResponseException` with the code
  `invalid_response`, the message `Invalid response: HTTP <status> body is not
  JSON`, the real 2xx as `status`, the answer's `x-request-id` as `requestId`,
  and the JSON error as `getPrevious()`. It is never retried, `isRetryable()`
  is `false`, and `onError` fires with it. Such a body usually comes from a
  proxy or a captive portal between the SDK and the API.
- `io_error`: `renderToFile()` raises it when it cannot write the file
  (creating a parent directory, opening, writing or moving it into place).
  No status, the message `Failed to write PDF: <reason>` with PHP's own error
  text, that error as `getPrevious()`, never retried.
- `toPayload()` answers `status` 502 for `invalid_response`. The error's own
  `status` keeps the real 2xx.
- `scripts/release.sh` and the Release workflow check that the API contract
  pinned for the tests has the release's major.minor.

### Changed

- **Breaking:** a 2xx whose body is not JSON was `INTERNAL_ERROR`
  (`API returned malformed JSON: …`); it is now `invalid_response` (above).
- **Breaking:** `renderToFile()` failures to write the file were
  `invalid_options` with messages of their own; they are now `io_error`.
- `renderToFile()` writes to a temporary file in the target's directory and
  renames it over the target only once the PDF is complete. When anything
  fails (the render, the download or the write), the target is left as it
  was: an existing file keeps its content and a missing one is not created.
  The directory and the temporary file are prepared before the render, so a
  path that cannot be written costs no render.
- The presigned-URL download is bounded by the client's `timeout` (or the
  call's `RenderInput::$timeout` / `downloadPdf($timeout)`). Before, it had no
  bound unless the call passed one, and Guzzle's defaults could wait forever.
  For `render->pdf()` and `downloadPdf()`, the timeout bounds the whole
  download. For `render->pdfStream()`, it is an idle timeout: the wait for the
  first byte and each gap between two chunks, so a large PDF read at the
  caller's pace is not cut off (before, an explicit timeout bounded the whole
  stream with Symfony HttpClient).
- A download that outlasts its timeout raises `DOWNLOAD_FAILED` with the
  message `Failed to download PDF: timeout` (was
  `Failed to download PDF: network error`). No status, not retryable.
- With Guzzle, the download of `render->pdfStream()` and `renderToFile()` now
  goes through Guzzle's stream handler (PHP's HTTP stream wrapper, part of
  Guzzle's default handler stack) instead of cURL, so the PDF streams instead
  of being buffered and the idle timeout applies. Two consequences: cURL
  options set on your Guzzle client (`curl` config: CA bundle, `CURLOPT_RESOLVE`,
  client certificates, interface binding) do not apply to that download, and
  it needs PHP's `allow_url_fopen`. Without `allow_url_fopen`, Guzzle keeps
  cURL, buffers the PDF, and the timeout bounds the whole download.
  `render->pdf()` and `downloadPdf()` still use cURL.
- A download whose connection closes before the announced `Content-Length`
  raises `DOWNLOAD_FAILED` (`Failed to download PDF: network error`) with
  every HTTP client, and `renderToFile()` then leaves the target untouched.
- With a `timeout` of 0 or less, the SDK sets no timeout, for API calls and
  downloads alike, and your HTTP client's own default applies: none for
  Guzzle over cURL, PHP's `default_socket_timeout` (60 s by default) for
  Symfony HTTP Client and for Guzzle 7's streamed download. Before, 0 timed
  out at once with Symfony, and a negative value failed with Guzzle.

## [0.11.0] - 2026-10-30

Conformance with the Poli Page API contract v1.3.0
([poli-page/api-contract](https://github.com/poli-page/api-contract)). See
[MIGRATION.md](MIGRATION.md#from-0100-to-0110).

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

[Unreleased]: https://github.com/poli-page/sdk-php/compare/v0.13.1...HEAD
[0.13.1]: https://github.com/poli-page/sdk-php/compare/v0.13.0...v0.13.1
[0.13.0]: https://github.com/poli-page/sdk-php/compare/v0.11.0...v0.13.0
[0.11.0]: https://github.com/poli-page/sdk-php/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/poli-page/sdk-php/compare/v0.9.0...v0.10.0
[0.9.0]: https://github.com/poli-page/sdk-php/releases/tag/v0.9.0
