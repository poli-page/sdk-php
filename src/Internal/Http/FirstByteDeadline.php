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
 * Each read timeout set is rounded up to a whole millisecond: PHP waits on a
 * socket for whole milliseconds and drops the rest, so a fractional time
 * left would end the wait before the deadline. A read that still times out
 * before the deadline waits again for the rest ({@see rearm()}).
 *
 * @internal
 */
final class FirstByteDeadline
{
    /** The shortest read timeout set, in µs: a passed deadline still lets bytes already received through. */
    private const MIN_MICROSECONDS = 1;

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
     * Bounds the next read by what is left of the budget, rounded up to a
     * whole millisecond.
     */
    public function arm(): void
    {
        $leftNs = $this->deadlineNs - hrtime(true);
        $this->setTimeout(max(intdiv($leftNs + 999_999, 1_000_000) * 1000, self::MIN_MICROSECONDS));
    }

    /**
     * After a read that timed out: when the deadline is not reached yet (the
     * wait woke early), bounds the next read by what is left and returns
     * true, so the caller reads again; else false, and the timeout stands.
     */
    public function rearm(): bool
    {
        if (hrtime(true) >= $this->deadlineNs) {
            return false;
        }
        $this->arm();

        return true;
    }

    /**
     * Bounds each next read by the idle timeout again.
     */
    public function disarm(): void
    {
        $this->setTimeout(TimeoutPolicy::wholeMilliseconds($this->idleSeconds) * 1000);
    }

    private function setTimeout(int $microseconds): void
    {
        if (!\is_resource($this->resource)) {
            return;
        }
        // A stream that has no read timeout (an in-memory body) reports it
        // with `false` and a warning: nothing to bound then.
        @stream_set_timeout($this->resource, intdiv($microseconds, 1_000_000), $microseconds % 1_000_000);
    }
}
