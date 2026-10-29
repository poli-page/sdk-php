<?php

declare(strict_types=1);

namespace PoliPage\Tests\Contract;

/**
 * One request as the mock server received it.
 */
final readonly class RecordedRequest
{
    /**
     * @param array<string, string> $headers lowercased names
     * @param int                   $atNs    `hrtime()` of its first byte, in the server's process
     */
    public function __construct(
        public string $method,
        public string $target,
        public array $headers,
        public string $body,
        public int $atNs,
    ) {
    }

    public static function fromServer(mixed $raw): self
    {
        if (!is_array($raw)
            || !is_string($raw['method'] ?? null)
            || !is_string($raw['target'] ?? null)
            || !is_array($raw['headers'] ?? null)
            || !is_string($raw['body'] ?? null)
            || !is_int($raw['atNs'] ?? null)
        ) {
            throw new ContractException('The mock server recorded a malformed request');
        }
        $headers = [];
        foreach ($raw['headers'] as $name => $value) {
            $headers[(string) $name] = is_string($value) ? $value : '';
        }
        $body = base64_decode($raw['body'], true);

        return new self($raw['method'], $raw['target'], $headers, $body === false ? '' : $body, $raw['atNs']);
    }
}
