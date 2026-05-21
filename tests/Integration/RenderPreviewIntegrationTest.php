<?php

declare(strict_types=1);

namespace PoliPage\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PoliPage\PoliPage;
use PoliPage\ProjectModeInput;

/**
 * End-to-end smoke test against `api-develop.poli.page`. Gated on
 * `POLI_PAGE_API_KEY` — skipped when the env var is unset so contributors
 * without API credentials don't hit a hard failure.
 *
 * Renders the public `getting-started/welcome` template, which is
 * available in every test organisation per sdk-node integration setup.
 */
#[Group('integration')]
final class RenderPreviewIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        $apiKey = getenv('POLI_PAGE_API_KEY');
        if ($apiKey === false || $apiKey === '') {
            self::markTestSkipped('POLI_PAGE_API_KEY is not set');
        }
    }

    public function testPreviewAgainstDevelop(): void
    {
        $apiKey = getenv('POLI_PAGE_API_KEY');
        // setUp() guarantees a non-empty string; the assertion narrows for static analysis.
        self::assertNotFalse($apiKey);
        self::assertNotSame('', $apiKey);

        $client = new PoliPage(
            apiKey: $apiKey,
            baseUrl: self::envOrDefault('POLI_PAGE_BASE_URL', 'https://api-develop.poli.page'),
        );

        $result = $client->render->preview(new ProjectModeInput(
            project: self::envOrDefault('POLI_PAGE_TEST_PROJECT', 'getting-started'),
            template: self::envOrDefault('POLI_PAGE_TEST_TEMPLATE', 'welcome'),
            data: ['name' => 'Integration Test'],
            version: self::envOrDefault('POLI_PAGE_TEST_VERSION', '1.0.0'),
        ));

        self::assertNotSame('', $result->html);
        // Spec: short inline previews may render to 0 pages (mirror Node 30cf4fd).
        self::assertGreaterThanOrEqual(0, $result->totalPages);
        self::assertContains($result->environment, ['sandbox', 'live']);
    }

    private static function envOrDefault(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
