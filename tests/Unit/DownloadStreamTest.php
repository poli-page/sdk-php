<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\Http\DownloadStream;
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
}
