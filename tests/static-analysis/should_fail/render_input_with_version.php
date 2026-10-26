<?php

declare(strict_types=1);

// PHPStan MUST reject this file. RenderInput has no `version` parameter:
// a render always uses the project's copy published in the key's
// environment.
//
// If PHPStan ever accepts this file cleanly, the render input contract
// has regressed — see tests/static-analysis/README.md.

use PoliPage\PoliPage;
use PoliPage\RenderInput;

$client = PoliPage::client('pp_sandbox_x');
$client->render->pdf(new RenderInput(
    project: 'getting-started',
    template: 'welcome',
    data: [],
    version: '1.0.0',
));
