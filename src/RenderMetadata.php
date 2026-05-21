<?php

declare(strict_types=1);

namespace PoliPage;

/**
 * Free-form caller metadata forwarded to the API and echoed back on
 * `preview` and `document` responses. Values are limited to primitives
 * (string | int | float | bool) — nested arrays and objects are rejected
 * at construction time to match the wire-contract guarantee (spec §5.4)
 * and the Node SDK's compile-time enforcement (types.ts:31).
 */
final readonly class RenderMetadata
{
    /**
     * @param array<string, scalar> $values
     *
     * @throws PoliPageException with code INVALID_OPTIONS when any value is not a primitive
     */
    public function __construct(public array $values)
    {
        foreach ($values as $key => $value) {
            if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
                throw new PoliPageException(
                    sprintf(
                        "metadata value for key '%s' must be a primitive (string|int|float|bool), got %s",
                        $key,
                        get_debug_type($value),
                    ),
                    PoliPageException::INVALID_OPTIONS,
                );
            }
        }
    }

    /**
     * @return array<string, scalar>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
