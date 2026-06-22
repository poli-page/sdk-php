<?php

declare(strict_types=1);

namespace PoliPage\Internal;

/**
 * Runtime SDK version. Bumped manually as part of the release flow
 * (sdk-php.md §12.5). Exposed as a constant so static analyzers and tests
 * can assert against it without instantiation.
 *
 * @internal
 */
final class Version
{
    public const VERSION = '0.9.0';
}
