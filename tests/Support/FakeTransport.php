<?php

declare(strict_types=1);

namespace PoliPage\Tests\Support;

use PoliPage\Internal\Http\TextResponse;
use PoliPage\Internal\Transport;
use PoliPage\PoliPageException;
use Psr\Http\Message\StreamInterface;

/**
 * In-test transport stub. Each verb captures its call args into a typed
 * list and returns whatever the test set on the matching response field.
 * Set `*Exception` to make a verb throw instead.
 *
 * Sequence-able where it matters: every `*Responses` field is a FIFO
 * queue — pop one per call — falling back to the matching scalar
 * `*Response` default when the queue is empty.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{path: string, body: array<string, mixed>, idempotencyKey: ?string, timeout: ?float}> */
    public array $postCalls = [];

    /** @var list<array{path: string, timeout: ?float}> */
    public array $getCalls = [];

    /** @var list<array{path: string, timeout: ?float}> */
    public array $getTextCalls = [];

    /** @var list<array{path: string, timeout: ?float}> */
    public array $deleteCalls = [];

    /** @var list<array{url: string, timeout: ?float}> */
    public array $fetchBytesCalls = [];

    /** @var list<array{url: string, timeout: ?float}> */
    public array $streamBytesCalls = [];

    /** @var array<array-key, mixed> default response when the queue is empty */
    public array $postResponse = [];

    /** @var list<array<array-key, mixed>> FIFO queue; falls back to postResponse */
    public array $postResponses = [];

    /** @var array<array-key, mixed> */
    public array $getResponse = [];

    /** @var list<array<array-key, mixed>> */
    public array $getResponses = [];

    public ?TextResponse $getTextResponse = null;

    /** @var list<TextResponse> */
    public array $getTextResponses = [];

    public string $fetchBytesResponse = '';

    /** @var list<string> */
    public array $fetchBytesResponses = [];

    public ?StreamInterface $streamBytesResponse = null;

    public ?\Throwable $postException = null;
    public ?\Throwable $getException = null;
    public ?\Throwable $getTextException = null;
    public ?\Throwable $deleteException = null;
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

    public function get(string $path, ?float $timeout): array
    {
        $this->getCalls[] = ['path' => $path, 'timeout' => $timeout];
        if ($this->getException !== null) {
            throw $this->getException;
        }
        if ($this->getResponses !== []) {
            return array_shift($this->getResponses);
        }

        return $this->getResponse;
    }

    public function getText(string $path, ?float $timeout): TextResponse
    {
        $this->getTextCalls[] = ['path' => $path, 'timeout' => $timeout];
        if ($this->getTextException !== null) {
            throw $this->getTextException;
        }
        if ($this->getTextResponses !== []) {
            return array_shift($this->getTextResponses);
        }
        if ($this->getTextResponse === null) {
            throw new PoliPageException(
                'FakeTransport::getTextResponse is unset — set it before calling getText',
                PoliPageException::INTERNAL_ERROR,
            );
        }

        return $this->getTextResponse;
    }

    public function delete(string $path, ?float $timeout): void
    {
        $this->deleteCalls[] = ['path' => $path, 'timeout' => $timeout];
        if ($this->deleteException !== null) {
            throw $this->deleteException;
        }
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
