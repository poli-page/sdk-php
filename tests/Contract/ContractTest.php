<?php

declare(strict_types=1);

namespace PoliPage\Tests\Contract;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\Constants;

/**
 * Conformance with the Poli Page API contract
 * (https://github.com/poli-page/api-contract).
 *
 * CI copies the pinned `contract.json` with the `poli-page/api-contract`
 * action and sets `POLI_CONTRACT` to its path. Every `kind: "sdk"` case runs
 * against a local HTTP server (its own process) that answers the case's
 * `responses` in order and records each request; the test then compares the
 * recorded requests, the `onRequest` / `onRetry` events and the normalized
 * result with the case's `expect`. Timing is checked as lower bounds,
 * measured on the server. Each case runs through Guzzle and through Symfony
 * HttpClient.
 *
 * `tests/contract-skip.json` maps the case ids allowed to fail to the reason;
 * a key `<id>@guzzle` or `<id>@symfony` lists a case for that client only.
 * A listed case that passes, or a listed id that is not a case this test
 * runs, fails the run.
 *
 * Locally: `POLI_CONTRACT=/path/to/contract.json composer test`. Without it
 * the suite is skipped, except in CI, where a missing contract is an error.
 */
#[CoversNothing]
final class ContractTest extends TestCase
{
    /** The normalization ids this test applies; each must be listed in the contract's policy. */
    private const NORMALIZATIONS_USED = [
        'N-STATUS-NULL', 'N-REQID-NULL', 'N-BYTES', 'N-DELETE-NULL', 'N-THUMBS-ARRAY',
        'N-PREVIEW', 'N-DESCRIPTOR', 'N-DURATION-MS',
    ];

    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /**
     * One row per case, named by its id. When the contract cannot be read,
     * one row whose test reports why.
     *
     * @return iterable<string, array{string}>
     */
    public static function sdkCaseIds(): iterable
    {
        $path = getenv('POLI_CONTRACT');
        try {
            $ids = is_string($path) && $path !== '' ? array_keys(ContractFile::load($path)->sdkCases()) : [];
        } catch (ContractException) {
            $ids = [];
        }
        if ($ids === []) {
            yield 'contract' => [''];
        }
        foreach ($ids as $id) {
            yield $id => [$id];
        }
    }

    public function testContractHasSdkCasesAndKnownKinds(): void
    {
        $contract = $this->contract();
        self::assertNotSame([], $contract->sdkCases(), 'the contract has no kind "sdk" case');
        $unknown = array_values(array_filter($contract->kinds, static fn (string $kind): bool => !in_array($kind, ['sdk', 'impl'], true)));
        self::assertSame([], $unknown, 'case kinds other than "sdk" and "impl"');
    }

    public function testAppliesOnlyTheNormalizationsTheContractAllows(): void
    {
        $allowed = $this->contract()->normalizationIds();
        self::assertSame([], array_values(array_diff(self::NORMALIZATIONS_USED, $allowed)));
    }

    public function testSkipListNamesOnlyCasesThatRun(): void
    {
        $contract = $this->contract();
        $keys = $contract->runningIds();
        foreach ($contract->runningIds() as $id) {
            foreach (CaseInvoker::HTTP_CLIENTS as $httpClient) {
                $keys[] = $id . '@' . $httpClient;
            }
        }
        $unknown = array_values(array_diff(array_keys($contract->skip), $keys));
        self::assertSame([], $unknown, 'not cases this test runs: remove them from tests/contract-skip.json');
    }

