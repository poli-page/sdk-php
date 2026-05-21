<?php

declare(strict_types=1);

namespace PoliPage;

/**
 * Options for `$client->documents->thumbnails($id, $options)`. Spec §6.3.
 *
 * `width` is the only required field. `format` accepts `"png"` (default)
 * or `"jpeg"`; `quality` (1-100) is only meaningful with JPEG. `pages`
 * restricts output to the listed 1-based page numbers — omit to receive
 * every page.
 */
final readonly class ThumbnailOptions
{
    /**
     * @param 'png'|'jpeg'|null $format
     * @param list<int>|null    $pages  1-based page numbers
     */
    public function __construct(
        public int $width,
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
        $wire = ['width' => $this->width];
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
