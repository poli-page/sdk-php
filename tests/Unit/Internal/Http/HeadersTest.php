<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Internal\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\Http\Headers;

#[CoversClass(Headers::class)]
final class HeadersTest extends TestCase
{
    private const UA = 'poli-page-sdk-php/1.0.0';

    public function testAlwaysSetsAcceptApplicationJson(): void
    {
        self::assertSame('application/json', Headers::build('POST', 'pp_test_x', 'idem-1', self::UA)['Accept']);
        self::assertSame('application/json', Headers::build('GET', 'pp_test_x', null, self::UA)['Accept']);
        self::assertSame('application/json', Headers::build('DELETE', 'pp_test_x', null, self::UA)['Accept']);
    }

    public function testSetsContentTypeApplicationJsonOnPost(): void
    {
        $headers = Headers::build('POST', 'pp_test_x', 'idem-1', self::UA);
        self::assertSame('application/json', $headers['Content-Type']);
    }

    public function testSetsAuthorizationWithBearerPrefix(): void
    {
        $headers = Headers::build('POST', 'pp_test_xyz', 'idem-1', self::UA);
        self::assertSame('Bearer pp_test_xyz', $headers['Authorization']);
    }

    public function testSetsTheSuppliedUserAgentVerbatim(): void
    {
        $headers = Headers::build('POST', 'pp_test_x', 'idem-1', 'custom-ua/9.9.9');
        self::assertSame('custom-ua/9.9.9', $headers['User-Agent']);
    }

    public function testSetsIdempotencyKeyFromArgumentOnPost(): void
    {
        $headers = Headers::build('POST', 'pp_test_x', 'idem-abc-123', self::UA);
        self::assertSame('idem-abc-123', $headers['Idempotency-Key']);
    }

    public function testGetOmitsContentTypeAndIdempotencyKeyButKeepsAuthUaAccept(): void
    {
        $headers = Headers::build('GET', 'pp_test_x', null, self::UA);
        self::assertArrayNotHasKey('Content-Type', $headers);
        self::assertArrayNotHasKey('Idempotency-Key', $headers);
        self::assertSame('Bearer pp_test_x', $headers['Authorization']);
        self::assertSame(self::UA, $headers['User-Agent']);
        self::assertSame('application/json', $headers['Accept']);
    }

    public function testDeleteOmitsContentTypeAndIdempotencyKeyButKeepsAuthUaAccept(): void
    {
        $headers = Headers::build('DELETE', 'pp_test_x', null, self::UA);
        self::assertArrayNotHasKey('Content-Type', $headers);
        self::assertArrayNotHasKey('Idempotency-Key', $headers);
        self::assertSame('Bearer pp_test_x', $headers['Authorization']);
        self::assertSame(self::UA, $headers['User-Agent']);
        self::assertSame('application/json', $headers['Accept']);
    }

    public function testPostWithoutIdempotencyKeyKeepsContentTypeButOmitsIdempotencyHeader(): void
    {
        $headers = Headers::build('POST', 'pp_test_x', null, self::UA);
        self::assertSame('application/json', $headers['Content-Type']);
        self::assertArrayNotHasKey('Idempotency-Key', $headers);
    }
}
