<?php

declare(strict_types=1);

// PHPStan MUST reject: render->document takes ProjectModeInput only.

use PoliPage\InlineModeInput;
use PoliPage\PoliPage;

$client = PoliPage::client('pp_test_x');
$client->render->document(new InlineModeInput(template: '<p>x</p>', data: []));
