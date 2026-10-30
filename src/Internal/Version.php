<?php

declare(strict_types=1);

namespace PoliPage\Internal;

/**
 * Runtime SDK version. Bumped manually as part of the release flow
 * (see CONTRIBUTING.md). Exposed as a constant so static analyzers and tests
 * can assert against it without instantiation.
 *
 * @internal
 */
final class Version
{
    public const VERSION = '0.11.0';
}
