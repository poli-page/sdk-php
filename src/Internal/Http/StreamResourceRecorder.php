<?php

declare(strict_types=1);

namespace PoliPage\Internal\Http;

use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A PSR-17 stream factory that keeps the first PHP stream resource it wraps.
 * Given to Guzzle 8 as its `stream_factory` request option for a streamed
 * download, it gets the socket stream under the response body, which
 * {@see FirstByteDeadline} needs. Every call goes to the factory it wraps.
 *
 * @internal
 */
final class StreamResourceRecorder implements StreamFactoryInterface
{
    /** @var resource|null */
    private $resource;

    private ?StreamFactoryInterface $factory = null;

    /**
     * Wraps `$factory`; returns this recorder.
     */
    public function around(StreamFactoryInterface $factory): self
    {
        $this->factory = $factory;

        return $this;
    }

    /**
     * @return resource|null
     */
    public function resource()
    {
        return $this->resource;
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return $this->factory()->createStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return $this->factory()->createStreamFromFile($filename, $mode);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        $this->resource ??= $resource;

        return $this->factory()->createStreamFromResource($resource);
    }

    private function factory(): StreamFactoryInterface
    {
        return $this->factory ?? throw new \LogicException('The recorder wraps no stream factory');
    }
}
