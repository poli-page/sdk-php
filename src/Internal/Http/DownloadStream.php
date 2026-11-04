<?php

declare(strict_types=1);

namespace PoliPage\Internal\Http;

use PoliPage\Internal\Constants;
use PoliPage\PoliPageException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The body of a presigned PDF download, as returned by `render->pdfStream()`.
 * A failure while it is read (the connection dropped mid-body, say) raises
 * `DOWNLOAD_FAILED` with the network message and the HTTP library's error as
 * `previous`, never the library's own exception or a PHP warning. When the
 * download's idle timeout elapsed, the message is the timeout one: also when
 * the PSR-7 stream only reports it through its `timed_out` metadata.
 *
 * A body that ends before its announced `Content-Length` is DOWNLOAD_FAILED
 * with the network message, whatever the HTTP library does: Guzzle 7's
 * stream handler, for one, reports a connection closed mid-body as a plain
 * end of stream, which would otherwise pass for a complete, shorter PDF. The
 * check follows the position reached in the body (reads, `seek()`,
 * `rewind()`), not the bytes returned, so re-reading or skipping part of a
 * complete body never fails it.
 *
 * Until the first byte of the body is in, reads wait only for what is left
 * of the budget it shares with the wait for the headers
 * ({@see FirstByteDeadline}); after that, the idle timeout bounds each gap.
 *
 * Not covered: a chunked or content-encoded answer has no length to check, so
 * a connection closed mid-body still ends that stream silently under Guzzle
 * 7's stream handler (Guzzle 8, Symfony HttpClient and cURL detect it
 * themselves). The PDF storage always answers with a `Content-Length`.
 *
 * @internal
 */
final class DownloadStream implements StreamInterface
{
    /** The most bytes the first read asks for, when the caller reads to the end. */
    private const FIRST_READ = 8192;

    /** The position reached in the body: the bytes read from its start. */
    private int $received = 0;

    /**
     * @param int|null               $expectedLength the answer's `Content-Length`, when it gives the
     *                                               body's length ({@see self::expectedLength()})
     * @param FirstByteDeadline|null $firstByte      bounds the reads until the first byte is in,
     *                                               then is dropped
     */
    public function __construct(
        private readonly StreamInterface $stream,
        private readonly ?int $expectedLength = null,
        private ?FirstByteDeadline $firstByte = null,
    ) {
    }

    /**
     * The body length a response announces: its `Content-Length`, unless
     * the body is encoded (the HTTP library may decode it to another
     * length) or the header is not a single non-negative integer.
     */
    public static function expectedLength(ResponseInterface $response): ?int
    {
        $encoding = strtolower(trim($response->getHeaderLine('content-encoding')));
        if ($encoding !== '' && $encoding !== 'identity') {
            return null;
        }
        $length = trim($response->getHeaderLine('content-length'));

        return preg_match('/^\d{1,18}\z/', $length) === 1 ? (int) $length : null;
    }

    /**
     * DOWNLOAD_FAILED when `$received` bytes are fewer than `$expectedLength`
     * at the end of the body.
     */
    public static function assertComplete(int $received, ?int $expectedLength): void
    {
        if ($expectedLength !== null && $received < $expectedLength) {
            throw self::failure(new \RuntimeException(sprintf(
                'The PDF download ended after %d of its %d bytes',
                $received,
                $expectedLength,
            )));
        }
    }

    /**
     * The DOWNLOAD_FAILED of a download that got no complete answer: the
     * timeout message when the timeout elapsed (`$timedOut`) or the HTTP
     * library reports a timeout, else the network one.
     */
    public static function failure(\Throwable $previous, bool $timedOut = false): PoliPageException
    {
        return new PoliPageException(
            $timedOut || TimeoutPolicy::isTimeout($previous)
                ? Constants::MESSAGE_DOWNLOAD_FAILED_TIMEOUT
                : Constants::MESSAGE_DOWNLOAD_FAILED_NETWORK,
            PoliPageException::DOWNLOAD_FAILED,
            null,
            null,
            $previous,
        );
    }

