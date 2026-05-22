<?php

declare(strict_types=1);

namespace PoliPage;

use PoliPage\Internal\Transport;

/**
 * Stored document returned by `$client->render->document(...)`. Mirrors the
 * deployed API's wire shape one-to-one. Nullable wire fields (project ids,
 * template ids, version, etc.) map to `?string`; metadata is always
 * non-null (the server echoes `{}` when the caller omits it).
 *
 * `downloadPdf()` fetches the PDF bytes from `presignedPdfUrl` on demand
 * using the SDK's PSR-18 client (so injected TLS / proxy / mock layers
 * apply), but the request is **unauthenticated** and **not subject to the
 * SDK's retry policy** — the URL is already signed and the storage
 * endpoint does not honour either.
 *
 * The presigned URL has a 15-minute TTL; on `DOWNLOAD_FAILED`, call
 * `$client->documents->get($documentId)` to obtain a fresh URL (Phase 4).
 */
final readonly class DocumentDescriptor
{
    public function __construct(
        public string $documentId,
        public string $organizationId,
        public ?string $projectId,
        public ?string $projectSlug,
        public ?string $templateId,
        public ?string $templateSlug,
        public ?string $version,
        public string $environment,
        public ?string $apiKeyId,
        public string $format,
        public ?string $orientation,
        public ?string $locale,
        public int $pageCount,
        public int $sizeBytes,
        public string $createdAt,
        public RenderMetadata $metadata,
        public string $presignedPdfUrl,
        public string $expiresAt,
        /** @internal Transport back-reference; not exposed via getters. */
        private Transport $transport,
    ) {
    }

    /**
     * Download the PDF bytes referenced by `$presignedPdfUrl`. Re-issues
     * a fresh fetch every time; the URL has a 15-minute TTL — call
     * `$client->documents->get($this->documentId)->downloadPdf()` to refresh.
     *
     * @param float|null $timeout per-call timeout in seconds; `null` defers to the client default
     *
     * @throws Exception\TimeoutException     when the underlying client reports a timeout (Guzzle: cURL error 28)
     * @throws PoliPageException              with code DOWNLOAD_FAILED on non-2xx or transport failure
     */
    public function downloadPdf(?float $timeout = null): string
    {
        return $this->transport->fetchBytes($this->presignedPdfUrl, $timeout);
    }

    /**
     * Construct a descriptor from the deployed API's JSON wire shape. The
     * caller (Render) passes the same Transport it owns, so subsequent
     * `downloadPdf()` calls flow through the user-injected PSR-18 client.
     *
     * @internal Wire-parsing entry point; not part of the public API.
     *
     * @param array<array-key, mixed> $raw
     *
     * @throws PoliPageException with code INTERNAL_ERROR when the response shape is unexpected
     */
    public static function fromWire(array $raw, Transport $transport): self
    {
        return new self(
            documentId: self::stringField($raw, 'documentId'),
            organizationId: self::stringField($raw, 'organizationId'),
            projectId: self::nullableStringField($raw, 'projectId'),
            projectSlug: self::nullableStringField($raw, 'projectSlug'),
            templateId: self::nullableStringField($raw, 'templateId'),
            templateSlug: self::nullableStringField($raw, 'templateSlug'),
            version: self::nullableStringField($raw, 'version'),
            environment: self::stringField($raw, 'environment'),
            apiKeyId: self::nullableStringField($raw, 'apiKeyId'),
            format: self::stringField($raw, 'format'),
            orientation: self::nullableStringField($raw, 'orientation'),
            locale: self::nullableStringField($raw, 'locale'),
            pageCount: self::intField($raw, 'pageCount'),
            sizeBytes: self::intField($raw, 'sizeBytes'),
            createdAt: self::stringField($raw, 'createdAt'),
            metadata: new RenderMetadata(self::metadataField($raw)),
            presignedPdfUrl: self::stringField($raw, 'presignedPdfUrl'),
            expiresAt: self::stringField($raw, 'expiresAt'),
            transport: $transport,
        );
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private static function stringField(array $raw, string $name): string
    {
        $value = $raw[$name] ?? null;
        if (!is_string($value)) {
            throw self::shapeError($name, 'string', $value);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private static function nullableStringField(array $raw, string $name): ?string
    {
        $value = $raw[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw self::shapeError($name, 'string|null', $value);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private static function intField(array $raw, string $name): int
    {
        $value = $raw[$name] ?? null;
        if (!is_int($value)) {
            throw self::shapeError($name, 'int', $value);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private static function metadataField(array $raw): array
    {
        $value = $raw['metadata'] ?? [];
        if (!is_array($value)) {
            throw self::shapeError('metadata', 'object', $value);
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    private static function shapeError(string $field, string $expected, mixed $actual): PoliPageException
    {
        return new PoliPageException(
            sprintf(
                'Unexpected wire response: field "%s" must be %s, got %s',
                $field,
                $expected,
                get_debug_type($actual),
            ),
            PoliPageException::INTERNAL_ERROR,
        );
    }
}
