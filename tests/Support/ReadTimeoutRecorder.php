<?php

declare(strict_types=1);

namespace PoliPage\Tests\Support;

/**
 * A PHP stream that records each read timeout `stream_set_timeout()` sets
 * on it, in microseconds: PHP does not report a stream's read timeout, and
 * a timing measured on a real socket depends on the scheduler.
 */
final class ReadTimeoutRecorder
{
    private const PROTOCOL = 'poli-read-timeout';

    /** @var list<int> the read timeouts set, in µs, oldest first */
    public static array $microseconds = [];

    /** @var resource|null set by PHP */
    public $context;

    /**
     * A new stream to record on; forgets what was recorded before.
     *
     * @return resource
     */
    public static function open()
    {
        if (!\in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
        self::$microseconds = [];
        $stream = fopen(self::PROTOCOL . '://stream', 'r');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open a read timeout recorder');
        }

        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        if ($option !== STREAM_OPTION_READ_TIMEOUT) {
            return false;
        }
        self::$microseconds[] = $arg1 * 1_000_000 + ($arg2 ?? 0);

        return true;
    }
}
