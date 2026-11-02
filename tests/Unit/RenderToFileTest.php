<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\AtomicFileWriter;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\RenderInput;

use function PoliPage\renderToFile;

use PoliPage\Tests\Support\DocumentWire;
use PoliPage\Tests\Support\FailingStream;

/**
 * `renderToFile()` writes through a temporary file renamed over the target:
 * a file that cannot be written is `io_error` (no status, the message
 * `Failed to write PDF: <reason>`, PHP's error as `getPrevious()`, never
 * retried), and any failure leaves the target untouched.
 */
#[CoversFunction('PoliPage\renderToFile')]
#[CoversClass(AtomicFileWriter::class)]
final class RenderToFileTest extends TestCase
{
    private string $dir = '';

    private Psr17Factory $factory;

    private MockClient $mock;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sdk-php-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->factory = new Psr17Factory();
        $this->mock = new MockClient();
    }

    protected function tearDown(): void
    {
        @chmod($this->dir, 0o755);
        self::removeTree($this->dir);
    }

    public function testWritesPdfStreamToFile(): void
    {
        $path = $this->dir . '/invoice.pdf';
        $this->answerRenderThenPdf("\x25PDF-1.7\nfake-bytes-from-stream");

        renderToFile($this->client(), self::input(), $path);

        self::assertSame("\x25PDF-1.7\nfake-bytes-from-stream", file_get_contents($path));
        // Two HTTP calls: hop 1 = /v1/render, hop 2 = presignedPdfUrl
        self::assertCount(2, $this->mock->getRequests());
        self::assertSame(['invoice.pdf'], self::entries($this->dir), 'no temporary file left');
    }

    public function testCreatesMissingParentDirectories(): void
    {
        $path = $this->dir . '/nested/deeper/file.pdf';
        $this->answerRenderThenPdf('bytes');

        renderToFile($this->client(), self::input(), $path);

        self::assertSame('bytes', file_get_contents($path));
    }

    public function testOverwritesAnExistingFile(): void
    {
        $path = $this->dir . '/invoice.pdf';
        file_put_contents($path, 'old content');
        $this->answerRenderThenPdf('%PDF-1.7 new');

        renderToFile($this->client(), self::input(), $path);

        self::assertSame('%PDF-1.7 new', file_get_contents($path));
        self::assertSame(['invoice.pdf'], self::entries($this->dir));
    }

    public function testParentDirectoryThatCannotBeCreatedIsAnIoErrorBeforeAnyRequest(): void
    {
        // A path component that is a file: the directory cannot be created.
        file_put_contents($this->dir . '/not-a-dir', 'x');
        $path = $this->dir . '/not-a-dir/sub/invoice.pdf';

        $error = $this->ioError($path);

        self::assertCount(0, $this->mock->getRequests(), 'no render for a path that cannot be written');
        self::assertInstanceOf(\ErrorException::class, $error->getPrevious());
        self::assertSame(['not-a-dir'], self::entries($this->dir));
    }

    public function testFileThatCannotBeMovedIntoPlaceIsAnIoErrorAndLeavesNoTemporaryFile(): void
    {
        // The target is a non-empty directory: the PDF cannot replace it.
        $path = $this->dir . '/invoice.pdf';
        mkdir($path);
        file_put_contents($path . '/keep', 'x');
        $this->answerRenderThenPdf('%PDF-1.7');

        $error = $this->ioError($path);

        self::assertCount(2, $this->mock->getRequests());
        self::assertInstanceOf(\ErrorException::class, $error->getPrevious());
        self::assertSame(['keep'], self::entries($path), 'the target is left untouched');
        self::assertSame(['invoice.pdf'], self::entries($this->dir), 'no temporary file left');
    }

    public function testUnwritableDirectoryIsAnIoErrorAndLeavesTheTargetUntouched(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\' || (\function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            self::markTestSkipped('needs POSIX permissions enforced on the test user');
        }
        $path = $this->dir . '/invoice.pdf';
        file_put_contents($path, 'old content');
        chmod($this->dir, 0o555);

        $error = $this->ioError($path);

        self::assertCount(0, $this->mock->getRequests(), 'no render for a path that cannot be written');
        self::assertInstanceOf(\ErrorException::class, $error->getPrevious());
        self::assertSame('old content', file_get_contents($path));
    }

    public function testDownloadFailureMidStreamLeavesAnExistingTargetUntouched(): void
    {
        $path = $this->dir . '/invoice.pdf';
        file_put_contents($path, 'old content');
        $this->mock->addResponse($this->factory->createResponse(200)->withBody(
            $this->factory->createStream(DocumentWire::descriptorJson()),
        ));
        $this->mock->addResponse($this->factory->createResponse(200)->withBody(
            new FailingStream('%PDF-1.7 partial', new \RuntimeException('End of response with 4000 bytes missing')),
        ));

        try {
            renderToFile($this->client(), self::input(), $path);
            self::fail('Expected DOWNLOAD_FAILED');
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::DOWNLOAD_FAILED, $e->errorCode);
        }

        self::assertSame('old content', file_get_contents($path));
        self::assertSame(['invoice.pdf'], self::entries($this->dir), 'no temporary file left');
    }

    public function testRenderFailureDoesNotCreateTheTarget(): void
    {
        $path = $this->dir . '/invoice.pdf';
        $this->mock->addResponse($this->factory->createResponse(404)->withBody(
            $this->factory->createStream('{"error":"TEMPLATE_NOT_FOUND","detail":"Template not found"}'),
        ));

        try {
            renderToFile($this->client(), self::input(), $path);
            self::fail('Expected TEMPLATE_NOT_FOUND');
        } catch (PoliPageException $e) {
            self::assertSame('TEMPLATE_NOT_FOUND', $e->errorCode);
        }

        self::assertSame([], self::entries($this->dir), 'neither the target nor a temporary file');
    }

    /**
     * Runs renderToFile() and returns its io_error, checked against the
     * error's own shape.
     */
    private function ioError(string $path): PoliPageException
    {
        try {
            renderToFile($this->client(maxRetries: 2), self::input(), $path);
        } catch (PoliPageException $e) {
            self::assertSame(PoliPageException::IO_ERROR, $e->errorCode, $e->getMessage());
            $previous = $e->getPrevious();
            self::assertNotNull($previous, 'the PHP error is chained');
            self::assertSame('Failed to write PDF: ' . $previous->getMessage(), $e->getMessage());
            self::assertNull($e->status);
            self::assertNull($e->requestId);
            self::assertFalse($e->isRetryable());
            self::assertSame(
                ['code' => 'io_error', 'message' => $e->getMessage(), 'status' => null, 'requestId' => null],
                $e->toPayload(),
            );

            return $e;
        }
        self::fail('Expected io_error');
    }

    private function answerRenderThenPdf(string $pdf): void
    {
        $this->mock->addResponse($this->factory->createResponse(200)->withBody(
            $this->factory->createStream(DocumentWire::descriptorJson()),
        ));
        $this->mock->addResponse($this->factory->createResponse(200)->withBody($this->factory->createStream($pdf)));
    }

    private function client(int $maxRetries = 0): PoliPage
    {
        return new PoliPage(
            apiKey: 'pp_sandbox_x',
            maxRetries: $maxRetries,
            retryDelay: 0.0,
            httpClient: $this->mock,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    private static function input(): RenderInput
    {
        return new RenderInput(project: 'billing', template: 'invoice', data: ['n' => 1]);
    }

    /**
     * @return list<string>
     */
    private static function entries(string $dir): array
    {
        $entries = array_values(array_diff(self::scan($dir), ['.', '..']));
        sort($entries);

        return $entries;
    }

    /**
     * @return list<string>
     */
    private static function scan(string $dir): array
    {
        $entries = scandir($dir);

        return $entries === false ? [] : $entries;
    }

    private static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff(self::scan($path), ['.', '..']) as $entry) {
                self::removeTree($path . '/' . $entry);
            }
            @rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
    }
}
