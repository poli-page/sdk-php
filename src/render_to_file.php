<?php

declare(strict_types=1);

namespace PoliPage;

/**
 * Render a PDF and stream the bytes to `$path`. Creates parent directories
 * if missing. Overwrites existing files. Memory-bounded — reads from the
 * PSR-7 body stream in 8 KB chunks regardless of document size.
 *
 * On short-write (disk full, quota exceeded), the partial file is removed
 * and a `PoliPageException` is thrown — never leaves a truncated PDF on
 * disk.
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
 * @throws PoliPageException with code INVALID_OPTIONS when the parent directory cannot be created,
 *                                   the destination file cannot be opened, or a write fails partway
 *                                   through (in which case the partial file is removed)
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

    $fullyWritten = false;
    try {
        while (!$stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                break;
            }
            $bytesWritten = fwrite($file, $chunk);
            if ($bytesWritten === false || $bytesWritten !== strlen($chunk)) {
                throw new PoliPageException(
                    sprintf(
                        'Short write to %s (wrote %s of %d bytes — disk full?)',
                        $path,
                        $bytesWritten === false ? 'unknown' : (string) $bytesWritten,
                        strlen($chunk),
                    ),
                    PoliPageException::INVALID_OPTIONS,
                );
            }
        }
        $fullyWritten = true;
    } finally {
        fclose($file);
        $stream->close();
        if (!$fullyWritten && is_file($path)) {
            @unlink($path);
        }
    }
}
