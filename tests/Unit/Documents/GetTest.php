<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Documents;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Documents;
use PoliPage\Tests\Support\FakeTransport;

#[CoversClass(Documents::class)]
final class GetTest extends TestCase
{
    public function testGetsDocumentByIdAndReturnsDescriptor(): void
    {
        $transport = new FakeTransport();
        $transport->getResponse = self::wirePayload('doc_abc');
        $documents = new Documents($transport);

        $descriptor = $documents->get('doc_abc');

        self::assertSame('doc_abc', $descriptor->documentId);
        self::assertCount(1, $transport->getCalls);
        self::assertSame('/v1/documents/doc_abc', $transport->getCalls[0]['path']);
        self::assertNull($transport->getCalls[0]['timeout']);
    }

    public function testIdIsRawurlencodedInThePath(): void
    {
        $transport = new FakeTransport();
        $transport->getResponse = self::wirePayload('doc/with slash');
        $documents = new Documents($transport);

        $documents->get('doc/with slash');

        self::assertSame(
            '/v1/documents/doc%2Fwith%20slash',
            $transport->getCalls[0]['path'],
        );
    }

    public function testReturnedDescriptorDownloadsThroughTheSameTransport(): void
    {
        $transport = new FakeTransport();
        $transport->getResponse = self::wirePayload('doc_abc');
        $transport->fetchBytesResponse = 'fresh-pdf-bytes';
        $documents = new Documents($transport);

        $descriptor = $documents->get('doc_abc');
        $bytes = $descriptor->downloadPdf();

        self::assertSame('fresh-pdf-bytes', $bytes);
        self::assertSame(
            'https://s3.example.com/doc_abc.pdf?sig=fresh',
            $transport->fetchBytesCalls[0]['url'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function wirePayload(string $id): array
    {
        return [
            'documentId' => $id,
            'organizationId' => 'org_xyz',
            'projectId' => null,
            'projectSlug' => 'billing',
            'templateId' => null,
            'templateSlug' => 'invoice',
            'version' => '1.0.0',
            'environment' => 'sandbox',
            'apiKeyId' => null,
            'format' => 'A4',
            'orientation' => null,
            'locale' => null,
            'pageCount' => 1,
            'sizeBytes' => 256,
            'createdAt' => '2026-05-21T12:00:00Z',
            'metadata' => [],
            'presignedPdfUrl' => 'https://s3.example.com/' . $id . '.pdf?sig=fresh',
            'expiresAt' => '2026-05-21T12:15:00Z',
        ];
    }
}
