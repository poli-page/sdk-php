<?php

declare(strict_types=1);

namespace PoliPage\Tests\Support;

use PoliPage\Internal\Transport;
use PoliPage\PoliPageException;
use Psr\Http\Message\StreamInterface;

/**
 * In-test transport stub. Each verb captures its call args into a typed
 * list and returns whatever the test set on the matching response field.
 * Set `*Exception` to make a verb throw instead.
 *
 * Sequence-able where it matters: `postResponses` and `fetchBytesResponses`
 * are FIFO queues — pop one per call so a test can stage two-hop or
 * retry flows.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{path: string, body: array<string, mixed>, idempotencyKey: ?string, timeout: ?float}> */
    public array $postCalls = [];

    /** @var list<array{url: string, timeout: ?float}> */
    public array $fetchBytesCalls = [];

    /** @var list<array{url: string, timeout: ?float}> */
    public array $streamBytesCalls = [];

    /** @var array<array-key, mixed> default response when the queue is empty */
    public array $postResponse = [];

    /** @var list<array<array-key, mixed>> FIFO queue of responses; falls back to postResponse */
    public array $postResponses = [];

    public string $fetchBytesResponse = '';

    /** @var list<string> FIFO queue of byte responses; falls back to fetchBytesResponse */
    public array $fetchBytesResponses = [];

    public ?StreamInterface $streamBytesResponse = null;

    public ?\Throwable $postException = null;
    public ?\Throwable $fetchBytesException = null;
    public ?\Throwable $streamBytesException = null;

    public function post(string $path, array $body, ?string $idempotencyKey, ?float $timeout): array
    {
        $this->postCalls[] = [
            'path' => $path,
            'body' => $body,
            'idempotencyKey' => $idempotencyKey,
            'timeout' => $timeout,
        ];
        if ($this->postException !== null) {
            throw $this->postException;
        }
        if ($this->postResponses !== []) {
            return array_shift($this->postResponses);
        }

        return $this->postResponse;
    }

    public function fetchBytes(string $url, ?float $timeout): string
    {
        $this->fetchBytesCalls[] = ['url' => $url, 'timeout' => $timeout];
        if ($this->fetchBytesException !== null) {
            throw $this->fetchBytesException;
        }
        if ($this->fetchBytesResponses !== []) {
            return array_shift($this->fetchBytesResponses);
        }

        return $this->fetchBytesResponse;
    }

    public function streamBytes(string $url, ?float $timeout): StreamInterface
    {
        $this->streamBytesCalls[] = ['url' => $url, 'timeout' => $timeout];
        if ($this->streamBytesException !== null) {
            throw $this->streamBytesException;
        }
        if ($this->streamBytesResponse === null) {
            throw new PoliPageException(
                'FakeTransport::streamBytesResponse is unset — set it before calling streamBytes',
                PoliPageException::INTERNAL_ERROR,
            );
        }

        return $this->streamBytesResponse;
    }
}
