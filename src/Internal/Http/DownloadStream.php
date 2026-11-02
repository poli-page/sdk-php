<?php

declare(strict_types=1);

namespace PoliPage\Internal\Http;

use PoliPage\Internal\Constants;
use PoliPage\PoliPageException;
use Psr\Http\Message\StreamInterface;

/**
 * The body of a presigned PDF download, as returned by `render->pdfStream()`.
 * A failure while it is read (the connection dropped mid-body, say) raises
 * `DOWNLOAD_FAILED` with the network message and the HTTP library's error as
 * `previous`, never the library's own exception or a PHP warning. When the
 * download's idle timeout elapsed, the message is the timeout one: also when
 * the PSR-7 stream only reports it through its `timed_out` metadata.
 *
 * @internal
 */
final class DownloadStream implements StreamInterface
{
    public function __construct(private readonly StreamInterface $stream)
    {
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
        return $this->reading(function (): string {
            if ($this->stream->isSeekable()) {
                $this->stream->rewind();
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

    public function eof(): bool
    {
        return $this->stream->eof();
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
    }

    public function rewind(): void
    {
        $this->stream->rewind();
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
    private function reading(\Closure $read, bool $toTheEnd): string
    {
        try {
            $bytes = BodyReader::guard($read);
        } catch (\Exception $e) {
            throw self::failure($e, $this->timedOut());
        }
        if (($toTheEnd || $bytes === '') && !$this->stream->eof() && $this->timedOut()) {
            throw self::failure(new \RuntimeException('Reading the PDF download timed out'), true);
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
