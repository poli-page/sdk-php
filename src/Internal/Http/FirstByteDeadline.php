<?php

declare(strict_types=1);

namespace PoliPage\Internal\Http;

/**
 * The first-byte bound of a streamed download (`render->pdfStream()`): the
 * wait for the answer's headers and the wait for the first byte of its body
 * share one `timeout`, measured from the start of the download request. The
 * HTTP clients bound each of the two waits on its own, so the SDK shortens
 * the read timeout of the stream under the body to what is left of the
 * budget while it waits for the first byte ({@see arm()}), then puts back the
 * idle timeout that bounds each gap between two chunks ({@see disarm()}).
 *
 * @internal
 */
final class FirstByteDeadline
{
    /** The shortest read timeout set: a passed deadline still lets bytes already received through. */
    private const MIN_SECONDS = 0.000001;

    /**
     * @param resource $resource    the PHP stream under the body, whose read timeout
     *                              `stream_set_timeout()` sets
     * @param int      $deadlineNs  the `hrtime()` at which the budget runs out
     * @param float    $idleSeconds the idle timeout, put back once the first byte is in
     */
    public function __construct(
        private $resource,
        private readonly int $deadlineNs,
        private readonly float $idleSeconds,
    ) {
    }

    /**
     * Bounds the next read by what is left of the budget.
     */
    public function arm(): void
    {
        $this->setTimeout(max(($this->deadlineNs - hrtime(true)) / 1e9, self::MIN_SECONDS));
    }

    /**
     * Bounds each next read by the idle timeout again.
     */
    public function disarm(): void
    {
        $this->setTimeout($this->idleSeconds);
    }

    private function setTimeout(float $seconds): void
    {
        if (!\is_resource($this->resource)) {
            return;
        }
        $whole = (int) floor($seconds);
        $micro = min(999_999, (int) round(($seconds - $whole) * 1e6));
        // A stream that has no read timeout (an in-memory body) reports it
        // with `false` and a warning: nothing to bound then.
        @stream_set_timeout($this->resource, $whole, $micro);
    }
}
