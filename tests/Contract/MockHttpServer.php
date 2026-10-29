<?php

declare(strict_types=1);

namespace PoliPage\Tests\Contract;

/**
 * The HTTP server of one contract case, run in its own process by
 * mock-server.php (PHP has no threads, and the SDK call blocks the test's
 * process). It answers the case's `responses` in order on 127.0.0.1 and
 * records every request with the time its first byte arrived
 * (`hrtime()`, monotonic, nanoseconds).
 *
 * A `drop` closes the connection once the request is read; a `hang` keeps it
 * open and never answers. Every answer closes its connection, like the
 * mocks of the other SDKs: this one origin serves both the API and the
 * presigned download, which production keeps on two hosts, so a reused
 * connection would let an HTTP library resend a request the SDK never made.
 * `keepAlive: true` (tests of that resend only) keeps connections open.
 *
 * `GET /__contract-mock__/stop` answers `{ requests, problems }` and ends
 * the process.
 */
final class MockHttpServer
{
    public const STOP_PATH = '/__contract-mock__/stop';

    /** Ends a server whose test never stopped it (a crashed or killed test run). */
    private const MAX_LIFETIME_SECONDS = 300;

    /** @var resource */
    private $listener;

    private readonly string $origin;

    /** @var array<int, MockConnection> */
    private array $connections = [];

    /** @var list<array{method: string, target: string, headers: array<string, string>, body: string, atNs: int}> */
    private array $requests = [];

    /** @var list<string> */
    private array $problems = [];

