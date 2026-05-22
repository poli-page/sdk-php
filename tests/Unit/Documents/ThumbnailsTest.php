<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Documents;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Documents;
use PoliPage\PoliPageException;
use PoliPage\Tests\Support\FakeTransport;
use PoliPage\ThumbnailOptions;

#[CoversClass(Documents::class)]
final class ThumbnailsTest extends TestCase
{
    public function testWrapsOptionsAndUnwrapsResponse(): void
    {
        $transport = new FakeTransport();
        $transport->postResponse = [
            'thumbnails' => [
                [
                    'page' => 1,
                    'width' => 840,
                    'height' => 1188,
                    'contentType' => 'image/png',
                    'data' => base64_encode('fake-png-bytes-page-1'),
                ],
                [
                    'page' => 2,
                    'width' => 840,
                    'height' => 1188,
                    'contentType' => 'image/png',
                    'data' => base64_encode('fake-png-bytes-page-2'),
                ],
            ],
        ];
        $documents = new Documents($transport);

        $thumbs = $documents->thumbnails('doc_abc', new ThumbnailOptions(
            width: 840,
            format: 'png',
        ));

        self::assertCount(2, $thumbs);
        self::assertSame(1, $thumbs[0]->page);
        self::assertSame('image/png', $thumbs[0]->contentType);
        self::assertSame('fake-png-bytes-page-1', base64_decode($thumbs[0]->data, true));

        // Request shape: nested under "thumbnails" key, sent to the right path.
        self::assertCount(1, $transport->postCalls);
        $call = $transport->postCalls[0];
        self::assertSame('/v1/documents/doc_abc/thumbnails', $call['path']);
        self::assertSame(
            ['thumbnails' => ['width' => 840, 'format' => 'png']],
            $call['body'],
        );
    }

    public function testOmitsNullOptionalsFromTheNestedWireBody(): void
    {
        $transport = new FakeTransport();
        $transport->postResponse = ['thumbnails' => []];
        $documents = new Documents($transport);

        $documents->thumbnails('doc_abc', new ThumbnailOptions(width: 840));

        self::assertSame(
            ['thumbnails' => ['width' => 840]],
            $transport->postCalls[0]['body'],
        );
    }

    public function testForwardsAllOptionalsWhenSupplied(): void
    {
        $transport = new FakeTransport();
        $transport->postResponse = ['thumbnails' => []];
        $documents = new Documents($transport);

        $documents->thumbnails('doc_abc', new ThumbnailOptions(
            width: 200,
            format: 'jpeg',
            quality: 75,
            pages: [1, 3, 5],
        ));

        self::assertSame(
            [
                'thumbnails' => [
                    'width' => 200,
                    'format' => 'jpeg',
                    'quality' => 75,
                    'pages' => [1, 3, 5],
                ],
            ],
            $transport->postCalls[0]['body'],
        );
    }

    public function testIdIsRawurlencodedInThePath(): void
    {
        $transport = new FakeTransport();
        $transport->postResponse = ['thumbnails' => []];
        $documents = new Documents($transport);

        $documents->thumbnails('doc/with slash', new ThumbnailOptions(width: 100));

        self::assertSame(
            '/v1/documents/doc%2Fwith%20slash/thumbnails',
            $transport->postCalls[0]['path'],
        );
    }

    public function testThrowsInternalErrorWhenResponseLacksThumbnailsArray(): void
    {
        $transport = new FakeTransport();
        $transport->postResponse = ['oops' => true];
        $documents = new Documents($transport);

        $this->expectException(PoliPageException::class);
        $this->expectExceptionMessage('missing or non-array "thumbnails" field');

        $documents->thumbnails('doc_abc', new ThumbnailOptions(width: 100));
    }

    public function testThrowsInternalErrorOnMalformedThumbnailEntry(): void
    {
        $transport = new FakeTransport();
        $transport->postResponse = [
            'thumbnails' => [['page' => 'one']], // page should be int
        ];
        $documents = new Documents($transport);

        $this->expectException(PoliPageException::class);
        $this->expectExceptionMessage('Unexpected thumbnail wire shape');

        $documents->thumbnails('doc_abc', new ThumbnailOptions(width: 100));
    }
}
