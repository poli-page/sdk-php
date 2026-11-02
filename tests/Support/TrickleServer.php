<?php

declare(strict_types=1);

namespace PoliPage\Tests\Support;

/**
 * A local HTTP server, in its own process (trickle-server.php), that answers
 * its connections in order with scripted responses sent piece by piece: each
 * piece after its own delay. It covers what the contract's mock cannot: a
 * body that arrives slowly, or stalls partway.
 *
 * `{{origin}}` in a piece is replaced by the server's origin, so a render
 * answer can point its `presignedPdfUrl` at the same server.
 */
final class TrickleServer
{
    /** @var resource */
    private $process;

    private readonly string $configPath;

    public readonly string $origin;

    /**
     * @param list<list<array{int, string}>> $responses per connection, its pieces as
     *                                                  [delay in ms, bytes]; the first piece
     *                                                  holds the status line and headers
     */
    public function __construct(array $responses)
    {
        $path = tempnam(sys_get_temp_dir(), 'poli-trickle-');
        if ($path === false) {
            throw new \RuntimeException('Cannot create a temporary file');
        }
        $this->configPath = $path;
        file_put_contents($path, json_encode($responses, JSON_THROW_ON_ERROR));
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/trickle-server.php', $path],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR],
            $pipes,
        );
        $stdout = self::stdout($pipes);
        if (!is_resource($process) || $stdout === null) {
            throw new \RuntimeException('Cannot start the trickle server');
        }
        $this->process = $process;
        $line = fgets($stdout);
        if (!is_string($line) || !str_starts_with($line, 'http://')) {
            $this->stop();
            throw new \RuntimeException('The trickle server did not start');
        }
        $this->origin = trim($line);
    }

    /**
     * A 200 answer with a JSON body, sent at once. No `content-length`: the
     * body ends with the connection, so `{{origin}}` may change its length.
     *
     * @return list<array{int, string}>
     */
    public static function json(string $json): array
    {
        return [[0, "HTTP/1.1 200 OK\r\ncontent-type: application/json\r\nconnection: close\r\n\r\n" . $json]];
    }

    /**
     * A 200 PDF answer: its headers after `$headersDelayMs`, then each
     * `[delay in ms, bytes]` chunk of the body.
     *
     * @param list<array{int, string}> $chunks
     *
     * @return list<array{int, string}>
     */
    public static function pdf(int $headersDelayMs, array $chunks): array
    {
        $length = array_sum(array_map(static fn (array $chunk): int => strlen($chunk[1]), $chunks));

        return [
            [$headersDelayMs, "HTTP/1.1 200 OK\r\ncontent-type: application/pdf\r\ncontent-length: {$length}\r\nconnection: close\r\n\r\n"],
            ...$chunks,
        ];
    }

    /**
     * @return resource|null
     */
    private static function stdout(mixed $pipes)
    {
        $pipe = is_array($pipes) ? ($pipes[1] ?? null) : null;

        return is_resource($pipe) ? $pipe : null;
    }

    /**
     * A 200 PDF answer that announces `$announcedLength` bytes, sends
     * `$body` (fewer), then closes the connection.
     *
     * @return list<array{int, string}>
     */
    public static function truncatedPdf(int $announcedLength, string $body): array
    {
        return [[0, "HTTP/1.1 200 OK\r\ncontent-type: application/pdf\r\ncontent-length: {$announcedLength}"
            . "\r\nconnection: close\r\n\r\n" . $body]];
    }

    /**
     * An origin where nothing listens: a connection to it is refused.
     */
    public static function refusedOrigin(): string
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $name = $listener === false ? false : stream_socket_get_name($listener, false);
        if ($listener === false || $name === false) {
            throw new \RuntimeException('Cannot reserve a local port');
        }
        fclose($listener);

        return 'http://' . $name;
    }

    public function stop(): void
    {
        $status = proc_get_status($this->process);
        if ($status['running']) {
            proc_terminate($this->process);
        }
        proc_close($this->process);
        @unlink($this->configPath);
    }
}
