<?php

declare(strict_types=1);

// The server of TrickleServer, in its own process. Usage:
// php trickle-server.php <responses.json>
// Prints its origin, then answers one connection per response, in order.

/** @var list<string> $argv */
$responses = json_decode((string) file_get_contents($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($responses)) {
    fwrite(STDERR, "trickle server: no responses\n");
    exit(2);
}

$listener = stream_socket_server('tcp://127.0.0.1:0');
$name = $listener === false ? false : stream_socket_get_name($listener, false);
if ($listener === false || $name === false) {
    fwrite(STDERR, "trickle server: cannot listen\n");
    exit(2);
}
$origin = 'http://' . $name;
fwrite(STDOUT, $origin . "\n");
fflush(STDOUT);

// Ends a server whose test never stopped it.
$deadline = microtime(true) + 60;

foreach ($responses as $pieces) {
    $connection = @stream_socket_accept($listener, max(0.0, $deadline - microtime(true)));
    if ($connection === false) {
        exit(3);
    }
    stream_set_timeout($connection, 5);
    // Read the whole request, its body included: closing a socket with
    // unread data resets the connection, which could reach the client before
    // the answer.
    $received = '';
    $headEnd = false;
    while (($headEnd = strpos($received, "\r\n\r\n")) === false && !feof($connection)) {
        $chunk = fread($connection, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $received .= $chunk;
    }
    if ($headEnd !== false && preg_match('/\r\ncontent-length:\s*(\d+)/i', substr($received, 0, $headEnd), $m) === 1) {
        $missing = (int) $m[1] - (strlen($received) - $headEnd - 4);
        while ($missing > 0 && !feof($connection)) {
            $chunk = fread($connection, $missing);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $missing -= strlen($chunk);
        }
    }

    foreach (is_array($pieces) ? $pieces : [] as $piece) {
        if (!is_array($piece) || !is_int($piece[0] ?? null) || !is_string($piece[1] ?? null)) {
            continue;
        }
        usleep($piece[0] * 1000);
        // A client that gave up has closed the connection: ignore the error.
        @fwrite($connection, str_replace('{{origin}}', $origin, $piece[1]));
        @fflush($connection);
    }
    @fclose($connection);
}
