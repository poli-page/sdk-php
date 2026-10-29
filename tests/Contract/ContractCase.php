<?php

declare(strict_types=1);

namespace PoliPage\Tests\Contract;

/**
 * One `kind: "sdk"` case. `$case` is the case decoded as arrays, `$object`
 * the same case decoded as objects (for the answers and the request bodies,
 * where `{}` and `[]` differ on the wire).
 */
final readonly class ContractCase
{
    public string $id;

    public ?string $requires;

    public string $method;

    /** @var list<mixed> */
    public array $args;

    public ?int $cancelAfterMs;

    public ?string $apiKey;

    public int $maxRetries;

    public int $retryDelayMs;

    public int $timeoutMs;

    public ?int $stopAfterRetryEvents;

    /**
     * @param array<array-key, mixed> $case
     */
    public function __construct(private array $case, private \stdClass $object)
    {
        $id = $case['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new ContractException('A kind "sdk" case has no id');
        }
        $this->id = $id;

        $requires = $case['requires'] ?? null;
        if ($requires !== null && $requires !== 'cancellation') {
            throw new ContractException(sprintf('%s: unknown requirement %s', $id, json_encode($requires)));
        }
        $this->requires = $requires;

        $call = $this->field($case, 'call');
        $method = $call['method'] ?? null;
        $args = $call['args'] ?? null;
        if (!is_string($method) || !is_array($args) || !array_is_list($args)) {
            throw new ContractException($id . ': call needs a method and an args list');
        }
        $this->method = $method;
        $this->args = $args;
        $this->cancelAfterMs = self::optionalInt($call, 'cancelAfterMs', $id);

        $apiKey = $case['apiKey'] ?? null;
        if ($apiKey !== null && !is_string($apiKey)) {
            throw new ContractException($id . ': apiKey must be a string');
        }
        $this->apiKey = $apiKey;

        $options = $this->field($case, 'options');
        $this->maxRetries = self::int($options, 'maxRetries', $id);
        $this->retryDelayMs = self::int($options, 'retryDelayMs', $id);
        $this->timeoutMs = self::int($options, 'timeoutMs', $id);
        $this->stopAfterRetryEvents = self::optionalInt($case, 'stopAfterRetryEvents', $id);
    }

    /**
     * The mock server's answers, decoded as objects.
     *
     * @return list<mixed>
     */
    public function responses(): array
    {
        $responses = $this->object->responses ?? null;
        if (!is_array($responses) || !array_is_list($responses)) {
            throw new ContractException($this->id . ': responses must be a list');
        }

        return $responses;
    }

    /**
     * `expect.requests`, each an array whose `json` keeps the objects of the
     * case (`{}` is a `\stdClass`).
     *
     * @return list<array<string, mixed>>
     */
    public function expectedRequests(): array
    {
        $expect = $this->object->expect ?? null;
        $requests = $expect instanceof \stdClass ? ($expect->requests ?? null) : null;
        if (!is_array($requests)) {
            throw new ContractException($this->id . ': expect.requests must be a list');
        }
        $out = [];
        foreach ($requests as $request) {
            if (!$request instanceof \stdClass) {
                throw new ContractException($this->id . ': an expected request is not an object');
            }
            $out[] = Json::fields($request);
        }

        return $out;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function expectedEvents(): array
    {
        $events = $this->field($this->field($this->case, 'expect'), 'events');
        $out = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                throw new ContractException($this->id . ': an expected event is not an object');
            }
            $out[] = $event;
        }

        return $out;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function expectedResult(): array
    {
        return $this->field($this->field($this->case, 'expect'), 'result');
    }

    /**
     * The expected events and requests, cut at the `stopAfterRetryEvents`-th
     * retry event, and whether the result is checked.
     *
     * @return array{list<array<array-key, mixed>>, list<array<string, mixed>>, bool}
     */
    public function expectedPrefix(): array
    {
        $events = $this->expectedEvents();
        $requests = $this->expectedRequests();
        if ($this->stopAfterRetryEvents === null) {
            return [$events, $requests, true];
        }
        $retries = 0;
        foreach ($events as $end => $event) {
            if (($event['type'] ?? null) !== 'retry' || ++$retries !== $this->stopAfterRetryEvents) {
                continue;
            }
            $cut = array_slice($events, 0, $end + 1);
            $attempts = count(array_filter($cut, static fn (array $e): bool => ($e['type'] ?? null) === 'request'));

            return [$cut, array_slice($requests, 0, $attempts), false];
        }

        throw new ContractException(sprintf('%s: stopAfterRetryEvents %d but the case has fewer retries', $this->id, $this->stopAfterRetryEvents));
    }

    /**
     * Replaces every placeholder with a stand-in origin: an unknown
     * placeholder fails before any request.
     */
    public function checkPlaceholders(): void
    {
        Placeholders::substitute($this->object, 'http://127.0.0.1');
    }

    /**
     * @param array<array-key, mixed> $parent
     *
     * @return array<array-key, mixed>
     */
    private function field(array $parent, string $name): array
    {
        $value = $parent[$name] ?? null;
        if (!is_array($value)) {
            throw new ContractException(sprintf('%s: %s must be an object or a list', $this->id, $name));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $parent
     */
    private static function int(array $parent, string $name, string $id): int
    {
        return self::optionalInt($parent, $name, $id) ?? throw new ContractException(sprintf('%s: %s is required', $id, $name));
    }

    /**
     * @param array<array-key, mixed> $parent
     */
    private static function optionalInt(array $parent, string $name, string $id): ?int
    {
        $value = $parent[$name] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new ContractException(sprintf('%s: %s must be an integer', $id, $name));
        }

        return $value;
    }
}
