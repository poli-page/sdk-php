<?php

declare(strict_types=1);

namespace PoliPage\Internal\Http;

/**
 * Computes the delay before the next retry attempt in **seconds**. When
 * `$retryAfter` is provided (server-explicit `Retry-After` header), it is
 * returned verbatim without jitter. Otherwise applies exponential backoff
 * `baseDelay × 2^(attempt-1)` multiplied by a jitter factor in
 * `[0.5, 1.5)`.
 *
 * `$attempt` is 1-based: 1 means the first retry. Mirrors
 * sdk-node/src/internal/http.ts `computeBackoff`.
 *
 * The default jitter source is `mt_rand() / (mt_getrandmax() + 1)`. Tests inject a
 * deterministic source via the optional `$jitterSource` parameter — PHP has
 * no equivalent of Vitest's `vi.spyOn(Math, 'random')`, so dependency
 * injection is the cleanest seam.
 *
 * @internal
 */
final class Backoff
{
    /**
     * @param int                 $attempt      1-based retry attempt number
     * @param float               $baseDelay    base delay in seconds
     * @param float|null          $retryAfter   server Retry-After value in seconds, or null
     * @param (\Closure(): float)|null $jitterSource Optional deterministic jitter source returning a value in [0, 1)
     */
    public static function compute(
        int $attempt,
        float $baseDelay,
        ?float $retryAfter,
        ?\Closure $jitterSource = null,
    ): float {
        if ($retryAfter !== null) {
            return $retryAfter;
        }
        $exp = $baseDelay * (2 ** ($attempt - 1));
        // [0, 1): the factor is uniform in [0.5, 1.5) (SDK spec §9.2).
        $jitterSource ??= static fn (): float => mt_rand() / (mt_getrandmax() + 1);
        $jitterFactor = 0.5 + $jitterSource();

        return $exp * $jitterFactor;
    }
}
