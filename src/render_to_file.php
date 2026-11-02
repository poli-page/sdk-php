<?php

declare(strict_types=1);

namespace PoliPage;

use PoliPage\Internal\AtomicFileWriter;

/**
 * Render a PDF and stream the bytes to `$path`. Creates parent directories
 * if missing. Overwrites existing files. Memory-bounded — reads from the
 * PSR-7 body stream in 8 KB chunks regardless of document size.
 *
 * The bytes go to a temporary file in the same directory, renamed over
 * `$path` only once the PDF is complete: when anything fails, `$path` is left
 * untouched (an existing file keeps its content, a missing one is not
 * created) and the temporary file is removed. The directory and the
 * temporary file are prepared before the render, so a path that cannot be
 * written costs no render.
 *
 * Free function rather than a method so callers can use it without
 * importing a `Node`-specific helper class — matches the `renderToFile`
 * helper of the other Poli Page SDKs. The Composer autoload entry under
 * `"files"` loads this file on every request.
 *
 * @example
 * ```php
 * use PoliPage\PoliPage;
 * use PoliPage\RenderInput;
 *
 * use function PoliPage\renderToFile;
 *
 * $client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);
 * renderToFile($client, new RenderInput(
 *     project: 'billing',
 *     template: 'invoice',
 *     data: ['invoiceNumber' => 'INV-001'],
 * ), 'invoice.pdf');
 * ```
 *
 * @throws PoliPageException with code IO_ERROR (`io_error`) when the file cannot be written:
 *                                   creating a parent directory, opening, writing or renaming it
 *                                   into place. No status, the message
 *                                   `Failed to write PDF: <reason>` with PHP's own error text,
 *                                   and that error as `getPrevious()`. Never retried
 * @throws PoliPageException any failure mode of `$client->render->pdfStream($input)` propagates
 */
function renderToFile(PoliPage $client, RenderInput $input, string $path): void
{
    $file = AtomicFileWriter::open($path);
    $stream = null;
    try {
        $stream = $client->render->pdfStream($input);
        while (!$stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                break;
            }
            $file->write($chunk);
        }
        $file->commit();
    } finally {
        $stream?->close();
        $file->discard();
    }
}
