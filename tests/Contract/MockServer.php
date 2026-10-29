<?php

declare(strict_types=1);

namespace PoliPage\Tests\Contract;

/**
 * The test's handle on a {@see MockHttpServer} process: starts it with the
 * case's steps, gives its origin, and stops it to read what it recorded.
 */
final class MockServer
{
    /** @var resource */
    private $process;

    /** @var resource */
    private $stdout;

    private readonly string $configPath;

    private readonly string $stderrPath;

    public readonly string $origin;

    /**
     * @param list<mixed> $steps the case's `responses`, decoded as objects
     */
    public function __construct(array $steps, bool $keepAlive = false)
    {
        $this->configPath = self::tempFile('config');
        $this->stderrPath = self::tempFile('stderr');
        file_put_contents(
            $this->configPath,
            json_encode(['steps' => $steps, 'keepAlive' => $keepAlive], JSON_THROW_ON_ERROR),
        );
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/mock-server.php', $this->configPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $this->stderrPath, 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new ContractException('Cannot start the mock server process');
        }
        $this->process = $process;
        fclose(self::pipe($pipes, 0));
        $this->stdout = self::pipe($pipes, 1);

        $line = fgets($this->stdout);
        $ready = is_string($line) ? json_decode($line, true) : null;
        if (!is_array($ready) || !is_string($ready['origin'] ?? null)) {
            $this->kill();
            throw new ContractException('The mock server did not start: ' . $this->stderr());
        }
        $this->origin = $ready['origin'];
    }

    /**
     * Stops the server, which closes every connection it still holds (each
     * `hang`), and returns what it recorded.
     *
     * @return array{requests: list<RecordedRequest>, problems: list<string>}
     */
    public function stop(): array
    {
        try {
            $answer = $this->requestStop();
        } finally {
            $this->kill();
        }

        $requests = [];
        foreach (self::listField($answer, 'requests') as $raw) {
            $requests[] = RecordedRequest::fromServer($raw);
        }
        $problems = [];
        foreach (self::listField($answer, 'problems') as $problem) {
            $problems[] = is_string($problem) ? $problem : json_encode($problem, JSON_THROW_ON_ERROR);
        }

        return ['requests' => $requests, 'problems' => $problems];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function requestStop(): array
    {
        $address = 'tcp://' . substr($this->origin, strlen('http://'));
        $socket = @stream_socket_client($address, timeout: 5.0);
        if ($socket === false) {
            throw new ContractException('Cannot reach the mock server to stop it: ' . $this->stderr());
        }
        stream_set_timeout($socket, 10);
        fwrite($socket, 'GET ' . MockHttpServer::STOP_PATH . " HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $raw = stream_get_contents($socket);
        fclose($socket);
        $bodyStart = is_string($raw) ? strpos($raw, "\r\n\r\n") : false;
        $answer = is_string($raw) && $bodyStart !== false
            ? json_decode(substr($raw, $bodyStart + 4), true)
            : null;
        if (!is_array($answer)) {
            throw new ContractException('The mock server gave no recording: ' . $this->stderr());
        }

        return $answer;
    }

    /**
     * @param array<array-key, mixed> $answer
     *
     * @return list<mixed>
     */
    private static function listField(array $answer, string $name): array
    {
        $value = $answer[$name] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new ContractException(sprintf('The mock server recording has no %s list', $name));
        }

        return $value;
    }

    /**
     * @return resource
     */
    private static function pipe(mixed $pipes, int $index)
    {
        $pipe = is_array($pipes) ? ($pipes[$index] ?? null) : null;
        if (!is_resource($pipe)) {
            throw new ContractException('The mock server process has no pipe ' . $index);
        }

        return $pipe;
    }

    private function kill(): void
    {
        if (is_resource($this->stdout)) {
            fclose($this->stdout);
        }
        $status = proc_get_status($this->process);
        if ($status['running']) {
            proc_terminate($this->process);
        }
        proc_close($this->process);
        @unlink($this->configPath);
    }

    private function stderr(): string
    {
        $text = @file_get_contents($this->stderrPath);
        @unlink($this->stderrPath);

        return $text === false || $text === '' ? '(no output)' : trim($text);
    }

    private static function tempFile(string $kind): string
    {
        $path = tempnam(sys_get_temp_dir(), 'poli-contract-' . $kind . '-');
        if ($path === false) {
            throw new ContractException('Cannot create a temporary file');
        }

        return $path;
    }

    public function __destruct()
    {
        @unlink($this->stderrPath);
    }
}
