<?php

declare(strict_types=1);

namespace PoliPage\Tests\Support;

/**
 * The `POST /v1/render` / `GET /v1/documents/:id` response body, shared by
 * every test that needs a document descriptor on the wire.
 */
final class DocumentWire
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function descriptor(array $overrides = []): array
    {
        return array_replace([
            'documentId' => 'doc_abc',
            'organizationId' => 'org_xyz',
            'projectId' => null,
            'projectSlug' => 'p',
            'templateId' => null,
            'templateSlug' => 't',
            'environment' => 'sandbox',
            'apiKeyId' => null,
            'format' => 'A4',
            'orientation' => null,
            'locale' => null,
            'pageCount' => 1,
            'sizeBytes' => 256,
            'createdAt' => '2026-05-21T12:00:00Z',
            'metadata' => [],
            'presignedPdfUrl' => 'https://s3.example.com/doc.pdf?sig=abc',
            'expiresAt' => '2026-05-21T12:15:00Z',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function descriptorJson(array $overrides = []): string
    {
        return json_encode(self::descriptor($overrides), flags: JSON_THROW_ON_ERROR);
    }
}
