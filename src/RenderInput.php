<?php

declare(strict_types=1);

namespace PoliPage;

/**
 * Render a stored project + template. Used by `render->pdf`,
 * `render->pdfStream`, `render->document`, and `renderToFile`.
 *
 * The render uses the project's copy published in the API key's
 * environment; there is no version to pick. `data` is the template
 * variable map.
 *
 * `idempotencyKey` and `timeout` are SDK-only fields, stripped from
 * `toWire()`.
 */
final readonly class RenderInput
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $project,
        public string $template,
        public array $data,
        public ?PageFormat $format = null,
        public ?Orientation $orientation = null,
        public ?string $locale = null,
        public ?RenderMetadata $metadata = null,
        public ?string $idempotencyKey = null,
        public ?float $timeout = null,
    ) {
    }

    /**
     * Serialise the input to the JSON-encodable array sent to the API:
     * the documented render fields only, `null` optionals omitted.
     *
     * @return array<string, mixed>
     *
     * @internal Wire shape is an implementation detail; callers should not depend on it.
     */
    public function toWire(): array
    {
        $wire = [
            'project' => $this->project,
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
