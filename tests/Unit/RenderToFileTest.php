<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit;

use Http\Message\RequestMatcher\CallbackRequestMatcher;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\AtomicFileWriter;
use PoliPage\PoliPage;
use PoliPage\PoliPageException;
use PoliPage\RenderInput;

use function PoliPage\renderToFile;

use PoliPage\Tests\Support\DocumentWire;
use PoliPage\Tests\Support\FailingStream;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * `renderToFile()` writes through a temporary file renamed over the target:
 * a file that cannot be written is `io_error` (no status, the message
 * `Failed to write PDF: <path>: <reason>`, PHP's error as `getPrevious()`,
 * never retried), and any failure leaves the target untouched. A target
 * that is not a regular file or not writable is refused before any request;
 * a symbolic link stays a link, and an existing file keeps its mode.
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
        // The target becomes a non-empty directory during the download: the
        // PDF cannot replace it.
        $path = $this->dir . '/invoice.pdf';
        file_put_contents($path, 'old content');
        $this->mock->addResponse($this->factory->createResponse(200)->withBody(
            $this->factory->createStream(DocumentWire::descriptorJson()),
        ));
        $this->mock->on(
            new CallbackRequestMatcher(static fn (RequestInterface $request): bool => $request->getMethod() === 'GET'),
            function () use ($path): ResponseInterface {
                unlink($path);
                mkdir($path);
                file_put_contents($path . '/keep', 'x');

                return $this->factory->createResponse(200)->withBody($this->factory->createStream('%PDF-1.7'));
            },
        );

        $error = $this->ioError($path);

        self::assertCount(2, $this->mock->getRequests());
        self::assertInstanceOf(\ErrorException::class, $error->getPrevious());
        self::assertSame(['keep'], self::entries($path), 'the target is left untouched');
        self::assertSame(['invoice.pdf'], self::entries($this->dir), 'no temporary file left');
    }

    public function testDirectoryTargetIsNotARegularFileBeforeAnyRequest(): void
    {
        $path = $this->dir . '/invoice.pdf';
        mkdir($path);

        $error = $this->ioError($path);

        self::assertSame('Failed to write PDF: ' . $path . ': not a regular file', $error->getMessage());
        self::assertCount(0, $this->mock->getRequests(), 'no render for a target that is not a regular file');
        self::assertTrue(is_dir($path));
        self::assertSame(['invoice.pdf'], self::entries($this->dir));
    }

    public function testFifoTargetIsNotARegularFileBeforeAnyRequest(): void
    {
        if (!\function_exists('posix_mkfifo')) {
            self::markTestSkipped('needs posix_mkfifo()');
        }
        $path = $this->dir . '/invoice.pdf';
        if (!posix_mkfifo($path, 0o600)) {
            self::markTestSkipped('cannot create a FIFO here');
        }

        // Judged by its attributes: opening the FIFO would block without a writer.
        $error = $this->ioError($path);

        self::assertSame('Failed to write PDF: ' . $path . ': not a regular file', $error->getMessage());
        self::assertCount(0, $this->mock->getRequests(), 'no render for a target that is not a regular file');
        self::assertSame('fifo', filetype($path), 'the FIFO is left as it was');
        self::assertSame(['invoice.pdf'], self::entries($this->dir));
    }

    public function testReadOnlyTargetIsAnIoErrorBeforeAnyRequestAndLeftUntouched(): void
    {
        self::skipAsRoot();
        $path = $this->dir . '/invoice.pdf';
        file_put_contents($path, 'old content');
        chmod($path, 0o444);

        $error = $this->ioError($path);

        self::assertSame('Failed to write PDF: ' . $path . ': Permission denied', $error->getMessage());
        self::assertCount(0, $this->mock->getRequests(), 'no render for a target that cannot be written');
        self::assertSame('old content', file_get_contents($path));
        self::assertSame(['invoice.pdf'], self::entries($this->dir));
    }

    public function testExistingTargetKeepsItsPermissionBits(): void
    {
        self::skipOnWindows('POSIX permission bits');
        $path = $this->dir . '/invoice.pdf';
        file_put_contents($path, 'old content');
        chmod($path, 0o640);
        $this->answerRenderThenPdf('%PDF-1.7 new');

        renderToFile($this->client(), self::input(), $path);

        clearstatcache();
        self::assertSame('%PDF-1.7 new', file_get_contents($path));
        self::assertSame(0o640, fileperms($path) & 0o7777);
    }

    public function testNewTargetGetsTheUmaskDefaultMode(): void
    {
        self::skipOnWindows('POSIX permission bits');
        $path = $this->dir . '/invoice.pdf';
        $this->answerRenderThenPdf('%PDF-1.7');
        $umask = umask(0o027);
        try {
            renderToFile($this->client(), self::input(), $path);
        } finally {
            umask($umask);
        }

        self::assertSame(0o640, fileperms($path) & 0o7777);
    }

    public function testSymlinkedTargetWritesTheFileItPointsToAndStaysALink(): void
    {
        self::skipOnWindows('symbolic links');
        $target = $this->dir . '/real.pdf';
        file_put_contents($target, 'old content');
        $link = $this->dir . '/invoice.pdf';
        symlink('real.pdf', $link);
        $this->answerRenderThenPdf('%PDF-1.7 new');

        renderToFile($this->client(), self::input(), $link);

        self::assertTrue(is_link($link), 'the link stays a link');
        self::assertSame('real.pdf', readlink($link));
        self::assertSame('%PDF-1.7 new', file_get_contents($target));
        self::assertSame(['invoice.pdf', 'real.pdf'], self::entries($this->dir));
    }

    /**
     * `sym -> real/dir` and `real/dir/link -> ../a.pdf`: writing `sym/link`
     * updates `real/a.pdf`, the `..` read against the link's real directory.
     */
    #[DataProvider('existingOrNot')]
    public function testRelativeLinkIsResolvedAgainstTheLinksRealDirectory(bool $exists): void
    {
        self::skipOnWindows('symbolic links');
        mkdir($this->dir . '/real/dir', 0o755, true);
        symlink('real/dir', $this->dir . '/sym');
        symlink('../a.pdf', $this->dir . '/real/dir/link');
        if ($exists) {
            file_put_contents($this->dir . '/real/a.pdf', 'old content');
        }
        $this->answerRenderThenPdf('%PDF-1.7 new');

        renderToFile($this->client(), self::input(), $this->dir . '/sym/link');

        self::assertSame('%PDF-1.7 new', file_get_contents($this->dir . '/real/a.pdf'));
        self::assertTrue(is_link($this->dir . '/real/dir/link'), 'the link stays a link');
        self::assertSame(['a.pdf', 'dir'], self::entries($this->dir . '/real'));
        self::assertSame(['real', 'sym'], self::entries($this->dir), 'nothing written next to the link');
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function existingOrNot(): iterable
    {
        yield 'existing target' => [true];
        yield 'missing target' => [false];
    }

    public function testSymlinkLoopIsAnIoErrorBeforeAnyRequest(): void
    {
        self::skipOnWindows('symbolic links');
        symlink('b.pdf', $this->dir . '/a.pdf');
        symlink('a.pdf', $this->dir . '/b.pdf');

        $error = $this->ioError($this->dir . '/a.pdf');

        self::assertSame('Failed to write PDF: ' . $this->dir . '/a.pdf: Too many levels of symbolic links', $error->getMessage());
        self::assertCount(0, $this->mock->getRequests());
        self::assertSame(['a.pdf', 'b.pdf'], self::entries($this->dir));
    }

    public function testUnwritableDirectoryIsAnIoErrorAndLeavesTheTargetUntouched(): void
    {
        self::skipOnWindows('POSIX directory permissions');
        self::skipAsRoot();
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
            // The path as passed, never the temporary file.
            self::assertStringStartsWith('Failed to write PDF: ' . $path . ': ', $e->getMessage());
            self::assertStringNotContainsString('.part', $e->getMessage());
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

    private static function skipOnWindows(string $needs): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('needs ' . $needs);
        }
    }

    private static function skipAsRoot(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can write any file');
        }
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
            @chmod($path, 0o644);
            @unlink($path);
        }
    }
}
