<?php

declare(strict_types=1);

// PHPStan MUST reject: render->pdfStream takes ProjectModeInput only.

use PoliPage\InlineModeInput;
use PoliPage\PoliPage;

$client = PoliPage::client('pp_test_x');
$client->render->pdfStream(new InlineModeInput(template: '<p>x</p>', data: []));