    /**
     * @param list<\stdClass> $steps
     */
    private function __construct(private readonly array $steps, private readonly bool $keepAlive)
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        if ($listener === false) {
            throw new ContractException('mock server: cannot listen on 127.0.0.1');
        }
        $this->listener = $listener;
        $name = stream_socket_get_name($listener, false);
        if ($name === false) {
            throw new ContractException('mock server: no local address');
        }
        $this->origin = 'http://' . $name;
    }

    /**
     * @param list<string> $argv
     */
    public static function main(array $argv): int
    {
        $config = self::readConfig($argv[1] ?? '');
        $server = new self($config['steps'], $config['keepAlive']);
        fwrite(STDOUT, json_encode(['origin' => $server->origin], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
        fflush(STDOUT);

        return $server->serve();
    }

    /**
     * @return array{steps: list<\stdClass>, keepAlive: bool}
     */
    private static function readConfig(string $path): array
    {
        $text = @file_get_contents($path);
        if ($text === false) {
            throw new ContractException('mock server: cannot read its configuration ' . $path);
        }
        $config = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        if (!$config instanceof \stdClass || !isset($config->steps) || !is_array($config->steps)) {
            throw new ContractException('mock server: the configuration has no steps');
        }
        $steps = [];
        foreach ($config->steps as $step) {
            if (!$step instanceof \stdClass) {
                throw new ContractException('mock server: a step is not an object');
            }
            $steps[] = $step;
        }

        return ['steps' => $steps, 'keepAlive' => ($config->keepAlive ?? false) === true];
    }

    private function serve(): int
    {
        $deadline = self::now() + self::MAX_LIFETIME_SECONDS * 1_000_000_000;
        while (self::now() < $deadline) {
            $read = [$this->listener];
            foreach ($this->connections as $connection) {
                $read[] = $connection->socket;
            }
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 200_000) === false) {
                continue;
            }
            foreach ($read as $socket) {
                if ($socket === $this->listener) {
                    $this->accept();
                    continue;
                }
                $connection = $this->connections[(int) $socket] ?? null;
                if ($connection !== null && $this->receive($connection)) {
                    return 0;
                }
            }
        }

        return 3;
    }

    private function accept(): void
    {
        $socket = @stream_socket_accept($this->listener, 0);
        if ($socket === false) {
            return;
        }
        stream_set_blocking($socket, false);
        $this->connections[(int) $socket] = new MockConnection($socket);
    }

    /**
     * Reads what arrived on one connection and handles each complete request.
     *
     * @return bool true when the stop request was answered
     */
    private function receive(MockConnection $connection): bool
    {
        $chunk = @fread($connection->socket, 65536);
        if ($chunk === false || $chunk === '') {
            if (feof($connection->socket)) {
                $this->close($connection);
            }

            return false;
        }
        if ($connection->buffer === '') {
            $connection->firstByteAtNs = self::now();
        }
        $connection->buffer .= $chunk;

        while (!$connection->hung && isset($this->connections[(int) $connection->socket])) {
            $request = $this->takeRequest($connection);
            if ($request === null) {
                return false;
            }
            if ($request['target'] === self::STOP_PATH) {
                $this->answerStop($connection);

                return true;
            }
            $this->handle($connection, $request);
        }

        return false;
    }

    /**
     * Removes the first complete request from the connection's buffer.
     *
     * @return array{method: string, target: string, headers: array<string, string>, body: string, atNs: int}|null
     */
    private function takeRequest(MockConnection $connection): ?array
    {
        $headerEnd = strpos($connection->buffer, "\r\n\r\n");
        if ($headerEnd === false) {
            return null;
        }
        $lines = explode("\r\n", substr($connection->buffer, 0, $headerEnd));
        $requestLine = explode(' ', array_shift($lines));
        $headers = [];
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
        }

        $rest = substr($connection->buffer, $headerEnd + 4);
        if (strtolower($headers['transfer-encoding'] ?? '') === 'chunked') {
            $decoded = self::decodeChunked($rest);
            if ($decoded === null) {
                $this->continueIfExpected($connection, $headers);

                return null;
            }
            [$body, $remainder] = $decoded;
        } else {
            $length = (int) ($headers['content-length'] ?? '0');
            if (strlen($rest) < $length) {
                $this->continueIfExpected($connection, $headers);

                return null;
            }
            $body = substr($rest, 0, $length);
            $remainder = substr($rest, $length);
        }

        $atNs = $connection->firstByteAtNs ?? self::now();
        $connection->buffer = $remainder;
        $connection->firstByteAtNs = $remainder === '' ? null : self::now();
        $connection->continued = false;

        return [
            'method' => $requestLine[0],
            'target' => $requestLine[1] ?? '',
            'headers' => $headers,
            'body' => $body,
            'atNs' => $atNs,
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    private function continueIfExpected(MockConnection $connection, array $headers): void
    {
        if (!$connection->continued && strtolower($headers['expect'] ?? '') === '100-continue') {
            $connection->continued = true;
            $this->write($connection, "HTTP/1.1 100 Continue\r\n\r\n");
        }
    }

    /**
     * @return array{string, string}|null the body and what follows it, or null while incomplete
     */
    private static function decodeChunked(string $data): ?array
    {
        $body = '';
        $offset = 0;
        while (true) {
            $lineEnd = strpos($data, "\r\n", $offset);
            if ($lineEnd === false) {
                return null;
            }
            $size = (int) hexdec(trim(explode(';', substr($data, $offset, $lineEnd - $offset))[0]));
            $offset = $lineEnd + 2;
            if ($size === 0) {
                $end = strpos($data, "\r\n", $offset);
                if ($end === false) {
                    return null;
                }

                return [$body, substr($data, $end + 2)];
            }
            if (strlen($data) < $offset + $size + 2) {
                return null;
            }
            $body .= substr($data, $offset, $size);
            $offset += $size + 2;
        }
    }

    /**
     * @param array{method: string, target: string, headers: array<string, string>, body: string, atNs: int} $request
     */
    private function handle(MockConnection $connection, array $request): void
    {
        $this->requests[] = $request;
        $index = count($this->requests) - 1;
        $step = $this->steps[$index] ?? null;
        if ($step === null) {
            // More requests than the case has answers: the recorded requests show it.
            $this->answer($connection, $request, 599, ['content-type' => 'application/json'], '{"error":"MOCK_EXHAUSTED"}');

            return;
        }

        $fault = $step->fault ?? null;
        if ($fault === 'drop') {
            stream_socket_shutdown($connection->socket, STREAM_SHUT_RDWR);
            $this->close($connection);

            return;
        }
        if ($fault === 'hang') {
            // Never answered; closed when the client gives up, or at stop.
            $connection->hung = true;

            return;
        }
        if ($fault !== null) {
            $this->problems[] = sprintf('request %d: unknown fault %s', $index + 1, json_encode($fault));
            $this->close($connection);

            return;
        }

        try {
            [$status, $headers, $body] = $this->render($step);
        } catch (\Throwable $e) {
            $this->problems[] = sprintf('request %d: %s', $index + 1, $e->getMessage());
            $this->close($connection);

            return;
        }
        $this->answer($connection, $request, $status, $headers, $body);
    }

    /**
     * @return array{int, array<string, string>, string|null}
     */
    private function render(\stdClass $step): array
    {
        $status = $step->status ?? null;
        if (!is_int($status)) {
            throw new ContractException('a response has no integer status');
        }
        $headers = [];
        $rawHeaders = $step->headers ?? new \stdClass();
        if (!$rawHeaders instanceof \stdClass) {
            throw new ContractException('response headers must be an object');
        }
        foreach (Json::fields($rawHeaders) as $name => $value) {
            if (!is_string($value)) {
                throw new ContractException(sprintf('header %s must be a string', $name));
            }
            $headers[$name] = Placeholders::substituteString($value, $this->origin);
        }
        $hasType = false;
        foreach (array_keys($headers) as $name) {
            $hasType = $hasType || strtolower($name) === 'content-type';
        }

        $body = null;
        if (property_exists($step, 'json')) {
            $body = json_encode(
                Placeholders::substitute($step->json, $this->origin),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            if (!$hasType) {
                $headers['Content-Type'] = 'application/json';
            }
        } elseif (property_exists($step, 'text')) {
            if (!is_string($step->text)) {
                throw new ContractException('text must be a string');
            }
            $body = $step->text;
        } elseif (property_exists($step, 'base64')) {
            $decoded = is_string($step->base64) ? base64_decode($step->base64, true) : false;
            if ($decoded === false) {
                throw new ContractException('base64 is not valid base64');
            }
            $body = $decoded;
        }

        return [$status, $headers, $body];
    }

    /**
     * @param array{method: string, target: string, headers: array<string, string>, body: string, atNs: int} $request
     * @param array<string, string>                                                                           $headers
     */
    private function answer(MockConnection $connection, array $request, int $status, array $headers, ?string $body): void
    {
        $keepAlive = $this->keepAlive && strtolower($request['headers']['connection'] ?? '') !== 'close';
        $head = sprintf("HTTP/1.1 %d %s\r\n", $status, self::reason($status));
        foreach ($headers as $name => $value) {
            $head .= $name . ': ' . $value . "\r\n";
        }
        $head .= 'Content-Length: ' . strlen($body ?? '') . "\r\n";
        if (!$keepAlive) {
            $head .= "Connection: close\r\n";
        }
        $this->write($connection, $head . "\r\n" . ($body ?? ''));
        if (!$keepAlive) {
            $this->close($connection);
        }
    }

    private function answerStop(MockConnection $connection): void
    {
        foreach ($this->connections as $other) {
            if ($other !== $connection) {
                $this->close($other);
            }
        }
        $requests = array_map(
            static fn (array $request): array => [...$request, 'body' => base64_encode($request['body'])],
            $this->requests,
        );
        $body = json_encode(['requests' => $requests, 'problems' => $this->problems], JSON_THROW_ON_ERROR);
        $this->write(
            $connection,
            "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body)
            . "\r\nConnection: close\r\n\r\n" . $body,
        );
        $this->close($connection);
        fclose($this->listener);
    }

    private function write(MockConnection $connection, string $data): void
    {
        stream_set_blocking($connection->socket, true);
        while ($data !== '') {
            $written = @fwrite($connection->socket, $data);
            if ($written === false || $written === 0) {
                // The client is gone; what it missed shows in its error.
                break;
            }
            $data = substr($data, $written);
        }
        stream_set_blocking($connection->socket, false);
    }

    private function close(MockConnection $connection): void
    {
        unset($this->connections[(int) $connection->socket]);
        if (is_resource($connection->socket)) {
            fclose($connection->socket);
        }
    }

    /**
     * The monotonic clock in nanoseconds (`hrtime()` is a float only on 32-bit builds).
     */
    private static function now(): int
    {
        $now = hrtime(true);

        return is_int($now) ? $now : (int) $now;
    }

    private static function reason(int $status): string
    {
        return match ($status) {
            200 => 'OK',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            410 => 'Gone',
            413 => 'Payload Too Large',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => 'Status',
        };
    }
}