    public function testSdkMessagesMatchThePolicy(): void
    {
        $contract = $this->contract();
        $errors = $contract->sdkErrors();
        $messages = [
            'invalid_options' => Constants::MESSAGE_INVALID_OPTIONS,
            'network_error' => Constants::MESSAGE_NETWORK_ERROR,
            'timeout' => Constants::MESSAGE_TIMEOUT,
            'DOWNLOAD_FAILED' => Constants::MESSAGE_DOWNLOAD_FAILED,
            'PROJECT_REQUIRED_FOR_DOCUMENT' => Constants::MESSAGE_PROJECT_REQUIRED,
        ];
        $policy = [];
        foreach (array_keys($messages) as $code) {
            $policy[$code] = $errors[$code]['message'] ?? null;
        }
        self::assertSame($policy, $messages);
        self::assertSame($contract->downloadNetworkMessage(), Constants::MESSAGE_DOWNLOAD_FAILED_NETWORK);

        $sdkCodes = array_keys($errors);
        if (!$contract->runsCancellation()) {
            $sdkCodes = array_diff($sdkCodes, ['aborted']);
        }
        sort($sdkCodes);
        $raised = array_keys($messages);
        sort($raised);
        self::assertSame($sdkCodes, $raised, 'the SDK codes of the policy this SDK raises');
    }

    public function testRetryPolicyMatches(): void
    {
        $contract = $this->contract();
        $codes = $contract->stringList('nonRetryableCodes');
        sort($codes);
        $sdk = Constants::NON_RETRYABLE_CODES;
        sort($sdk);
        self::assertSame($codes, $sdk);
        self::assertSame($contract->policy['retryAfterClampMs'] ?? null, (int) (Constants::RETRY_AFTER_CAP_SECONDS * 1000));
    }

    #[DataProvider('sdkCaseIds')]
    public function testCaseWithGuzzle(string $id): void
    {
        $this->runListedCase($id, 'guzzle');
    }

    #[DataProvider('sdkCaseIds')]
    public function testCaseWithSymfonyHttpClient(string $id): void
    {
        $this->runListedCase($id, 'symfony');
    }

    private function contract(): ContractFile
    {
        $path = getenv('POLI_CONTRACT');
        if (!is_string($path) || $path === '') {
            $ci = getenv('CI');
            if (is_string($ci) && $ci !== '' && $ci !== 'false') {
                self::fail('POLI_CONTRACT is not set: in CI the "API contract" step must provide contract.json '
                    . '(poli-page/api-contract action, output `path`)');
            }
            self::markTestSkipped('API contract: set POLI_CONTRACT to a contract.json path to run it');
        }

        return ContractFile::load($path);
    }

    private function runListedCase(string $id, string $httpClient): void
    {
        $contract = $this->contract();
        $case = $contract->sdkCase($id);
        if (!$contract->runs($case)) {
            self::markTestSkipped(sprintf('policy.cancellationSdks does not list %s', ContractFile::SDK_NAME));
        }
        // `<id>@<client>` lists a case for one HTTP client only.
        $key = isset($contract->skip[$id . '@' . $httpClient]) ? $id . '@' . $httpClient : $id;
        $reason = $contract->skip[$key] ?? null;
        if ($reason === null) {
            $this->runCase($case, $httpClient);

            return;
        }
        try {
            $this->runCase($case, $httpClient);
        } catch (AssertionFailedError) {
            self::markTestIncomplete('listed in tests/contract-skip.json: ' . $reason);
        }
        self::fail(sprintf('%s passes now with %s: remove "%s" from tests/contract-skip.json', $id, $httpClient, $key));
    }

    private function runCase(ContractCase $case, string $httpClient): void
    {
        if ($case->cancelAfterMs !== null) {
            throw new ContractException('The PHP SDK has no cancellation: cancelAfterMs cannot run');
        }
        $case->checkPlaceholders();

        $server = new MockServer($case->responses());
        try {
            $invoker = new CaseInvoker($case, $server->origin);
            $result = $invoker->run($httpClient);
        } finally {
            $recording = $server->stop();
        }
        if ($recording['problems'] !== []) {
            throw new ContractException('mock server: ' . implode('; ', $recording['problems']));
        }

        [$events, $requests, $checkResult] = $case->expectedPrefix();
        $mock = $server->origin;
        self::assertSame(
            self::canonical(Placeholders::substitute($requests, $mock)),
            self::canonical($this->describeRequests($recording['requests'], $requests, $invoker->apiKey())),
            'requests',
        );
        self::assertSame(
            self::canonical($events),
            self::canonical($this->describeEvents($invoker->events, $events)),
            'onRequest / onRetry events',
        );
        if ($checkResult) {
            self::assertSame(
                self::canonical(Placeholders::substitute($case->expectedResult(), $mock)),
                self::canonical($result),
                'result',
            );
        }
    }

