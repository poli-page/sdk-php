<?php

declare(strict_types=1);

namespace PoliPage\Internal;

use PoliPage\PoliPageException;

/**
 * Writes a file through a temporary file in the same directory, renamed
 * over the target only once every byte is written, so a failure leaves the
 * target untouched: an existing file keeps its content and a missing one is
 * not created. Used by {@see \PoliPage\renderToFile()}.
 *
 * The target is the file the path names once its symbolic links are
 * followed, so the rename replaces that file and a link stays a link. An
 * existing target keeps its permission bits (without setuid, setgid or
 * sticky). The rename makes it a new file even so: its owner and group
 * become the calling process's, and its other hard links keep the old
 * content.
 *
 * Every failure is `io_error`: no status, the message
 * `Failed to write PDF: <path>: <reason>`, where `<path>` is the path the
 * caller passed (never the temporary file) and `<reason>` the system's own
 * text, and the PHP error as `previous`.
 *
 * @internal
 */
final class AtomicFileWriter
{
    /** Most symbolic links followed in one path (Linux's limit), so a loop fails. */
    private const MAX_LINKS = 40;

    /** The permission bits an existing target keeps: no setuid, setgid or sticky. */
    private const PERMISSION_BITS = 0o777;

    /** @var resource|null */
    private $handle;

    private bool $committed = false;

    /**
     * @param string   $path     the path the caller passed, named in every error
     * @param string   $target   the file written: `$path` with its symbolic links followed
     * @param int|null $mode     the permission bits of an existing target, else null
     * @param resource $handle
     */
    private function __construct(
        private readonly string $path,
        private readonly string $target,
        private readonly string $tempPath,
        private readonly ?int $mode,
        $handle,
    ) {
        $this->handle = $handle;
    }

    /**
     * Checks the target, creates its missing parent directories and opens
     * the temporary file next to it.
     *
     * Two targets are refused, judged from the file's attributes without
     * opening it (opening a FIFO would block): one that exists but is not a
     * regular file (a directory, a FIFO, a device such as `/dev/stdout`, a
     * socket), which the rename would replace with a regular file, and one
     * that is not writable, which the rename could replace even so.
     *
     * @throws PoliPageException with code IO_ERROR
     */
    public static function open(string $path): self
    {
        // Why: PHP caches stat and realpath results, which a link or file
        // changed since would make stale.
        clearstatcache(true);
        $target = self::resolveLinks($path);

        $mode = null;
        if (file_exists($target)) {
            if (!is_file($target)) {
                throw self::ioError($path, new \RuntimeException('not a regular file'));
            }
            if (!is_writable($target)) {
                throw self::ioError($path, new \RuntimeException('Permission denied'));
            }
            $perms = fileperms($target);
            $mode = $perms === false ? null : $perms & self::PERMISSION_BITS;
        }

        $dir = \dirname($target);
        if (!is_dir($dir)) {
            try {
                self::attempt($path, static fn (): bool => mkdir($dir, 0o755, recursive: true), 'cannot create its directory');
            } catch (PoliPageException $e) {
                // Another process may have created it meanwhile.
                if (!is_dir($dir)) {
                    throw $e;
                }
            }
        }

        // Why `x`: the name is random, and an existing file is never reused.
        // Opening it is the check that the directory can be written.
        $tempPath = $dir . \DIRECTORY_SEPARATOR . '.' . basename($target) . '.' . bin2hex(random_bytes(8)) . '.part';
        $handle = self::attempt(
            $path,
            static fn () => fopen($tempPath, 'xb'),
            'cannot create a file in its directory',
        );

        return new self($path, $target, $tempPath, $mode, $handle);
    }

    /**
     * @throws PoliPageException with code IO_ERROR
     */
    public function write(string $bytes): void
    {
        $handle = $this->handle();
        $written = self::attempt(
            $this->path,
            static fn (): int|false => fwrite($handle, $bytes),
            'write failed',
        );
        if ($written !== \strlen($bytes)) {
            throw self::ioError($this->path, new \RuntimeException(sprintf(
                'wrote %d of %d bytes',
                $written,
                \strlen($bytes),
            )));
        }
    }

