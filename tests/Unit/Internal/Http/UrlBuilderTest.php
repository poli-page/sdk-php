<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Internal\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\Http\UrlBuilder;

#[CoversClass(UrlBuilder::class)]
final class UrlBuilderTest extends TestCase
{
    public function testConcatenatesBaseAndPath(): void
    {
        self::assertSame(
            'https://api.poli.page/v1/render',
            UrlBuilder::build('https://api.poli.page', '/v1/render'),
        );
    }

    public function testTrimsTrailingSlashOnBase(): void
    {
        self::assertSame(
            'https://api.poli.page/v1/render',
            UrlBuilder::build('https://api.poli.page/', '/v1/render'),
        );
    }

    public function testPrependsLeadingSlashIfPathLacksOne(): void
    {
        self::assertSame(
            'https://api.poli.page/v1/render',
            UrlBuilder::build('https://api.poli.page', 'v1/render'),
        );
    }

    public function testHandlesBothTrailingSlashAndMissingLeadingSlash(): void
    {
        self::assertSame(
            'https://api.poli.page/v1/render',
            UrlBuilder::build('https://api.poli.page/', 'v1/render'),
        );
    }

    public function testPreservesQueryStringOnPath(): void
    {
        self::assertSame(
            'https://api.poli.page/v1/documents?cursor=abc',
            UrlBuilder::build('https://api.poli.page', '/v1/documents?cursor=abc'),
        );
    }
}
