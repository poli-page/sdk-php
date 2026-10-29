<?php

declare(strict_types=1);

namespace PoliPage\Tests\Contract;

/**
 * `contract.json` (format 1, README of poli-page/api-contract) and the skip
 * list, read once per process.
 */
final class ContractFile
{
    public const KNOWN_FORMAT_VERSION = 1;

    /** This SDK's name in `policy.cancellationSdks`. */
    public const SDK_NAME = 'php';

    public const SKIP_LIST = __DIR__ . '/../contract-skip.json';

    /** @var array<string, self> */
    private static array $loaded = [];

    /**
     * @param array<array-key, mixed>      $policy
     * @param list<string>                 $kinds  the `kind` of every case, in order
     * @param array<string, ContractCase>  $sdkCases
     * @param array<string, string>        $skip   case id to reason
     */
    private function __construct(
        public readonly array $policy,
        public readonly array $kinds,
        private readonly array $sdkCases,
        public readonly array $skip,
    ) {
    }

    public static function load(string $path): self
    {
        return self::$loaded[$path] ??= self::read($path);
    }

    private static function read(string $path): self
    {
        $text = @file_get_contents($path);
        if ($text === false) {
            throw new ContractException(sprintf('POLI_CONTRACT=%s cannot be read', $path));
        }
        try {
            $assoc = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            // The same file decoded with objects, so a request body `{}` stays
            // distinct from `[]` when the test compares what went on the wire.
            $objects = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ContractException(sprintf('POLI_CONTRACT=%s is not JSON: %s', $path, $e->getMessage()), 0, $e);
        }
        if (!is_array($assoc) || !array_key_exists('formatVersion', $assoc) || !$objects instanceof \stdClass) {
            throw new ContractException($path . ' is not a contract.json: it has no formatVersion');
        }
        if ($assoc['formatVersion'] !== self::KNOWN_FORMAT_VERSION) {
            throw new ContractException(sprintf(
                'contract.json formatVersion %s is not supported (this test reads format %d): update tests/Contract',
                json_encode($assoc['formatVersion']),
                self::KNOWN_FORMAT_VERSION,
            ));
        }
        $policy = $assoc['policy'] ?? null;
        $cases = $assoc['cases'] ?? null;
        $caseObjects = $objects->cases ?? null;
        if (!is_array($policy) || !is_array($cases) || !array_is_list($cases) || !is_array($caseObjects)) {
            throw new ContractException('contract.json has no policy or no cases list');
        }

        $kinds = [];
        $sdkCases = [];
        foreach ($cases as $index => $case) {
            $object = $caseObjects[$index] ?? null;
            if (!is_array($case) || !$object instanceof \stdClass) {
                throw new ContractException(sprintf('cases[%d] is not an object', $index));
            }
            $kind = $case['kind'] ?? null;
            $kinds[] = is_string($kind) ? $kind : json_encode($kind, JSON_THROW_ON_ERROR);
            if ($kind !== 'sdk') {
                continue;
            }
            $sdkCase = new ContractCase($case, $object);
            if (isset($sdkCases[$sdkCase->id])) {
                throw new ContractException('Duplicate case id ' . $sdkCase->id);
            }
            $sdkCases[$sdkCase->id] = $sdkCase;
        }

        return new self($policy, $kinds, $sdkCases, self::readSkipList());
    }

    /**
     * @return array<string, string>
     */
    private static function readSkipList(): array
    {
        $text = @file_get_contents(self::SKIP_LIST);
        $parsed = $text === false ? null : json_decode($text, true);
        if (!is_array($parsed)) {
            throw new ContractException('tests/contract-skip.json must be an object of case id to reason');
        }
        $skip = [];
        foreach ($parsed as $id => $reason) {
            if (!is_string($reason) || trim($reason) === '') {
                throw new ContractException(sprintf('tests/contract-skip.json: "%s" needs a reason', $id));
            }
            $skip[(string) $id] = $reason;
        }

        return $skip;
    }

    /**
     * @return array<string, ContractCase>
     */
    public function sdkCases(): array
    {
        return $this->sdkCases;
    }

    public function sdkCase(string $id): ContractCase
    {
        return $this->sdkCases[$id] ?? throw new ContractException('the contract has no kind "sdk" case ' . json_encode($id));
    }

    public function runsCancellation(): bool
    {
        return in_array(self::SDK_NAME, $this->stringList('cancellationSdks'), true);
    }

    /**
     * Whether this SDK runs the case: `requires: "cancellation"` only in the
     * SDKs of `policy.cancellationSdks`.
     */
    public function runs(ContractCase $case): bool
    {
        return $case->requires !== 'cancellation' || $this->runsCancellation();
    }

    /**
     * @return list<string>
     */
    public function runningIds(): array
    {
        return array_values(array_map(
            static fn (ContractCase $case): string => $case->id,
            array_filter($this->sdkCases, fn (ContractCase $case): bool => $this->runs($case)),
        ));
    }

    /**
     * @return list<string>
     */
    public function stringList(string $policyField): array
    {
        $value = $this->policy[$policyField] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new ContractException(sprintf('policy.%s is not a list', $policyField));
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new ContractException(sprintf('policy.%s holds a non-string', $policyField));
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function normalizationIds(): array
    {
        $value = $this->policy['normalizations'] ?? null;
        if (!is_array($value)) {
            throw new ContractException('policy.normalizations is not a list');
        }
        $ids = [];
        foreach ($value as $normalization) {
            if (!is_array($normalization) || !is_string($normalization['id'] ?? null)) {
                throw new ContractException('policy.normalizations holds an entry without id');
            }
            $ids[] = $normalization['id'];
        }

        return $ids;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function sdkErrors(): array
    {
        $value = $this->policy['sdkErrors'] ?? null;
        if (!is_array($value)) {
            throw new ContractException('policy.sdkErrors is not an object');
        }
        $out = [];
        foreach ($value as $code => $entry) {
            if (!is_array($entry)) {
                throw new ContractException(sprintf('policy.sdkErrors.%s is not an object', $code));
            }
            $fields = [];
            foreach ($entry as $name => $field) {
                $fields[(string) $name] = $field;
            }
            $out[(string) $code] = $fields;
        }

        return $out;
    }

    public function downloadNetworkMessage(): string
    {
        $message = $this->sdkErrors()['DOWNLOAD_FAILED']['networkMessage'] ?? null;
        if (!is_string($message)) {
            throw new ContractException('policy.sdkErrors.DOWNLOAD_FAILED has no networkMessage');
        }

        return $message;
    }
}
