<?php

declare(strict_types=1);

namespace PoliPage;

/**
 * Render against a stored project + template. Used by `render->pdf`,
 * `render->pdfStream`, `render->document`, and `render->preview`.
 *
 * Pass `version` to target a specific published version; omit to render
 * the draft. `data` is the template variable map.
 */
final readonly class ProjectModeInput extends RenderInput
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $project,
        public string $template,
        public array $data,
        public ?string $version = null,
        public ?PageFormat $format = null,
        public ?Orientation $orientation = null,
        public ?string $locale = null,
        public ?RenderMetadata $metadata = null,
        ?string $idempotencyKey = null,
        ?float $timeout = null,
    ) {
        parent::__construct($idempotencyKey, $timeout);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $wire = [
            'project' => $this->project,
            'template' => $this->template,
            'data' => $this->data,
        ];
        if ($this->version !== null) {
            $wire['version'] = $this->version;
        }
        if ($this->format !== null) {
            $wire['format'] = $this->format->value;
        }
        if ($this->orientation !== null) {
            $wire['orientation'] = $this->orientation->value;
        }
        if ($this->locale !== null) {
            $wire['locale'] = $this->locale;
        }
        if ($this->metadata !== null) {
            $wire['metadata'] = $this->metadata->toArray();
        }

        return $wire;
    }
}
