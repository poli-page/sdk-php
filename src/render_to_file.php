<?php

declare(strict_types=1);

namespace PoliPage;

/**
 * Render a PDF and stream the bytes to `$path`. Creates parent directories
 * if missing. Overwrites existing files. Memory-bounded — reads from the
 * PSR-7 body stream in 8 KB chunks regardless of document size.
 *
 * Free function rather than a method so callers can use it without
 * importing a `Node`-specific helper class — matches the SDK spec's
 * `renderToFile` (sdk-php.md §2). The Composer autoload entry under
 * `"files"` loads this file on every request.
 *
 * @example
 * ```php
 * use PoliPage\PoliPage;
 * use PoliPage\ProjectModeInput;
 *
 * use function PoliPage\renderToFile;
 *
 * $client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);
 * renderToFile($client, new ProjectModeInput(
 *     project: 'billing',
 *     template: 'invoice',
 *     version: '1.0.0',
 *     data: ['invoiceNumber' => 'INV-001'],
 * ), 'invoice.pdf');
 * ```
 *
 * @throws PoliPageException with code INVALID_OPTIONS when the parent directory cannot be created
 *                                   or the destination file cannot be opened for writing
 * @throws PoliPageException any failure mode of `$client->render->pdfStream($input)` propagates
 */
function renderToFile(PoliPage $client, ProjectModeInput $input, string $path): void
{
    $dir = \dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0o755, recursive: true) && !is_dir($dir)) {
        throw new PoliPageException(
            "Failed to create directory: {$dir}",
            PoliPageException::INVALID_OPTIONS,
        );
    }

    $stream = $client->render->pdfStream($input);

    $file = fopen($path, 'wb');
    if ($file === false) {
        throw new PoliPageException(
            "Failed to open file for writing: {$path}",
            PoliPageException::INVALID_OPTIONS,
        );
    }

    try {
        while (!$stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                break;
            }
            fwrite($file, $chunk);
        }
    } finally {
        fclose($file);
        $stream->close();
    }
}
