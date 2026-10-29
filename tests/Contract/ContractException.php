<?php

declare(strict_types=1);

namespace PoliPage\Tests\Contract;

/**
 * The contract file, the skip list or a case is not something the contract
 * test can run. Never an assertion failure: a listed case that throws this
 * still fails the run.
 */
final class ContractException extends \RuntimeException
{
}
