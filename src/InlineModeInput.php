<?php

declare(strict_types=1);

namespace PoliPage;

/**
 * Render with raw HTML inline (no project / template resolution). Accepted
 * only by `render->preview`; the document-producing methods require
 * project mode (enforced both at the type-hint level and at runtime).
 */
final readonly class InlineModeInput extends RenderInput
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $template,
        public array $data,
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
            'template' => $this->template,
            'data' => $this->data,
        ];
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
