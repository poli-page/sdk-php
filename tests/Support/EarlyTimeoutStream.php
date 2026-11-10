<?php

declare(strict_types=1);

namespace PoliPage\Tests\Support;

use Psr\Http\Message\StreamInterface;

/**
 * A response body whose first `$timeouts` reads time out, then whose next
 * read returns `$body` and ends it: a wait that woke before its deadline.
 * A read that times out returns nothing with the `timed_out` metadata set,
 * as guzzlehttp/psr7 and nyholm/psr7 over a socket do, or, with `$throws`,
 * throws the timeout error alone, as Symfony HttpClient's PSR-18 stream
 * does.
 */
final class EarlyTimeoutStream implements StreamInterface
{
    public int $reads = 0;

    private bool $timedOut = false;

    private bool $eof = false;

    public function __construct(
        private readonly string $body,
        private readonly int $timeouts,
        private readonly bool $throws = false,
    ) {
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->eof ? strlen($this->body) : 0;
    }

    public function eof(): bool
    {
        return $this->eof;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        ++$this->reads;
        if ($this->reads <= $this->timeouts) {
            if ($this->throws) {
                throw new \RuntimeException('Idle timeout reached for "http://127.0.0.1/doc.pdf".');
            }
            $this->timedOut = true;

            return '';
        }
        $this->timedOut = false;
        $this->eof = true;

        return $this->body;
    }

    public function getContents(): string
    {
        return $this->eof ? '' : $this->read(8192);
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === 'timed_out' ? $this->timedOut : null;
    }
}
