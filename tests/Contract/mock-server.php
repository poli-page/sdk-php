<?php

declare(strict_types=1);

// The mock HTTP server of one contract case, started by MockServer in its own
// process. Usage: php mock-server.php <configuration.json>

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var list<string> $argv */
exit(PoliPage\Tests\Contract\MockHttpServer::main($argv));
