<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\Http\DownloadStream;
use PoliPage\Internal\Http\FirstByteDeadline;
use PoliPage\PoliPageException;
use Psr\Http\Message\StreamInterface;

/**
 * A PSR-7 stream over a socket can report an elapsed read timeout only
 * through its `timed_out` metadata (guzzlehttp/psr7 2.x does): the download
 * stream turns it into DOWNLOAD_FAILED with the timeout message, never a
 * short body.
 */
#[CoversClass(DownloadStream::class)]
final class DownloadStreamTest extends TestCase
{
    private const BODY = '%PDF-1.7 a complete body';

    public function testAnEmptyReadFlaggedTimedOutIsATimeout(): void
    {
        $stream = new DownloadStream(self::socketLike(read: '', timedOut: true));

        $this->assertTimeout(static fn (): string => $stream->read(8192));
    }

    public function testAReadErrorFlaggedTimedOutIsATimeout(): void
    {
        $stream = new DownloadStream(self::socketLike(
            read: new \RuntimeException('Unable to read from stream'),
            timedOut: true,
        ));

        $this->assertTimeout(static fn (): string => $stream->read(8192));
    }

    public function testGetContentsThatStopsShortFlaggedTimedOutIsATimeout(): void
    {
        $stream = new DownloadStream(self::socketLike(read: '%PDF-1.7 partial', timedOut: true));

        $this->assertTimeout(static fn (): string => $stream->getContents());
    }

    public function testAReadThatReturnedBytesIsKeptWhateverTheFlag(): void
    {
        $stream = new DownloadStream(self::socketLike(read: '%PDF-1.7', timedOut: true));

        self::assertSame('%PDF-1.7', $stream->read(8192));
    }

