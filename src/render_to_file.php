<?php

declare(strict_types=1);

namespace PoliPage;

use PoliPage\Internal\AtomicFileWriter;
use PoliPage\Internal\Http\DownloadStream;

/**
 * Render a PDF and stream the bytes to `$path`. Creates parent directories
 * if missing. Overwrites existing files. Memory-bounded — reads from the
 * PSR-7 body stream in 8 KB chunks regardless of document size.
 *
 * The bytes go to a temporary file in the same directory, renamed over
 * `$path` only once the PDF is complete: when anything fails, `$path` is left
 * untouched (an existing file keeps its content, a missing one is not
 * created) and the temporary file is removed. The target and its directory
 * are checked, and the temporary file created, before the render, so a path
 * that cannot be written costs no render.
 *
 * When `$path` is a symbolic link (or a chain of them), the file it points to
 * is written and the link stays a link. An existing target keeps its
 * permission bits, without setuid, setgid or sticky; as the PDF replaces it
 * with a new file, its owner and group become those of the calling process,
 * and its other hard links keep the old content. An existing target that is
 * not writable (a read-only file) or not a regular file (a directory, FIFO,
 * socket or device such as `/dev/stdout` when stdout is a pipe or a
 * terminal) is refused before any API call. To write the PDF to a pipe or to
 * stdout, copy `$client->render->pdfStream()` into it instead.
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
 *                                   the target is not writable or not a regular file, or creating
 *                                   a parent directory, opening, writing or renaming it into place
 *                                   fails. No status, the message
 *                                   `Failed to write PDF: <path>: <reason>` (`$path` as passed,
 *                                   never the temporary file; the system's own reason, or
 *                                   `not a regular file`), and PHP's error as `getPrevious()`.
 *                                   Never retried
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
        // An empty read short of the end is a download that stopped: never
        // commit what came before it.
        if (!$stream->eof()) {
            throw DownloadStream::failure(new \RuntimeException('The PDF download stopped before its end'));
        }
        $file->commit();
    } finally {
        $stream?->close();
        $file->discard();
    }
}
