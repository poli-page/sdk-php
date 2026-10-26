# Migration Guide

This file documents breaking changes between versions of `poli-page/sdk`.
The package follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html):
before 1.0.0 a breaking change ships in a minor bump (0.9.x → 0.10.0), from
1.0.0 on only in a major bump, and it always comes with an entry here.

## From 0.9.x to 0.10.0

An API key now belongs to one environment of your organization
(`pp_sandbox_…`, `pp_live_…`, `pp_staging_…`; standard and service keys
share this one format), and every render uses the project's copy
published in that environment. There is no version to pick, no API key
renders the draft, and the API no longer previews templates or renders
inline HTML. Every removal ships at once, with no deprecation window.

### Keys

Create a key per environment in the dashboard. The `pp_test_` and
`pp_sa_` prefixes are gone: use a `pp_sandbox_` key where you used a
`pp_test_` one. The SDK does not check the key client-side.

### `ProjectModeInput` → `RenderInput`, without `version`

```php
// 0.9.x
use PoliPage\ProjectModeInput;

$pdf = $client->render->pdf(new ProjectModeInput(
    project: 'billing',
    template: 'invoice',
    version: '1.0.0',
    data: ['invoiceNumber' => 'INV-001'],
));

// 0.10.0
use PoliPage\RenderInput;

$pdf = $client->render->pdf(new RenderInput(
    project: 'billing',
    template: 'invoice',
    data: ['invoiceNumber' => 'INV-001'],
));
```

To change what a key renders, publish the project to that key's
environment. Passing `version:` is now an unknown named argument (PHP
`Error`, PHPStan `argument.unknown`).

### `render->preview()` and `InlineModeInput` are removed

Preview templates and try inline HTML in the playground at
[play.poli.page](https://play.poli.page). `PreviewResult` is removed too.
`$client->documents->preview($id)`, the stored HTML of a document you
already rendered, stays.

### `DocumentDescriptor`

`$version` is removed. `$environment` is any environment name, returned
verbatim (`sandbox`, `live`, `staging`, …).

### Error codes

| 0.9.x constant | 0.10.0 |
| -------------- | ------ |
| `VERSION_NOT_FOUND`, `INVALID_VERSION_FORMAT`, `VERSION_REQUIRED`, `INVALID_VERSION_FOR_KEY_ENV` | removed: there is no version |
| `MISSING_PROJECT_OR_TEMPLATE` | removed |
| `GONE` | `DOCUMENT_GONE` (410: deleted, or a `sandbox` document older than 30 days) |
| `PAYMENT_REQUIRED` | `ORGANIZATION_PAYMENT_GRACE` (403) |
| `NOT_FOUND` on a render | `PROJECT_NOT_FOUND`, `PROJECT_NOT_IN_ENVIRONMENT` or `TEMPLATE_NOT_FOUND` |

See the [CHANGELOG](CHANGELOG.md) for every added code. Error codes stay
an open set: compare `$e->errorCode` against the constants you handle and
let the others through.

### Thumbnails

Every `ThumbnailOptions` argument is optional, `format` accepts `webp`,
and `documents->thumbnails($id)` works without options.

## 0.9.0 — first public release

The section below describes the 0.9.x surface, for reference.

### Surface (0.9.x)

```php
use PoliPage\PoliPage;
use PoliPage\ProjectModeInput;
use PoliPage\InlineModeInput;
use PoliPage\ThumbnailOptions;

use function PoliPage\renderToFile;

$client = PoliPage::client($apiKey);
// or new PoliPage(apiKey: $apiKey, baseUrl: ..., maxRetries: ..., logger: ...)

// Render namespace
// render->pdf, render->pdfStream, render->document → project mode only (project + template + version)
// render->preview → accepts both project mode and inline HTML
$client->render->pdf($projectInput);          // → string                    (two HTTP calls internally)
$client->render->pdfStream($projectInput);    // → Psr\Http\Message\StreamInterface (two HTTP calls)
$client->render->preview($input);             // → PreviewResult{html, totalPages, environment}
$client->render->document($projectInput);     // → DocumentDescriptor        (skip auto-download)

// Documents namespace
$client->documents->get($id);                 // → DocumentDescriptor
$client->documents->preview($id);             // → DocumentPreviewResult{html, pageCount}
$client->documents->thumbnails($id, $opts);   // → list<Thumbnail>
$client->documents->delete($id);              // → void

// Free function (autoloaded via composer.json "files")
renderToFile($client, $projectInput, $path);  // → void
```

### Auto-provisioned `getting-started/welcome` template (0.9.x)

Every Poli Page org is created with a `getting-started/welcome/1.0.0` template
already in place. You can call
`$client->render->pdf(new ProjectModeInput(project: 'getting-started', template: 'welcome', version: '1.0.0', data: [...]))`
the moment your API key is active — no `poli init` / `poli push` required. The
SDK Quick Start example, the demo, and the integration tests in this repo all
default to this template so they run for any new user.

### Render is always a stored document

Every `render->*` (except `render->preview`) produces a stored document
server-side. `render->pdf` and `render->pdfStream` are SDK conveniences
that chain a presigned-URL fetch internally to return PDF bytes.
`render->document` returns just the descriptor — use it when you'd rather
hold the `documentId` and fetch bytes later.

This means `render->pdf` makes two HTTP calls (`POST /v1/render` +
`GET presignedPdfUrl`). Same throughput characteristics; only network-log
visibility differs.

`render->preview` is the exception — it doesn't store and returns
paginated HTML directly. It's also the only render method that accepts
inline-mode HTML.

### Storage workflow

`render->document` is a render that **stores** the result server-side and
returns a descriptor instead of bytes. Persist `documentId` in your
database; fetch bytes on demand via `$client->documents->get($id)->downloadPdf()`.
The presigned URL is short-lived (15 min) — refresh via `documents->get`
when needed.

See [CHANGELOG.md](https://github.com/poli-page/sdk-php/blob/main/CHANGELOG.md) for the full per-feature list.
