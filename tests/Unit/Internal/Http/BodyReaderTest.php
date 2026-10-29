<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Internal\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\Http\BodyReader;
use PoliPage\Tests\Support\FailingStream;

#[CoversClass(BodyReader::class)]
final class BodyReaderTest extends TestCase
{
    public function testReadsTheWholeBodyFromTheStart(): void
    {
        $stream = (new Psr17Factory())->createStream('{"ok":true}');
        $stream->read(3);

        self::assertSame('{"ok":true}', BodyReader::read($stream));
    }

    public function testAFailureMidBodyIsThrown(): void
    {
        $failure = new \RuntimeException('End of response with 86 bytes missing');

        $this->expectExceptionObject($failure);
        BodyReader::read(new FailingStream('{"doc', $failure));
    }

    public function testAWarningMidBodyBecomesAnErrorException(): void
    {
        try {
            BodyReader::read(new FailingStream('{"doc', new \RuntimeException('Idle timeout reached'), warn: true));
            self::fail('Expected an ErrorException');
        } catch (\ErrorException $e) {
            self::assertSame('Idle timeout reached', $e->getMessage());
            self::assertSame(E_USER_WARNING, $e->getSeverity());
        }
    }

    public function testTheErrorHandlerIsRestoredAfterwards(): void
    {
        $before = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        BodyReader::read((new Psr17Factory())->createStream('read'));
        try {
            BodyReader::read(new FailingStream('x', new \RuntimeException('dropped'), warn: true));
            self::fail('Expected the read to fail');
        } catch (\ErrorException) {
        }

        $after = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        self::assertSame($before, $after);
    }
}
