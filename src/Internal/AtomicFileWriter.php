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
 * Every failure is `io_error`: no status, the message
 * `Failed to write PDF: <reason>` with the PHP error's own text as the
 * reason, and that error as `previous`.
 *
 * @internal
 */
final class AtomicFileWriter
{
    /** @var resource|null */
    private $handle;

    private bool $committed = false;

    /**
     * @param resource $handle
     */
    private function __construct(private readonly string $path, private readonly string $tempPath, $handle)
    {
        $this->handle = $handle;
    }

    /**
     * Creates the target's missing parent directories and opens the
     * temporary file next to it.
     *
     * @throws PoliPageException with code IO_ERROR
     */
    public static function open(string $path): self
    {
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            try {
                self::attempt(static fn (): bool => mkdir($dir, 0o755, recursive: true), 'cannot create ' . $dir);
            } catch (PoliPageException $e) {
                // Another process may have created it meanwhile.
                if (!is_dir($dir)) {
                    throw $e;
                }
            }
        }

        // Why `x`: the name is random, and an existing file is never reused.
        $tempPath = $dir . \DIRECTORY_SEPARATOR . '.' . basename($path) . '.' . bin2hex(random_bytes(8)) . '.part';
        $handle = self::attempt(
            static fn () => fopen($tempPath, 'xb'),
            'cannot open ' . $tempPath,
        );

        return new self($path, $tempPath, $handle);
    }

    /**
     * @throws PoliPageException with code IO_ERROR
     */
    public function write(string $bytes): void
    {
        $handle = $this->handle();
        $written = self::attempt(
            static fn (): int|false => fwrite($handle, $bytes),
            'cannot write to ' . $this->tempPath,
        );
        if ($written !== \strlen($bytes)) {
            throw self::ioError(new \RuntimeException(sprintf(
                'wrote %d of %d bytes to %s',
                $written,
                \strlen($bytes),
                $this->tempPath,
            )));
        }
    }

    /**
     * Closes the temporary file and renames it over the target.
     *
     * @throws PoliPageException with code IO_ERROR
     */
    public function commit(): void
    {
        $handle = $this->handle();
        $this->handle = null;
        self::attempt(static fn (): bool => fclose($handle), 'cannot close ' . $this->tempPath);
        self::attempt(
            fn (): bool => rename($this->tempPath, $this->path),
            sprintf('cannot rename %s to %s', $this->tempPath, $this->path),
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
    private static function attempt(\Closure $operation, string $fallbackReason): mixed
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
            throw self::ioError($e);
        } finally {
            restore_error_handler();
        }
        if ($result === false) {
            throw self::ioError(new \RuntimeException($fallbackReason));
        }

        return $result;
    }

    private static function ioError(\Throwable $previous): PoliPageException
    {
        return new PoliPageException(
            str_replace('{reason}', $previous->getMessage(), Constants::MESSAGE_IO_ERROR),
            PoliPageException::IO_ERROR,
            null,
            null,
            $previous,
        );
    }
}
