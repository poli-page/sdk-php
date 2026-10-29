<?php

declare(strict_types=1);

namespace PoliPage\Tests\Contract;

/**
 * One client connection of {@see MockHttpServer}.
 */
final class MockConnection
{
    public string $buffer = '';

    /** hrtime() of the first byte of the request being read, or null between requests. */
    public ?int $firstByteAtNs = null;

    /** The request in progress got its `100 Continue`. */
    public bool $continued = false;

    /** A `hang` step: the connection gets no answer. */
    public bool $hung = false;

    /**
     * @param resource $socket
     */
    public function __construct(public $socket)
    {
    }
}