    public function testAReadErrorWithoutTheFlagIsANetworkError(): void
    {
        $stream = new DownloadStream(self::socketLike(
            read: new \RuntimeException('Unable to read from stream'),
            timedOut: false,
        ));

        try {
            $stream->read(8192);
            self::fail('Expected DOWNLOAD_FAILED');
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::DOWNLOAD_FAILED, $e->errorCode);
            self::assertSame('Failed to download PDF: network error', $e->getMessage());
        }
    }

    public function testABodyThatEndsShortOfItsLengthIsANetworkError(): void
    {
        $inner = FnStream::decorate(Utils::streamFor('%PDF-1.7 short'), []);
        $stream = new DownloadStream($inner, 1000);

        try {
            while (!$stream->eof()) {
                $stream->read(8192);
            }
            self::fail('Expected DOWNLOAD_FAILED');
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::DOWNLOAD_FAILED, $e->errorCode);
            self::assertSame('Failed to download PDF: network error', $e->getMessage());
            self::assertNull($e->status);
        }
    }

    public function testABodyOfItsAnnouncedLengthPasses(): void
    {
        $stream = new DownloadStream(Utils::streamFor('%PDF-1.7'), 8);

        self::assertSame('%PDF-1.7', $stream->getContents());
        self::assertTrue($stream->eof());
    }

    public function testAPartialReadThenACastOfANonSeekableBodyPasses(): void
    {
        $inner = FnStream::decorate(Utils::streamFor(self::BODY), ['isSeekable' => static fn (): bool => false]);
        $stream = new DownloadStream($inner, \strlen(self::BODY));

        self::assertSame('%PDF-', $stream->read(5));
        self::assertSame(substr(self::BODY, 5), (string) $stream);
        self::assertTrue($stream->eof());
    }

    public function testAPartialReadThenACastOfASeekableBodyPasses(): void
    {
        $stream = new DownloadStream(Utils::streamFor(self::BODY), \strlen(self::BODY));

        self::assertSame('%PDF-', $stream->read(5));
        self::assertSame(self::BODY, (string) $stream);
        self::assertTrue($stream->eof());
    }

    public function testASeekForwardThenGetContentsPasses(): void
    {
        $stream = new DownloadStream(Utils::streamFor(self::BODY), \strlen(self::BODY));

        $stream->seek(5);

        self::assertSame(substr(self::BODY, 5), $stream->getContents());
        self::assertTrue($stream->eof());
    }

    public function testARewindAfterAPartialReadPasses(): void
    {
        $stream = new DownloadStream(Utils::streamFor(self::BODY), \strlen(self::BODY));

        $stream->read(5);
        $stream->rewind();

        self::assertSame(self::BODY, $stream->getContents());
        self::assertTrue($stream->eof());
    }

    public function testEofStaysFalseOnAShortBodyAndTheNextReadRaises(): void
    {
        $inner = Utils::streamFor('%PDF-1.7 short');
        $inner->getContents();
        $stream = new DownloadStream($inner, 1000);

        self::assertFalse($stream->eof(), 'a short body is never reported complete');
        $this->assertNetworkFailure(static fn (): string => $stream->read(8192));
    }

    /**
     * @param \Closure(): string $read
     */
    private function assertNetworkFailure(\Closure $read): void
    {
        try {
            $read();
            self::fail('Expected DOWNLOAD_FAILED');
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::DOWNLOAD_FAILED, $e->errorCode);
            self::assertSame('Failed to download PDF: network error', $e->getMessage());
        }
    }

    /**
     * @param \Closure(): string $read
     */
    private function assertTimeout(\Closure $read): void
    {
        try {
            $read();
            self::fail('Expected DOWNLOAD_FAILED');
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::DOWNLOAD_FAILED, $e->errorCode);
            self::assertSame('Failed to download PDF: timeout', $e->getMessage());
            self::assertNull($e->status);
            self::assertFalse($e->isRetryable());
        }
    }

    /**
     * A body that is never at its end, whose reads give `$read` (or throw
     * it) and whose `timed_out` metadata is `$timedOut`.
     */
    private static function socketLike(string|\RuntimeException $read, bool $timedOut): StreamInterface
    {
        $give = static function () use ($read): string {
            if ($read instanceof \RuntimeException) {
                throw $read;
            }

            return $read;
        };

        return FnStream::decorate(Utils::streamFor(''), [
            'read' => static fn (int $length): string => $give(),
            'getContents' => $give,
            'eof' => static fn (): bool => false,
            'getMetadata' => static fn (?string $key = null): mixed => $key === 'timed_out' ? $timedOut : null,
        ]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reads(): iterable
    {
        yield 'read' => ['read'];
        yield 'getContents' => ['getContents'];
        yield '__toString' => ['__toString'];
    }

    #[DataProvider('reads')]
    public function testTheFirstReadWaitsOnlyForWhatIsLeftOfTheBudget(string $read): void
    {
        [$reader] = $this->socketPair();
        $stream = new DownloadStream(
            Utils::streamFor($reader),
            8,
            new FirstByteDeadline($reader, hrtime(true) + 200_000_000, 30.0),
        );

        $startedAt = hrtime(true);
        try {
            self::readWith($stream, $read);
            self::fail('Expected DOWNLOAD_FAILED');
        } catch (PoliPageException $e) {
            self::assertSame('Failed to download PDF: timeout', $e->getMessage());
        }
        self::assertLessThan(5.0, (hrtime(true) - $startedAt) / 1e9, 'not the 30 s idle timeout');
    }

    /**
     * A read to the end takes a first chunk within the budget, then the rest.
     */
    #[DataProvider('reads')]
    public function testAfterTheFirstByteTheWholeBodyIsRead(string $read): void
    {
        $body = str_repeat('%PDF-chunk', 2000);
        $memory = self::memory($body);
        $stream = new DownloadStream(
            Utils::streamFor($memory),
            \strlen($body),
            new FirstByteDeadline($memory, hrtime(true) + 5_000_000_000, 5.0),
        );

        $bytes = '';
        do {
            $bytes .= self::readWith($stream, $read);
        } while (!$stream->eof());

        self::assertSame($body, $bytes);
    }

    public function testASeekOrARewindDropsTheFirstByteBound(): void
    {
        $memory = self::memory(self::BODY);
        $stream = new DownloadStream(
            Utils::streamFor($memory),
            \strlen(self::BODY),
            new FirstByteDeadline($memory, hrtime(true) + 5_000_000_000, 5.0),
        );

        $stream->seek(5);
        self::assertSame(self::BODY, (string) $stream, 'a cast rewinds once the bound is dropped');
        $stream->rewind();
        self::assertSame(self::BODY, $stream->getContents());
    }

    /**
     * @return resource
     */
    private static function memory(string $content)
    {
        $memory = fopen('php://memory', 'r+');
        self::assertIsResource($memory);
        fwrite($memory, $content);
        rewind($memory);

        return $memory;
    }

    private static function readWith(StreamInterface $stream, string $read): string
    {
        return match ($read) {
            'getContents' => $stream->getContents(),
            '__toString' => (string) $stream,
            default => $stream->read(8192),
        };
    }

    /** @var list<resource> */
    private array $sockets = [];

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            if (\is_resource($socket)) {
                fclose($socket);
            }
        }
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
