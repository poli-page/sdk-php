# Migration Guide

This file documents breaking changes between major versions of `poli-page/sdk`.
Follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html): breaking
changes only ship in major version bumps and always come with an entry here.

## 1.0

The first stable release. No prior published versions exist on Packagist.

### Surface

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

### Auto-provisioned `getting-started/welcome` template

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

See [CHANGELOG.md](CHANGELOG.md) for the full per-feature list.
