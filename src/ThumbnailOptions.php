<?php

declare(strict_types=1);

namespace PoliPage;

/**
 * Options for `$client->documents->thumbnails($id, $options)`. Spec §6.3.
 *
 * Every option is optional; an unset option is not sent and the API
 * applies its default. `width` (50-2000, API default 400); `format`
 * `"jpeg"` (API default), `"webp"` or `"png"`; `quality` (1-100) is only
 * meaningful with JPEG; `pages` restricts output to the listed 1-based
 * page numbers — omit to receive every page. The API validates the
 * bounds (`400 VALIDATION_ERROR`).
 */
final readonly class ThumbnailOptions
{
    /**
     * @param 'jpeg'|'webp'|'png'|null $format
     * @param list<int>|null           $pages  1-based page numbers
     */
    public function __construct(
        public ?int $width = null,
        public ?string $format = null,
        public ?int $quality = null,
        public ?array $pages = null,
    ) {
    }

    /**
     * @internal Wire body shape; the Documents namespace nests this under
     *           a top-level `thumbnails` key per the deployed API quirk.
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $wire = [];
        if ($this->width !== null) {
            $wire['width'] = $this->width;
        }
        if ($this->format !== null) {
            $wire['format'] = $this->format;
        }
        if ($this->quality !== null) {
            $wire['quality'] = $this->quality;
        }
        if ($this->pages !== null) {
            $wire['pages'] = $this->pages;
        }

        return $wire;
    }
}
