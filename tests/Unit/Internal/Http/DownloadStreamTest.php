<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Internal\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\Http\DownloadStream;
use PoliPage\PoliPageException;
use PoliPage\Tests\Support\FailingStream;

#[CoversClass(DownloadStream::class)]
final class DownloadStreamTest extends TestCase
{
    public function testReadsLikeTheStreamItWraps(): void
    {
        $stream = new DownloadStream((new Psr17Factory())->createStream('%PDF-1.4 body'));

        self::assertTrue($stream->isReadable());
        self::assertFalse($stream->isWritable());
        self::assertTrue($stream->isSeekable());
        self::assertSame(13, $stream->getSize());
        self::assertSame('%PDF', $stream->read(4));
        self::assertSame(4, $stream->tell());
        self::assertSame('-1.4 body', $stream->getContents());
        self::assertTrue($stream->eof());
        $stream->seek(1);
        self::assertSame('PDF', $stream->read(3));
        $stream->rewind();
        self::assertSame('%PDF-1.4 body', (string) $stream);
        self::assertSame('%PDF-1.4 body', (string) $stream, 'casting reads from the start again');
        self::assertIsArray($stream->getMetadata());
        self::assertIsResource($stream->detach());
    }

    public function testIsReadOnly(): void
    {
        $this->expectException(\RuntimeException::class);
        (new DownloadStream((new Psr17Factory())->createStream('x')))->write('y');
    }

    public function testCloseClosesTheWrappedStream(): void
    {
        $inner = (new Psr17Factory())->createStream('x');
        (new DownloadStream($inner))->close();

        self::assertNull($inner->detach());
    }

    public function testAReadFailureIsDownloadFailed(): void
    {
        $failure = new \RuntimeException('End of response with 4000 bytes missing');
        $stream = new DownloadStream(new FailingStream('%PDF', $failure));
        self::assertSame('%PDF', $stream->read(8192));

        try {
            $stream->read(8192);
            self::fail('Expected DOWNLOAD_FAILED');
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::DOWNLOAD_FAILED, $e->errorCode);
            self::assertSame('Failed to download PDF: network error', $e->getMessage());
            self::assertNull($e->status);
            self::assertSame($failure, $e->getPrevious());
        }
    }

    public function testGetContentsAndCastFailuresAreDownloadFailed(): void
    {
        foreach ([
            static fn (DownloadStream $s): string => $s->getContents(),
            static fn (DownloadStream $s): string => (string) $s,
        ] as $read) {
            $stream = new DownloadStream(new FailingStream('%PDF', new \RuntimeException('dropped'), warn: true));
            try {
                $read($stream);
                self::fail('Expected DOWNLOAD_FAILED');
            } catch (PoliPageException $e) {
                self::assertSame(PoliPageException::DOWNLOAD_FAILED, $e->errorCode);
                self::assertInstanceOf(\ErrorException::class, $e->getPrevious());
            }
        }
    }
}
