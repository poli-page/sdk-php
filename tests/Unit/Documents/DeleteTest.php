<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Documents;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Documents;
use PoliPage\Exception\NotFoundException;
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

    public function testTransportErrorSurfacesToCaller(): void
    {
        $transport = new FakeTransport();
        $transport->deleteException = new NotFoundException(
            'document not found',
            PoliPageException::DOCUMENT_NOT_FOUND,
            404,
            'req_42',
        );
        $documents = new Documents($transport);

        try {
            $documents->delete('doc_abc');
            self::fail('Expected NotFoundException to surface');
        } catch (NotFoundException $e) {
            self::assertSame(404, $e->status);
            self::assertSame(PoliPageException::DOCUMENT_NOT_FOUND, $e->errorCode);
        }
    }
}
