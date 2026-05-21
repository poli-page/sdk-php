<?php

declare(strict_types=1);

namespace PoliPage\Internal\Http;

/**
 * Carrier for a non-JSON GET response: the raw body bytes plus the
 * subset of headers callers may need. Used by `Documents::preview` to
 * read the `text/html` body alongside the `X-Document-Page-Count`
 * response header in one round-trip.
 *
 * @internal
 */
final readonly class TextResponse
{
    /**
     * @param array<string, list<string>> $headers PSR-7 shape: name → list of values
     */
    public function __construct(
        public string $body,
        public array $headers,
    ) {
    }

    /**
     * Case-insensitive header lookup; returns the first value or `null`.
     */
    public function header(string $name): ?string
    {
        $needle = strtolower($name);
        foreach ($this->headers as $key => $values) {
            if (strtolower($key) === $needle) {
                return $values[0] ?? null;
            }
        }

        return null;
    }
}
