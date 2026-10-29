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
 * `previous`, never the library's own exception or a PHP warning.
 *
 * @internal
 */
final class DownloadStream implements StreamInterface
{
    public function __construct(private readonly StreamInterface $stream)
    {
    }

    public static function failure(\Throwable $previous): PoliPageException
    {
        return new PoliPageException(
            Constants::MESSAGE_DOWNLOAD_FAILED_NETWORK,
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
        });
    }

    public function read(int $length): string
    {
        return $this->reading(fn (): string => $this->stream->read($length));
    }

    public function getContents(): string
    {
        return $this->reading(fn (): string => $this->stream->getContents());
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
     * @param \Closure(): string $read
     */
    private function reading(\Closure $read): string
    {
        try {
            return BodyReader::guard($read);
        } catch (\Exception $e) {
            throw self::failure($e);
        }
    }
}
