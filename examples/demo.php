<?php

declare(strict_types=1);

/**
 * poli-page/sdk — runnable end-to-end demo.
 *
 * Walks through every public method of the SDK and writes the artefacts to
 * `examples/output/`. Uses the `getting-started/welcome/1.0.0` template
 * that's auto-provisioned in every Poli Page org, so this works out of the
 * box for any newcomer with a fresh API key.
 *
 * Run:  composer install && php examples/demo.php
 *
 * The SDK auto-discovers `symfony/http-client` via php-http/discovery (it's
 * in this project's `require-dev`); in your own application install any
 * PSR-18 client of your choice and discovery will pick it up the same way.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_shared.php';

use PoliPage\Exception\BadRequestException;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\ProjectModeInput;
use PoliPage\ThumbnailOptions;

use function PoliPage\Examples\bold;
use function PoliPage\Examples\cyan;
use function PoliPage\Examples\dim;
use function PoliPage\Examples\ensureApiKey;
use function PoliPage\Examples\fileLink;
use function PoliPage\Examples\green;
use function PoliPage\Examples\red;
use function PoliPage\Examples\resolveBaseUrl;
use function PoliPage\Examples\step;
use function PoliPage\Examples\yellow;
use function PoliPage\renderToFile;

const OUT_DIR = __DIR__ . '/output';
const TOTAL_STEPS = 10;

if (!is_dir(OUT_DIR) && !mkdir(OUT_DIR, 0o755, true) && !is_dir(OUT_DIR)) {
    fwrite(\STDERR, "Failed to create output directory: " . OUT_DIR . "\n");
    exit(1);
}

$apiKey = ensureApiKey();
$baseUrl = resolveBaseUrl();

// Every render call uses project mode — `render->pdf`, `render->pdfStream`,
// `render->document`, and `renderToFile` all require it. `render->preview`
// also accepts inline-mode HTML; we use project mode here for parity with
// the document-producing methods.
$projectInput = new ProjectModeInput(
    project: 'getting-started',
    template: 'welcome',
    version: '1.0.0',
    data: ['name' => 'SDK Demo'],
);

// Construct the client once; hooks are optional and never block the request.
$client = new PoliPage(
    apiKey: $apiKey,
    baseUrl: $baseUrl,
    onRetry: static fn (\PoliPage\Events\RetryEvent $e) => print(
        yellow('  ↻') . ' ' . dim(sprintf(
            'retrying attempt %d after %.0fms: %s',
            $e->attempt,
            $e->delayMs,
            $e->reason->errorCode,
        )) . "\n"
    ),
    onError: static fn (PoliPageException $e) => print(
        red('  ↯') . ' ' . dim(sprintf(
            'terminal failure: %s (status=%s, requestId=%s)',
            $e->errorCode,
            $e->status ?? 'n/a',
            $e->requestId ?? 'n/a',
        )) . "\n"
    ),
);

// ─────────────────────────────────────────────────────────────────────────────
// 1. render->pdf() — fetch PDF bytes into memory
// ─────────────────────────────────────────────────────────────────────────────
step(1, TOTAL_STEPS, 'render->pdf() — PDF bytes in memory');
$pdf = $client->render->pdf($projectInput);
$renderPath = OUT_DIR . '/render.pdf';
file_put_contents($renderPath, $pdf);
echo "  " . strlen($pdf) . " bytes, magic: " . bold(substr($pdf, 0, 4)) . "\n";
echo "  " . dim('open:') . ' ' . fileLink($renderPath) . "\n";

// ─────────────────────────────────────────────────────────────────────────────
// 2. render->pdfStream() — get a PSR-7 stream of PDF bytes
// ─────────────────────────────────────────────────────────────────────────────
step(2, TOTAL_STEPS, 'render->pdfStream() — PSR-7 stream of PDF bytes');
$stream = $client->render->pdfStream($projectInput);
$streamPath = OUT_DIR . '/stream.pdf';
$handle = fopen($streamPath, 'wb');
$streamed = 0;
if ($handle !== false) {
    while (!$stream->eof()) {
        $chunk = $stream->read(8192);
        if ($chunk === '') {
            break;
        }
        $streamed += strlen($chunk);
        fwrite($handle, $chunk);
    }
    fclose($handle);
}
$stream->close();
echo "  {$streamed} bytes streamed\n";
echo "  " . dim('open:') . ' ' . fileLink($streamPath) . "\n";

// ─────────────────────────────────────────────────────────────────────────────
// 3. renderToFile() — convenience helper around pdfStream (free function)
// ─────────────────────────────────────────────────────────────────────────────
step(3, TOTAL_STEPS, 'renderToFile() — render straight to disk');
$filePath = OUT_DIR . '/file.pdf';
renderToFile($client, $projectInput, $filePath);
echo "  wrote {$filePath}\n";
echo "  " . dim('open:') . ' ' . fileLink($filePath) . "\n";

// ─────────────────────────────────────────────────────────────────────────────
// 4. render->preview() — paginated HTML preview output
// ─────────────────────────────────────────────────────────────────────────────
step(4, TOTAL_STEPS, 'render->preview() — paginated HTML preview');
$preview = $client->render->preview($projectInput);
$previewPath = OUT_DIR . '/render-preview.html';
file_put_contents($previewPath, $preview->html);
echo "  " . bold((string) $preview->totalPages) . ' page(s), '
    . strlen($preview->html) . " chars (env: {$preview->environment})\n";
echo "  " . dim('open:') . ' ' . fileLink($previewPath) . "\n";

// ─────────────────────────────────────────────────────────────────────────────
// 5. render->document() — store the document, return the descriptor
// ─────────────────────────────────────────────────────────────────────────────
step(5, TOTAL_STEPS, 'render->document() — store the document, return the descriptor');
$doc = $client->render->document($projectInput);
echo '  ' . dim('documentId:') . ' ' . bold($doc->documentId) . "\n";
echo '  ' . dim('pageCount:') . ' ' . $doc->pageCount
    . ', ' . dim('sizeBytes:') . ' ' . $doc->sizeBytes . "\n";

// ─────────────────────────────────────────────────────────────────────────────
// 6. documents->get(id) — re-fetch the descriptor with a fresh presigned URL
// ─────────────────────────────────────────────────────────────────────────────
step(6, TOTAL_STEPS, 'documents->get(id) — re-fetch with a fresh presigned URL');
$refetched = $client->documents->get($doc->documentId);
echo '  ' . dim('environment:') . ' ' . $refetched->environment . "\n";
echo '  ' . dim('expiresAt:') . ' ' . $refetched->expiresAt . "\n";

// ─────────────────────────────────────────────────────────────────────────────
// 7. documents->thumbnails(id, opts) — generate page thumbnails (Starter+ tier)
// ─────────────────────────────────────────────────────────────────────────────
step(7, TOTAL_STEPS, 'documents->thumbnails(id) — page thumbnails (Starter+ tier)');
try {
    $thumbs = $client->documents->thumbnails(
        $doc->documentId,
        new ThumbnailOptions(width: 420, format: 'png'),
    );
    foreach ($thumbs as $thumb) {
        $thumbPath = OUT_DIR . '/thumbnail-' . $thumb->page . '.png';
        file_put_contents($thumbPath, base64_decode($thumb->data, true) ?: '');
        echo '  ' . dim('page ' . $thumb->page . ':') . ' '
            . $thumb->width . 'x' . $thumb->height
            . ' (' . $thumb->contentType . ') → ' . fileLink($thumbPath) . "\n";
    }
} catch (PoliPageException $e) {
    echo '  ' . yellow('!') . ' skipped (' . $e->errorCode . ', status=' . ($e->status ?? 'n/a') . ")\n";
    echo '  ' . dim('  thumbnails require a Starter+ tier API key; rest of the demo continues.') . "\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// 8. documents->preview(id) — stored document HTML, no engine work
// ─────────────────────────────────────────────────────────────────────────────
step(8, TOTAL_STEPS, 'documents->preview(id) — stored HTML, no engine work');
$docPreview = $client->documents->preview($doc->documentId);
$docPreviewPath = OUT_DIR . '/document-preview.html';
file_put_contents($docPreviewPath, $docPreview->html);
echo '  ' . bold((string) $docPreview->pageCount) . ' page(s), '
    . strlen($docPreview->html) . " chars\n";
echo "  " . dim('open:') . ' ' . fileLink($docPreviewPath) . "\n";

// ─────────────────────────────────────────────────────────────────────────────
// 9. documents->delete(id) — soft-delete; the PDF is purged, metadata kept
// ─────────────────────────────────────────────────────────────────────────────
step(9, TOTAL_STEPS, 'documents->delete(id) — soft-delete the document');
$client->documents->delete($doc->documentId);
echo '  ' . green('✔') . ' deleted ' . $doc->documentId . "\n";

// ─────────────────────────────────────────────────────────────────────────────
// 10. Error handling — DELIBERATELY trigger a 400 INVALID_VERSION_FORMAT
// ─────────────────────────────────────────────────────────────────────────────
step(10, TOTAL_STEPS, 'error handling — DEMO ONLY (we trigger an error on purpose)');
echo yellow('  ⚠  This step is intentional — the SDK is about to throw, but the') . "\n";
echo yellow('     demo will catch and inspect it. ') . bold('The demo is NOT crashing.') . "\n";
echo dim('     (We send an invalid version string, expecting 400 INVALID_VERSION_FORMAT.)') . "\n\n";

try {
    $client->render->pdf(new ProjectModeInput(
        project: 'getting-started',
        template: 'welcome',
        version: 'banana', // intentionally invalid
        data: [],
    ));
    echo '  ' . red('✗ unexpected: the call succeeded but should have failed') . "\n";
} catch (BadRequestException $e) {
    echo '  ' . green('✔') . " Caught BadRequestException — PoliPageException exposes:\n";
    printf(
        "       errorCode:  %s\n       status:     %d\n       requestId:  %s\n",
        $e->errorCode,
        (int) $e->status,
        $e->requestId ?? 'n/a',
    );
    printf(
        "       isAuthError:       %s\n       isValidationError: %s\n       isRetryable:       %s\n",
        $e->isAuthError() ? 'true' : 'false',
        $e->isValidationError() ? 'true' : 'false',
        $e->isRetryable() ? 'true' : 'false',
    );
}

echo "\n" . green('✔') . ' ' . bold('All steps completed.')
    . ' Inspect output in ' . fileLink(OUT_DIR) . "\n";
