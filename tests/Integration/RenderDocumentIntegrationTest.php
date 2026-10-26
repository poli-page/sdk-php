<?php

declare(strict_types=1);

namespace PoliPage\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PoliPage\PoliPage;
use PoliPage\RenderInput;

/**
 * End-to-end smoke test against the API at `POLI_PAGE_BASE_URL` (the SDK
 * default when unset). Gated on `POLI_PAGE_API_KEY` — skipped when the env
 * var is unset so contributors without API credentials don't hit a hard
 * failure.
 *
 * Renders the `getting-started` project's `welcome` template, which every
 * organization gets published into its `sandbox` environment; use a
 * sandbox key of an organization that left that project untouched.
 */
#[Group('integration')]
final class RenderDocumentIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        $apiKey = getenv('POLI_PAGE_API_KEY');
        if ($apiKey === false || $apiKey === '') {
            self::markTestSkipped('POLI_PAGE_API_KEY is not set');
        }
    }

    public function testRendersGettingStartedWelcomeWithoutAVersion(): void
    {
        $apiKey = getenv('POLI_PAGE_API_KEY');
        // setUp() guarantees a non-empty string; the assertion narrows for static analysis.
        self::assertNotFalse($apiKey);
        self::assertNotSame('', $apiKey);

        $baseUrl = getenv('POLI_PAGE_BASE_URL');
        $client = new PoliPage(
            apiKey: $apiKey,
            baseUrl: is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null,
        );

        $document = $client->render->document(new RenderInput(
            project: 'getting-started',
            template: 'welcome',
            data: ['name' => 'Integration Test'],
        ));

        self::assertNotSame('', $document->documentId);
        self::assertNotSame('', $document->environment);
        self::assertGreaterThanOrEqual(1, $document->pageCount);
        self::assertStringStartsWith('%PDF', $document->downloadPdf());
    }
}
