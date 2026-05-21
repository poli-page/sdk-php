<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Documents;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Documents;
use PoliPage\Exception\GoneException;
use PoliPage\PoliPageException;
use PoliPage\Tests\Support\FakeTransport;

#[CoversClass(Documents::class)]
final class DeleteTest extends TestCase
{
    public function testDeletesByIdAndReturnsVoid(): void
    {
        $transport = new FakeTransport();
        $documents = new Documents($transport);

        $documents->delete('doc_abc');

        self::assertCount(1, $transport->deleteCalls);
        self::assertSame('/v1/documents/doc_abc', $transport->deleteCalls[0]['path']);
        self::assertNull($transport->deleteCalls[0]['timeout']);
    }

    public function testIdIsRawurlencodedInThePath(): void
    {
        $transport = new FakeTransport();
        $documents = new Documents($transport);

        $documents->delete('doc/with slash');

        self::assertSame(
            '/v1/documents/doc%2Fwith%20slash',
            $transport->deleteCalls[0]['path'],
        );
    }

    public function testTransportGoneExceptionSurfacesToCaller(): void
    {
        $transport = new FakeTransport();
        $transport->deleteException = new GoneException(
            'document already deleted',
            PoliPageException::GONE,
            410,
            'req_42',
        );
        $documents = new Documents($transport);

        try {
            $documents->delete('doc_abc');
            self::fail('Expected GoneException to surface');
        } catch (GoneException $e) {
            self::assertSame(410, $e->status);
            self::assertSame(PoliPageException::GONE, $e->errorCode);
        }
    }
}
