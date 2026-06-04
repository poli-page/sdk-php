<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Events;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Events\ResponseEvent;

#[CoversClass(ResponseEvent::class)]
final class ResponseEventTest extends TestCase
{
    public function testConstructorAndReadonlyFieldsWithRequestId(): void
    {
        $event = new ResponseEvent(
            status: 200,
            requestId: 'req_abc123',
            durationMs: 142,
        );

        self::assertSame(200, $event->status);
        self::assertSame('req_abc123', $event->requestId);
        self::assertSame(142, $event->durationMs);
    }

    public function testNullableRequestId(): void
    {
        $event = new ResponseEvent(
            status: 201,
            requestId: null,
            durationMs: 55,
        );

        self::assertSame(201, $event->status);
        self::assertNull($event->requestId);
        self::assertSame(55, $event->durationMs);
    }
}
