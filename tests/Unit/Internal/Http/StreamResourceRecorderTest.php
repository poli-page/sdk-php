<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Internal\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\Http\StreamResourceRecorder;

/**
 * The recorder hands every call to the factory it wraps and keeps the first
 * resource it is given.
 */
#[CoversClass(StreamResourceRecorder::class)]
final class StreamResourceRecorderTest extends TestCase
{
    public function testKeepsTheFirstResourceAndDelegatesEveryCall(): void
    {
        $recorder = (new StreamResourceRecorder())->around(new Psr17Factory());
        $first = fopen('php://memory', 'r+');
        $second = fopen('php://memory', 'r+');
        self::assertIsResource($first);
        self::assertIsResource($second);
        fwrite($first, 'first');
        rewind($first);

        self::assertNull($recorder->resource());
        self::assertSame('first', (string) $recorder->createStreamFromResource($first));
        $recorder->createStreamFromResource($second);

        self::assertSame($first, $recorder->resource());
        self::assertSame('text', (string) $recorder->createStream('text'));
        self::assertSame('<?php', substr((string) $recorder->createStreamFromFile(__FILE__), 0, 5));
    }

    public function testWithoutAFactoryIsALogicError(): void
    {
        $this->expectException(\LogicException::class);

        (new StreamResourceRecorder())->createStream('x');
    }
}
