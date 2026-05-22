<?php

declare(strict_types=1);

// PHPStan MUST reject this file. Render::pdf accepts ProjectModeInput
// only; passing InlineModeInput is a type-system violation.
//
// If PHPStan ever accepts this file cleanly, the sealed-class enforcement
// has regressed — see tests/static-analysis/README.md.

use PoliPage\InlineModeInput;
use PoliPage\PoliPage;

$client = PoliPage::client('pp_test_x');
$client->render->pdf(new InlineModeInput(template: '<p>x</p>', data: []));
