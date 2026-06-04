<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Events;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Events\RequestEvent;

#[CoversClass(RequestEvent::class)]
final class RequestEventTest extends TestCase
{
    public function testConstructorAndReadonlyFields(): void
    {
        $event = new RequestEvent(
            method: 'POST',
            url: 'https://api.poli.page/v1/render/preview',
            attempt: 1,
        );

        self::assertSame('POST', $event->method);
        self::assertSame('https://api.poli.page/v1/render/preview', $event->url);
        self::assertSame(1, $event->attempt);
    }

    public function testSecondAttemptCarriesCorrectAttemptNumber(): void
    {
        $event = new RequestEvent(
            method: 'GET',
            url: 'https://api.poli.page/v1/documents/abc',
            attempt: 2,
        );

        self::assertSame('GET', $event->method);
        self::assertSame(2, $event->attempt);
    }
}
