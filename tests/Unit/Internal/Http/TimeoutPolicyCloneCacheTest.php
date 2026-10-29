<?php

declare(strict_types=1);

namespace PoliPage\Tests\Unit\Internal\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PoliPage\Internal\Http\TimeoutPolicy;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * The Symfony `withOptions()` clones are kept per client and option set,
 * at most MAX_SYMFONY_CLONES per client, least recently used evicted first.
 */
#[CoversClass(TimeoutPolicy::class)]
final class TimeoutPolicyCloneCacheTest extends TestCase
{
    public function testTheSameOptionsGiveTheSameClone(): void
    {
        $client = new Psr18Client();

        self::assertSame(TimeoutPolicy::symfonyClient($client, self::options(1)), TimeoutPolicy::symfonyClient($client, self::options(1)));
        self::assertNotSame(TimeoutPolicy::symfonyClient($client, self::options(1)), TimeoutPolicy::symfonyClient($client, self::options(2)));
        self::assertSame(2, TimeoutPolicy::symfonyCloneCount($client));
    }

    public function testMoreDistinctTimeoutsThanTheCapKeepTheMapAtTheCap(): void
    {
        $client = new Psr18Client();
        for ($i = 1; $i <= TimeoutPolicy::MAX_SYMFONY_CLONES + 10; ++$i) {
            TimeoutPolicy::symfonyClient($client, self::options($i));
        }

        self::assertSame(TimeoutPolicy::MAX_SYMFONY_CLONES, TimeoutPolicy::symfonyCloneCount($client));
    }

    public function testTheLeastRecentlyUsedCloneIsEvictedFirst(): void
    {
        $client = new Psr18Client();
        $clones = [];
        for ($i = 1; $i <= TimeoutPolicy::MAX_SYMFONY_CLONES; ++$i) {
            $clones[$i] = TimeoutPolicy::symfonyClient($client, self::options($i));
        }
        // Using the oldest makes it the most recently used: the next insert evicts 2.
        TimeoutPolicy::symfonyClient($client, self::options(1));
        TimeoutPolicy::symfonyClient($client, self::options(TimeoutPolicy::MAX_SYMFONY_CLONES + 1));

        self::assertSame(TimeoutPolicy::MAX_SYMFONY_CLONES, TimeoutPolicy::symfonyCloneCount($client));
        self::assertSame($clones[1], TimeoutPolicy::symfonyClient($client, self::options(1)), 'the recently used clone survives');
        self::assertSame($clones[3], TimeoutPolicy::symfonyClient($client, self::options(3)));
        self::assertNotSame($clones[2], TimeoutPolicy::symfonyClient($client, self::options(2)), 'the least recently used clone was evicted');
    }

    public function testEachClientHasItsOwnClones(): void
    {
        $first = new Psr18Client();
        $second = new Psr18Client();
        TimeoutPolicy::symfonyClient($first, self::options(1));

        self::assertSame(1, TimeoutPolicy::symfonyCloneCount($first));
        self::assertSame(0, TimeoutPolicy::symfonyCloneCount($second));
    }

    /**
     * @return array<string, mixed>
     */
    private static function options(int $timeout): array
    {
        return ['timeout' => (float) $timeout, 'max_duration' => (float) $timeout];
    }
}