    public function __toString(): string
    {
        // Until the first byte is in, nothing was read: no need to rewind.
        if ($this->firstByte !== null) {
            return $this->getContents();
        }

        return $this->reading(function (): string {
            if ($this->stream->isSeekable()) {
                $this->stream->rewind();
                $this->received = 0;
            }

            return $this->stream->getContents();
        }, toTheEnd: true);
    }

    public function read(int $length): string
    {
        return $this->reading(fn (): string => $this->stream->read($length), toTheEnd: false);
    }

    public function getContents(): string
    {
        return $this->reading(fn (): string => $this->stream->getContents(), toTheEnd: true);
    }

    /**
     * Never true for a body that ended short of its announced length: the
     * caller's next read raises DOWNLOAD_FAILED instead, so a
     * `while (!eof()) read()` loop never takes a short body for a whole one.
     */
    public function eof(): bool
    {
        return $this->stream->eof()
            && ($this->expectedLength === null || $this->received >= $this->expectedLength);
    }

    public function close(): void
    {
        $this->stream->close();
    }

    public function detach()
    {
        return $this->stream->detach();
    }

    public function getSize(): ?int
    {
        return $this->stream->getSize();
    }

    public function tell(): int
    {
        return $this->stream->tell();
    }

    public function isSeekable(): bool
    {
        return $this->stream->isSeekable();
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);
        $this->firstByte = null;
        $this->received = $this->stream->tell();
    }

    public function rewind(): void
    {
        $this->stream->rewind();
        $this->firstByte = null;
        $this->received = 0;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('A PDF download stream is read-only');
    }

    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $this->stream->getMetadata($key);
    }

    /**
     * Until the first byte is in, a read waits only for what is left of the
     * first-byte budget ({@see FirstByteDeadline}); a read to the end first
     * reads one chunk that way, then the rest with the idle timeout.
     *
     * @param \Closure(): string $read
     * @param bool               $toTheEnd `$read` reads to the end of the body
     */
    private function reading(\Closure $read, bool $toTheEnd): string
    {
        $firstByte = $this->firstByte;
        if ($firstByte === null) {
            return $this->guarded($read, $toTheEnd);
        }
        $firstByte->arm();
        try {
            $bytes = $this->guarded($toTheEnd ? fn (): string => $this->stream->read(self::FIRST_READ) : $read, false);
        } finally {
            $firstByte->disarm();
        }
        if ($bytes !== '' || $this->stream->eof()) {
            $this->firstByte = null;
        }
        if ($toTheEnd && !$this->stream->eof()) {
            $bytes .= $this->guarded($read, true);
        }

        return $bytes;
    }

    /**
     * A PSR-7 stream over a socket (guzzlehttp/psr7 2.x, say) can report an
     * elapsed read timeout only through the socket's `timed_out` flag: with a
     * generic read error, an empty `read()`, or a `getContents()` that stops
     * short of the end. Each would otherwise look like a short body. A
     * `read()` that returned bytes is kept even when the flag is set (on
     * Windows a read can set it although it returned bytes); the next one
     * comes back empty if the timeout really elapsed.
     *
     * @param \Closure(): string $read
     * @param bool               $toTheEnd `$read` reads to the end of the body
     */
    private function guarded(\Closure $read, bool $toTheEnd): string
    {
        try {
            $bytes = BodyReader::guard($read);
        } catch (\Exception $e) {
            throw self::failure($e, $this->timedOut());
        }
        if (($toTheEnd || $bytes === '') && !$this->stream->eof() && $this->timedOut()) {
            throw self::failure(new \RuntimeException('Reading the PDF download timed out'), true);
        }
        $this->received += \strlen($bytes);
        if ($this->stream->eof()) {
            self::assertComplete($this->received, $this->expectedLength);
        }

        return $bytes;
    }

    private function timedOut(): bool
    {
        try {
            return $this->stream->getMetadata('timed_out') === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