    /**
     * Closes the temporary file, gives it the existing target's permission
     * bits and renames it over the target.
     *
     * @throws PoliPageException with code IO_ERROR
     */
    public function commit(): void
    {
        $handle = $this->handle();
        $this->handle = null;
        self::attempt($this->path, static fn (): bool => fclose($handle), 'close failed');
        if ($this->mode !== null) {
            $mode = $this->mode;
            self::attempt($this->path, fn (): bool => chmod($this->tempPath, $mode), 'cannot set its permissions');
        }
        self::attempt(
            $this->path,
            fn (): bool => rename($this->tempPath, $this->target),
            'cannot move the file into place',
        );
        $this->committed = true;
    }

    /**
     * Removes the temporary file unless it was committed. Never throws.
     */
    public function discard(): void
    {
        if ($this->handle !== null) {
            @fclose($this->handle);
            $this->handle = null;
        }
        if (!$this->committed) {
            @unlink($this->tempPath);
        }
    }

    /**
     * The file `$path` names once its symbolic links are followed. An
     * existing file is its real path. A link whose target does not exist yet
     * is followed hop by hop to that target, which the rename then creates:
     * each relative link is read against the real directory of the link, as
     * the file system does, so a `..` after a linked directory leaves the
     * directory the link points to.
     *
     * @throws PoliPageException with code IO_ERROR: a link loop, or a link that cannot be read
     */
    private static function resolveLinks(string $path): string
    {
        if (file_exists($path)) {
            $real = realpath($path);

            return $real === false ? $path : $real;
        }
        $current = $path;
        for ($hop = 0; $hop < self::MAX_LINKS; ++$hop) {
            if (!is_link($current)) {
                return $current;
            }
            $link = self::attempt($path, static fn (): string|false => readlink($current), 'cannot read a symbolic link');
            $current = self::isAbsolute($link) ? $link : self::realDir(\dirname($current)) . \DIRECTORY_SEPARATOR . $link;
        }

        throw self::ioError($path, new \RuntimeException('Too many levels of symbolic links'));
    }

    /**
     * `$dir` with its symbolic links resolved, or as it is when it does not
     * exist yet (the writer creates it).
     */
    private static function realDir(string $dir): string
    {
        $real = realpath($dir);

        return $real === false ? $dir : $real;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || (\DIRECTORY_SEPARATOR === '\\' && preg_match('#^([a-zA-Z]:)?[\\\\/]#', $path) === 1);
    }

    /**
     * @return resource
     */
    private function handle()
    {
        if ($this->handle === null) {
            throw new \LogicException('The file is already closed');
        }

        return $this->handle;
    }

    /**
     * Runs a filesystem call with its PHP warnings and notices turned into
     * an `io_error` that carries their text, and a `false` result into one
     * that carries `$fallbackReason`.
     *
     * @template T
     *
     * @param \Closure(): (T|false) $operation
     *
     * @return T
     *
     * @throws PoliPageException with code IO_ERROR
     */
    private static function attempt(string $path, \Closure $operation, string $fallbackReason): mixed
    {
        set_error_handler(
            static function (int $severity, string $message): never {
                throw new \ErrorException($message, 0, $severity);
            },
            E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE,
        );
        try {
            $result = $operation();
        } catch (\ErrorException $e) {
            throw self::ioError($path, $e);
        } finally {
            restore_error_handler();
        }
        if ($result === false) {
            throw self::ioError($path, new \RuntimeException($fallbackReason));
        }

        return $result;
    }

    /**
     * The `io_error` for `$path`. Its reason is the system's text from
     * `$previous`: a PHP warning (`rename(/a,/b): Permission denied`) without
     * the function and its arguments, which name the temporary file.
     */
    private static function ioError(string $path, \Throwable $previous): PoliPageException
    {
        $reason = preg_replace('/^\w+\(.*\): (Failed to open stream: )?/s', '', $previous->getMessage());

        return new PoliPageException(
            str_replace('{reason}', $path . ': ' . ($reason ?? $previous->getMessage()), Constants::MESSAGE_IO_ERROR),
            PoliPageException::IO_ERROR,
            null,
            null,
            $previous,
        );
    }
}
