<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\DocumentDescriptor;
use PoliPage\PoliPageException;
use PoliPage\RenderMetadata;
use PoliPage\Tests\Support\FakeTransport;

#[CoversClass(DocumentDescriptor::class)]
final class DocumentDescriptorTest extends TestCase
{
    public function testFromWireBuildsCompleteDescriptor(): void
    {
        $transport = new FakeTransport();
        $descriptor = DocumentDescriptor::fromWire(self::wirePayload(), $transport);

        self::assertSame('doc_abc', $descriptor->documentId);
        self::assertSame('org_xyz', $descriptor->organizationId);
        self::assertSame('proj_1', $descriptor->projectId);
        self::assertSame('billing', $descriptor->projectSlug);
        self::assertSame('tmpl_1', $descriptor->templateId);
        self::assertSame('invoice', $descriptor->templateSlug);
        self::assertSame('1.0.0', $descriptor->version);
        self::assertSame('sandbox', $descriptor->environment);
        self::assertSame('key_42', $descriptor->apiKeyId);
        self::assertSame('A4', $descriptor->format);
        self::assertSame('portrait', $descriptor->orientation);
        self::assertSame('en-US', $descriptor->locale);
        self::assertSame(3, $descriptor->pageCount);
        self::assertSame(123_456, $descriptor->sizeBytes);
        self::assertSame('2026-05-21T13:00:00Z', $descriptor->createdAt);
        self::assertSame(['customerId' => 'cust_123', 'amount' => 1280], $descriptor->metadata->toArray());
        self::assertSame('https://s3.example.com/doc.pdf?sig=abc', $descriptor->presignedPdfUrl);
        self::assertSame('2026-05-21T13:15:00Z', $descriptor->expiresAt);
    }

    public function testFromWireAcceptsNullableFieldsAsNull(): void
    {
        $payload = self::wirePayload();
        $payload['projectId'] = null;
        $payload['projectSlug'] = null;
        $payload['templateId'] = null;
        $payload['templateSlug'] = null;
        $payload['version'] = null;
        $payload['apiKeyId'] = null;
        $payload['orientation'] = null;
        $payload['locale'] = null;

        $descriptor = DocumentDescriptor::fromWire($payload, new FakeTransport());

        self::assertNull($descriptor->projectId);
        self::assertNull($descriptor->projectSlug);
        self::assertNull($descriptor->templateId);
        self::assertNull($descriptor->templateSlug);
        self::assertNull($descriptor->version);
        self::assertNull($descriptor->apiKeyId);
        self::assertNull($descriptor->orientation);
        self::assertNull($descriptor->locale);
    }

    public function testFromWireDefaultsEmptyMetadataToEmptyRenderMetadata(): void
    {
        $payload = self::wirePayload();
        $payload['metadata'] = [];

        $descriptor = DocumentDescriptor::fromWire($payload, new FakeTransport());

        self::assertInstanceOf(RenderMetadata::class, $descriptor->metadata);
        self::assertSame([], $descriptor->metadata->toArray());
    }

    public function testFromWireThrowsOnMissingRequiredString(): void
    {
        $payload = self::wirePayload();
        unset($payload['documentId']);

        $this->expectException(PoliPageException::class);
        $this->expectExceptionMessage('field "documentId" must be string');
        DocumentDescriptor::fromWire($payload, new FakeTransport());
    }

    public function testFromWireThrowsOnWrongType(): void
    {
        $payload = self::wirePayload();
        $payload['pageCount'] = '3'; // wire bug: string instead of int

        $this->expectException(PoliPageException::class);
        $this->expectExceptionMessage('field "pageCount" must be int, got string');

        try {
            DocumentDescriptor::fromWire($payload, new FakeTransport());
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::INTERNAL_ERROR, $e->errorCode);

            throw $e;
        }
    }

    public function testDownloadPdfDelegatesToTransportWithPresignedUrl(): void
    {
        $transport = new FakeTransport();
        $transport->fetchBytesResponse = "\x25PDF-1.7\nfake pdf bytes";
        $descriptor = DocumentDescriptor::fromWire(self::wirePayload(), $transport);

        $bytes = $descriptor->downloadPdf();

        self::assertSame("\x25PDF-1.7\nfake pdf bytes", $bytes);
        self::assertCount(1, $transport->fetchBytesCalls);
        self::assertSame('https://s3.example.com/doc.pdf?sig=abc', $transport->fetchBytesCalls[0]['url']);
        self::assertNull($transport->fetchBytesCalls[0]['timeout']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function wirePayload(): array
    {
        return [
            'documentId' => 'doc_abc',
            'organizationId' => 'org_xyz',
            'projectId' => 'proj_1',
            'projectSlug' => 'billing',
            'templateId' => 'tmpl_1',
            'templateSlug' => 'invoice',
            'version' => '1.0.0',
            'environment' => 'sandbox',
            'apiKeyId' => 'key_42',
            'format' => 'A4',
            'orientation' => 'portrait',
            'locale' => 'en-US',
            'pageCount' => 3,
            'sizeBytes' => 123_456,
            'createdAt' => '2026-05-21T13:00:00Z',
            'metadata' => ['customerId' => 'cust_123', 'amount' => 1280],
            'presignedPdfUrl' => 'https://s3.example.com/doc.pdf?sig=abc',
            'expiresAt' => '2026-05-21T13:15:00Z',
        ];
    }
}
