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
        if (getenv('POLI_PAGE_API_KEY') === false || getenv('POLI_PAGE_API_KEY') === '') {
            self::markTestSkipped('POLI_PAGE_API_KEY is not set');
        }
    }

    public function testPreviewAgainstDevelop(): void
    {
        $apiKey = getenv('POLI_PAGE_API_KEY');
        \assert(is_string($apiKey) && $apiKey !== '');
        $baseUrl = getenv('POLI_PAGE_BASE_URL') ?: 'https://api-develop.poli.page';
        \assert(is_string($baseUrl));

        $project = getenv('POLI_PAGE_TEST_PROJECT') ?: 'getting-started';
        \assert(is_string($project));
        $template = getenv('POLI_PAGE_TEST_TEMPLATE') ?: 'welcome';
        \assert(is_string($template));
        $version = getenv('POLI_PAGE_TEST_VERSION') ?: '1.0.0';
        \assert(is_string($version));

        $client = new PoliPage(apiKey: $apiKey, baseUrl: $baseUrl);

        $result = $client->render->preview(new ProjectModeInput(
            project: $project,
            template: $template,
            data: ['name' => 'Integration Test'],
            version: $version,
        ));

        self::assertNotSame('', $result->html);
        // Spec: short inline previews may render to 0 pages (mirror Node 30cf4fd).
        self::assertGreaterThanOrEqual(0, $result->totalPages);
        self::assertContains($result->environment, ['sandbox', 'live']);
    }
}
