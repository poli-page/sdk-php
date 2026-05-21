<?php

declare(strict_types=1);

namespace PoliPage;

/**
 * A single page thumbnail returned by `$client->documents->thumbnails(...)`.
 * `data` is base64-encoded image bytes; decode with `base64_decode` before
 * writing to disk or sending downstream. Spec §6.3.
 */
final readonly class Thumbnail
{
    public function __construct(
        public int $page,
        public int $width,
        public int $height,
        public string $contentType,
        public string $data,
    ) {
    }

    /**
     * @internal Wire-parsing entry point; called by the Documents namespace.
     *
     * @param array<array-key, mixed> $raw
     *
     * @throws PoliPageException with code INTERNAL_ERROR on shape violations
     */
    public static function fromWire(array $raw): self
    {
        $page = $raw['page'] ?? null;
        $width = $raw['width'] ?? null;
        $height = $raw['height'] ?? null;
        $contentType = $raw['contentType'] ?? null;
        $data = $raw['data'] ?? null;

        if (!is_int($page) || !is_int($width) || !is_int($height) || !is_string($contentType) || !is_string($data)) {
            throw new PoliPageException(
                'Unexpected thumbnail wire shape: expected {page: int, width: int, height: int, contentType: string, data: string}',
                PoliPageException::INTERNAL_ERROR,
            );
        }

        return new self(
            page: $page,
            width: $width,
            height: $height,
            contentType: $contentType,
            data: $data,
        );
    }
}
