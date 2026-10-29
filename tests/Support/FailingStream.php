<?php

declare(strict_types=1);

namespace PoliPage\Tests\Support;

use Psr\Http\Message\StreamInterface;

/**
 * A response body whose connection fails after `$partial`: reading past it
 * throws `$failure`, or, with `$warn`, first raises the failure's message as
 * an `E_USER_WARNING` and returns what it has, as Symfony HttpClient's
 * PSR-18 stream does.
 */
final class FailingStream implements StreamInterface
{
    private bool $read = false;

    public function __construct(
        private readonly string $partial,
        private readonly \RuntimeException $failure,
        private readonly bool $warn = false,
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
        return $this->read ? strlen($this->partial) : 0;
    }

    public function eof(): bool
    {
        return false;
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
        if (!$this->read) {
            $this->read = true;

            return $this->partial;
        }

        return $this->fail();
    }

    public function getContents(): string
    {
        if (!$this->read) {
            $this->read = true;

            return $this->partial . $this->fail();
        }

        return $this->fail();
    }

    public function getMetadata(?string $key = null): mixed
    {
        return null;
    }

    private function fail(): string
    {
        if ($this->warn) {
            trigger_error($this->failure->getMessage(), E_USER_WARNING);

            return '';
        }

        throw $this->failure;
    }
}
