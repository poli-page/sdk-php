<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Documents;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Documents;
use PoliPage\Internal\Http\TextResponse;
use PoliPage\Tests\Support\FakeTransport;

#[CoversClass(Documents::class)]
final class PreviewTest extends TestCase
{
    public function testReadsBodyAndPageCountHeader(): void
    {
        $transport = new FakeTransport();
        $transport->getTextResponse = new TextResponse(
            body: '<html><body>page 1</body></html>',
            headers: ['X-Document-Page-Count' => ['7']],
        );
        $documents = new Documents($transport);

        $result = $documents->preview('doc_abc');

        self::assertSame('<html><body>page 1</body></html>', $result->html);
        self::assertSame(7, $result->pageCount);

        self::assertCount(1, $transport->getTextCalls);
        self::assertSame('/v1/documents/doc_abc/preview', $transport->getTextCalls[0]['path']);
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $transport = new FakeTransport();
        $transport->getTextResponse = new TextResponse(
            body: '<p>x</p>',
            headers: ['x-document-page-count' => ['3']], // lowercase
        );
        $documents = new Documents($transport);

        $result = $documents->preview('doc_abc');

        self::assertSame(3, $result->pageCount);
    }

    public function testMissingHeaderYieldsZeroPageCount(): void
    {
        $transport = new FakeTransport();
        $transport->getTextResponse = new TextResponse(
            body: '<p>x</p>',
            headers: [],
        );
        $documents = new Documents($transport);

        $result = $documents->preview('doc_abc');

        self::assertSame(0, $result->pageCount);
    }

    public function testUnparseableHeaderYieldsZeroPageCount(): void
    {
        $transport = new FakeTransport();
        $transport->getTextResponse = new TextResponse(
            body: '<p>x</p>',
            headers: ['X-Document-Page-Count' => ['not-a-number']],
        );
        $documents = new Documents($transport);

        $result = $documents->preview('doc_abc');

        self::assertSame(0, $result->pageCount);
    }

    public function testIdIsRawurlencodedInThePath(): void
    {
        $transport = new FakeTransport();
        $transport->getTextResponse = new TextResponse(body: '', headers: []);
        $documents = new Documents($transport);

        $documents->preview('doc/with slash');

        self::assertSame(
            '/v1/documents/doc%2Fwith%20slash/preview',
            $transport->getTextCalls[0]['path'],
        );
    }
}
