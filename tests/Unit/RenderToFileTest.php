<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;
use PoliPage\PoliPage;
use PoliPage\ProjectModeInput;

use function PoliPage\renderToFile;

#[CoversFunction('PoliPage\renderToFile')]
final class RenderToFileTest extends TestCase
{
    private string $tmpPath = '';

    protected function tearDown(): void
    {
        if ($this->tmpPath !== '' && is_file($this->tmpPath)) {
            @unlink($this->tmpPath);
        }
        $tmpDir = dirname($this->tmpPath);
        if ($tmpDir !== '' && str_contains($tmpDir, 'sdk-php-test') && is_dir($tmpDir)) {
            @rmdir($tmpDir);
        }
    }

    public function testWritesPdfStreamToFile(): void
    {
        $this->tmpPath = sys_get_temp_dir() . '/sdk-php-test-' . uniqid('', true) . '.pdf';
        $factory = new Psr17Factory();
        $mock = new MockClient();
        $mock->addResponse(
            $factory->createResponse(200)->withBody(
                $factory->createStream(json_encode(self::descriptorWire(), flags: JSON_THROW_ON_ERROR)),
            ),
        );
        $mock->addResponse(
            $factory->createResponse(200)->withBody(
                $factory->createStream("\x25PDF-1.7\nfake-bytes-from-stream"),
            ),
        );
        $client = new PoliPage(
            apiKey: 'pp_test_x',
            httpClient: $mock,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        renderToFile($client, new ProjectModeInput(
            project: 'billing',
            template: 'invoice',
            data: ['n' => 1],
        ), $this->tmpPath);

        self::assertFileExists($this->tmpPath);
        self::assertSame("\x25PDF-1.7\nfake-bytes-from-stream", file_get_contents($this->tmpPath));
        // Two HTTP calls: hop 1 = /v1/render, hop 2 = presignedPdfUrl
        self::assertCount(2, $mock->getRequests());
    }

    public function testCreatesMissingParentDirectories(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sdk-php-test-' . uniqid('', true);
        $this->tmpPath = $tmpDir . '/nested/file.pdf';
        self::assertDirectoryDoesNotExist($tmpDir);

        $factory = new Psr17Factory();
        $mock = new MockClient();
        $mock->addResponse(
            $factory->createResponse(200)->withBody(
                $factory->createStream(json_encode(self::descriptorWire(), flags: JSON_THROW_ON_ERROR)),
            ),
        );
        $mock->addResponse(
            $factory->createResponse(200)->withBody(
                $factory->createStream('bytes'),
            ),
        );
        $client = new PoliPage(
            apiKey: 'pp_test_x',
            httpClient: $mock,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        renderToFile($client, new ProjectModeInput(project: 'p', template: 't', data: []), $this->tmpPath);

        self::assertFileExists($this->tmpPath);
        // Best-effort cleanup of the nested directory tree.
        @unlink($this->tmpPath);
        @rmdir(dirname($this->tmpPath));
        @rmdir($tmpDir);
    }

    /**
     * @return array<string, mixed>
     */
    private static function descriptorWire(): array
    {
        return [
            'documentId' => 'doc_abc',
            'organizationId' => 'org_xyz',
            'projectId' => null,
            'projectSlug' => 'p',
            'templateId' => null,
            'templateSlug' => 't',
            'version' => null,
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
        ];
    }
}
