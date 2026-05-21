<?php

declare(strict_types=1);

namespace PoliPage;

/**
 * Sealed-in-package base for render inputs. The SDK exposes only two
 * concrete subclasses ({@see ProjectModeInput}, {@see InlineModeInput});
 * external code cannot subclass this directly because the constructor is
 * protected and both children are `final`.
 *
 * PHP has no native sum types or sealed classes (the proposal was deferred
 * from 8.4), so this hybrid of abstract base + final children + protected
 * constructor is the closest available approximation. See sdk-php.md §9.1.
 *
 * `idempotencyKey` and `timeout` are common to both subclasses and live on
 * the base so the request loop can read them uniformly. They are SDK-only
 * fields, stripped from `toWire()`.
 */
abstract readonly class RenderInput
{
    /** @internal Children call parent::__construct(); external code cannot. */
    protected function __construct(
        public ?string $idempotencyKey = null,
        public ?float $timeout = null,
    ) {
    }

    /**
     * Serialise the input to the JSON-encodable array sent to the API.
     *
     * Implementations strip SDK-only fields (idempotencyKey, timeout),
     * unwrap {@see RenderMetadata}, and omit `null` optional fields so
     * the wire body stays compact.
     *
     * @return array<string, mixed>
     *
     * @internal Wire shape is an implementation detail; callers should not depend on it.
     */
    abstract public function toWire(): array;
}