    /**
     * The recorded requests in the shape of `expect.requests`. A field that
     * breaks the expectation keeps the observed value, so the diff shows it.
     *
     * @param list<RecordedRequest>      $recorded
     * @param list<array<string, mixed>> $expected
     *
     * @return list<array<string, mixed>>
     */
    private function describeRequests(array $recorded, array $expected, string $apiKey): array
    {
        $firstKey = null;
        $out = [];
        foreach ($recorded as $i => $request) {
            $want = $expected[$i] ?? [];
            $authorization = $request->headers['authorization'] ?? null;
            $auth = match ($authorization) {
                null => 'none',
                'Bearer ' . $apiKey => 'bearer',
                default => 'unexpected Authorization: ' . $authorization,
            };
            $key = $request->headers['idempotency-key'] ?? null;
            if ($key === null) {
                $idempotencyKey = 'absent';
            } elseif (preg_match(self::UUID_V4, $key) !== 1) {
                $idempotencyKey = 'not a lowercase UUID v4: ' . $key;
            } else {
                $firstKey ??= $key;
                $idempotencyKey = $key === $firstKey ? 'stable' : sprintf('changed: %s then %s', $firstKey, $key);
            }
            $item = [
                'method' => $request->method,
                'path' => $request->target,
                'auth' => $auth,
                'idempotencyKey' => $idempotencyKey,
            ];
            if (array_key_exists('json', $want)) {
                try {
                    $item['json'] = json_decode($request->body, false, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $item['json'] = 'body is not JSON: ' . $request->body;
                }
            }
            if (array_key_exists('minGapMsBefore', $want)) {
                // N-DURATION-MS: the measured gap is compared in integer
                // milliseconds, so a timer waking a fraction of a millisecond
                // early is not a short wait.
                $gapMs = $i > 0 ? (int) round(($request->atNs - $recorded[$i - 1]->atNs) / 1_000_000) : 0;
                $minGap = $want['minGapMsBefore'];
                $item['minGapMsBefore'] = is_int($minGap) && $gapMs >= $minGap ? $minGap : sprintf('only %d ms', $gapMs);
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * The observed events, with a delay inside an expected range shown as that range.
     *
     * @param list<array<string, int|string>> $actual
     * @param list<array<array-key, mixed>>   $expected
     *
     * @return list<array<array-key, mixed>>
     */
    private function describeEvents(array $actual, array $expected): array
    {
        $out = [];
        foreach ($actual as $i => $event) {
            $delay = ($expected[$i] ?? [])['delayMs'] ?? null;
            if ($event['type'] === 'retry'
                && ($expected[$i]['type'] ?? null) === 'retry'
                && is_array($delay)
                && is_int($delay['min'] ?? null)
                && is_int($delay['max'] ?? null)
                && $delay['min'] <= $event['delayMs']
                && $event['delayMs'] < $delay['max']
            ) {
                $event['delayMs'] = $delay;
            }
            $out[] = $event;
        }

        return $out;
    }

    /**
     * Pretty JSON with object keys sorted, so `assertSame` compares values,
     * types and `{}` against `[]` exactly, and shows a readable diff.
     */
    private static function canonical(mixed $value): string
    {
        return json_encode(
            self::sortKeys($value),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private static function sortKeys(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $fields = get_object_vars($value);
            ksort($fields);
            $out = new \stdClass();
            foreach ($fields as $name => $field) {
                $out->{$name} = self::sortKeys($field);
            }

            return $out;
        }
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value);
            }

            return array_map(self::sortKeys(...), $value);
        }

        return $value;
    }
}
