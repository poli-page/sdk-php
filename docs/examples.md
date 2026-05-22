# Examples

A walkthrough of every public method in the SDK, mirroring the runnable demo at [`examples/demo.php`](https://github.com/poli-page/sdk-php/blob/main/examples/demo.php). All snippets use project mode against the `getting-started/welcome/1.0.0` template that ships with every Poli Page org — clone the repo, set `POLI_PAGE_API_KEY`, and run:

```bash
composer install
php examples/demo.php
```

The output lands in `examples/output/`. Each section below explains what the corresponding step does and the shape of the data you get back.

## Setting up the client

```php
use PoliPage\Events\RetryEvent;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\ProjectModeInput;

$client = new PoliPage(
    apiKey: $_ENV['POLI_PAGE_API_KEY'],
    onRetry: fn (RetryEvent $e) => error_log(sprintf(
        'retrying attempt %d after %.0fms: %s',
        $e->attempt, $e->delayMs, $e->reason->errorCode,
    )),
    onError: fn (PoliPageException $e) => error_log(sprintf(
        'terminal failure: %s (status=%s, requestId=%s)',
        $e->errorCode, $e->status ?? 'n/a', $e->requestId ?? 'n/a',
    )),
);

$input = new ProjectModeInput(
    project: 'getting-started',
    template: 'welcome',
    version: '1.0.0',
    data: ['name' => 'SDK Demo'],
);
```

The `onRetry` and `onError` hooks are optional — pass them when you want observability without wiring a PSR-3 logger.

## 1. `render->pdf()` — PDF bytes in memory

The simplest path: hand back the raw bytes.

```php
$pdf = $client->render->pdf($input);
file_put_contents('render.pdf', $pdf);
```

Good for small documents (invoices, single-page reports) where buffering the whole PDF in memory is fine.

## 2. `render->pdfStream()` — PSR-7 stream of PDF bytes

When the document is large, ask for a stream instead and copy it to your destination in chunks.

```php
$stream = $client->render->pdfStream($input);

$handle = fopen('stream.pdf', 'wb');
while (!$stream->eof()) {
    fwrite($handle, $stream->read(8192));
}
fclose($handle);
$stream->close();
```

The stream is a standard `Psr\Http\Message\StreamInterface`, so any PSR-7-aware destination works — a file, an HTTP response body, or an S3 multipart upload.

## 3. `renderToFile()` — render straight to disk

A free function that wraps `pdfStream()` for the common "save it to a path" case.

```php
use function PoliPage\renderToFile;

renderToFile($client, $input, './file.pdf');
```

Bounded memory (8 KB chunks) regardless of document size.

## 4. `render->preview()` — paginated HTML preview

Render to paginated HTML instead of PDF. Useful for in-editor previews where you want to inspect the layout without producing a stored document.

```php
$preview = $client->render->preview($input);

echo "{$preview->totalPages} page(s) in {$preview->environment} mode\n";
file_put_contents('render-preview.html', $preview->html);
```

`render->preview` also accepts inline HTML via `InlineModeInput` — the other render methods require project mode.

## 5. `render->document()` — render and return the descriptor

When you want the document stored but don't need to download the bytes right now, ask for just the descriptor.

```php
$doc = $client->render->document($input);

echo "documentId: {$doc->documentId}\n";
echo "pageCount: {$doc->pageCount}, sizeBytes: {$doc->sizeBytes}\n";
```

The descriptor exposes `documentId`, `pageCount`, `sizeBytes`, `presignedPdfUrl`, `metadata`, and more. Save the `documentId` in your DB; download bytes later via step 6.

## 6. `documents->get($id)` — re-fetch with a fresh presigned URL

The `presignedPdfUrl` returned by `render->document()` has a 15-minute TTL. To download later, re-fetch the descriptor:

```php
$refetched = $client->documents->get($doc->documentId);

echo "environment: {$refetched->environment}\n";
echo "expiresAt: {$refetched->expiresAt}\n";

$bytes = $refetched->downloadPdf();
```

If `downloadPdf()` fails with `errorCode: 'DOWNLOAD_FAILED'` (HTTP 403 from S3), call `documents->get($id)` again to refresh and retry.

## 7. `documents->thumbnails($id, $opts)` — page thumbnails

Generate base64-encoded thumbnails for every page of a stored document. **Starter+ tier required.**

```php
use PoliPage\ThumbnailOptions;

try {
    $thumbs = $client->documents->thumbnails(
        $doc->documentId,
        new ThumbnailOptions(width: 420, format: 'png'),
    );

    foreach ($thumbs as $thumb) {
        file_put_contents(
            "thumbnail-{$thumb->page}.png",
            base64_decode($thumb->data, true),
        );
    }
} catch (PoliPageException $e) {
    // Quietly skip on free tier — the rest of your flow still works.
}
```

Each thumbnail exposes `page`, `width`, `height`, `contentType`, and `data` (base64 string).

## 8. `documents->preview($id)` — stored document HTML, no engine work

Re-render the paginated HTML for a stored document without re-running the template engine. Cheaper than `render->preview()` because no rendering happens server-side.

```php
$docPreview = $client->documents->preview($doc->documentId);

echo "{$docPreview->pageCount} page(s)\n";
file_put_contents('document-preview.html', $docPreview->html);
```

## 9. `documents->delete($id)` — soft-delete

Mark the document as deleted. The PDF bytes are purged from storage; metadata is retained for audit.

```php
$client->documents->delete($doc->documentId);
```

## 10. Error handling — inspecting a `PoliPageException`

Every exception in the SDK extends `PoliPageException` and exposes machine-readable fields for logging, alerting, and conditional retries.

```php
use PoliPage\Exception\BadRequestException;

try {
    $client->render->pdf(new ProjectModeInput(
        project: 'getting-started',
        template: 'welcome',
        version: 'banana', // intentionally invalid
        data: [],
    ));
} catch (BadRequestException $e) {
    printf(
        "errorCode:         %s\n" .
        "status:            %d\n" .
        "requestId:         %s\n" .
        "isAuthError:       %s\n" .
        "isValidationError: %s\n" .
        "isRetryable:       %s\n",
        $e->errorCode,
        (int) $e->status,
        $e->requestId ?? 'n/a',
        $e->isAuthError() ? 'true' : 'false',
        $e->isValidationError() ? 'true' : 'false',
        $e->isRetryable() ? 'true' : 'false',
    );
}
```

The `errorCode` is the stable API error identifier (e.g. `INVALID_VERSION_FORMAT`); always log it alongside `requestId` so support can trace the call end-to-end.

## See also

- [Quickstart](quickstart.md) — the minimum to get your first render running.
- [PSR-18 setup](psr18-setup.md) — picking the right HTTP client.
- [Migration guide](migration.md) — upgrading from earlier versions.
