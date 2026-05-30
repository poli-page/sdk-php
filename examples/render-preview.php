<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PoliPage\InlineModeInput;
use PoliPage\PoliPage;

$client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);

$preview = $client->render->preview(new InlineModeInput(
    template: '<h1>Hello {{ name }}</h1>',
    data: ['name' => 'World'],
));

echo "totalPages: {$preview->totalPages}\n";
echo "environment: {$preview->environment}\n";
echo $preview->html;
