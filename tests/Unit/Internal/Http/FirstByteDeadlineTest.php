<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Internal\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\Http\FirstByteDeadline;

/**
 * `arm()` bounds the next read of the stream by what is left of the budget,
 * `disarm()` by the idle timeout again.
 */
#[CoversClass(FirstByteDeadline::class)]
final class FirstByteDeadlineTest extends TestCase
{
    /** @var list<resource> */
    private array $sockets = [];

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            fclose($socket);
        }
    }

    public function testArmBoundsTheReadByWhatIsLeftOfTheBudget(): void
    {
        $reader = $this->silentSocket();
        $deadline = new FirstByteDeadline($reader, hrtime(true) + 200_000_000, 30.0);

        $deadline->arm();
        $startedAt = hrtime(true);
        $bytes = @fread($reader, 1);
        $elapsed = (hrtime(true) - $startedAt) / 1e9;

        self::assertContains($bytes, ['', false], 'no byte');
        self::assertTrue(stream_get_meta_data($reader)['timed_out']);
        self::assertGreaterThan(0.1, $elapsed);
        self::assertLessThan(5.0, $elapsed, 'not the 30 s idle timeout');
    }

    public function testArmAfterTheDeadlineStillLetsReceivedBytesThrough(): void
    {
        [$reader, $writer] = $this->socketPair();
        fwrite($writer, 'x');
        $deadline = new FirstByteDeadline($reader, hrtime(true) - 1_000_000_000, 30.0);

        $deadline->arm();

        self::assertSame('x', fread($reader, 1));
    }

    public function testDisarmPutsBackTheIdleTimeout(): void
    {
        $reader = $this->silentSocket();
        $deadline = new FirstByteDeadline($reader, hrtime(true) - 1_000_000_000, 0.3);
        $deadline->arm();

        $deadline->disarm();
        $startedAt = hrtime(true);
        @fread($reader, 1);

        self::assertGreaterThan(0.2, (hrtime(true) - $startedAt) / 1e9, 'the idle timeout, not the passed deadline');
    }

    public function testAStreamWithoutReadTimeoutOrAClosedOneIsLeftAlone(): void
    {
        $memory = fopen('php://memory', 'r+');
        self::assertIsResource($memory);
        $deadline = new FirstByteDeadline($memory, hrtime(true) + 1_000_000_000, 1.0);
        $deadline->arm();
        $deadline->disarm();
        fclose($memory);
        $deadline->arm();

        // Reached with no warning or error from either stream.
        self::assertFalse(\is_resource($memory));
    }

    /**
     * @return resource the reading end of a socket pair nothing is written to
     */
    private function silentSocket()
    {
        return $this->socketPair()[0];
    }

    /**
     * @return array{resource, resource}
     */
    private function socketPair(): array
    {
        $domain = \DIRECTORY_SEPARATOR === '\\' ? STREAM_PF_INET : STREAM_PF_UNIX;
        $pair = stream_socket_pair($domain, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            self::markTestSkipped('cannot create a socket pair');
        }
        array_push($this->sockets, ...$pair);

        return [$pair[0], $pair[1]];
    }
}
