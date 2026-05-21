<?php

declare(strict_types=1);

namespace PoliPage\Internal;

/**
 * Small request seam used by namespace classes (Render, Documents) so they
 * can be unit-tested without spinning up a PSR-18 mock. PoliPage implements
 * this interface; tests inject a hand-rolled fake.
 *
 * Only `post` is exposed in Phase 2 — `get`, `delete`, `fetchBytes`, and
 * `streamBytes` will be added as the namespace methods that need them ship
 * in later phases.
 *
 * @internal Not part of the public API.
 */
interface Transport
{
    /**
     * Send an authenticated POST and return the decoded JSON body.
     *
     * @param array<string, mixed> $body
     * @param float|null           $timeout per-call override; `null` defers to the client default
     *
     * @return array<array-key, mixed>
     */
    public function post(string $path, array $body, ?string $idempotencyKey, ?float $timeout): array;
}
