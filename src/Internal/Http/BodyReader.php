<?php

declare(strict_types=1);

namespace PoliPage\Internal\Http;

use Psr\Http\Message\StreamInterface;

/**
 * Reads a PSR-7 body to the end, so a failure mid-body is an exception and
 * never a truncated string.
 *
 * Why: PSR-18 clients that stream the body lazily (Symfony HttpClient's
 * `Psr18Client`) report a transport error while it is read as a PHP warning
 * (`E_USER_WARNING`), then return the bytes read so far or throw a bare
 * `RuntimeException`. The warning is turned into an `\ErrorException` while
 * the body is read, so the caller always sees the failure, whatever the
 * PSR-7 implementation does next.
 *
 * @internal
 */
final class BodyReader
{
    /**
     * @throws \Exception when the body cannot be read to its end
     */
    public static function read(StreamInterface $stream): string
    {
        return self::guard(static function () use ($stream): string {
            if ($stream->isSeekable() && $stream->tell() !== 0) {
                $stream->rewind();
            }

            return $stream->getContents();
        });
    }

    /**
     * Runs `$read` with PHP warnings turned into `\ErrorException`.
     *
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return T
     */
    public static function guard(\Closure $read): mixed
    {
        set_error_handler(
            static function (int $severity, string $message): never {
                throw new \ErrorException($message, 0, $severity);
            },
            E_WARNING | E_USER_WARNING,
        );
        try {
            return $read();
        } finally {
            restore_error_handler();
        }
    }
}
