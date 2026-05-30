<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PoliPage\PoliPage;
use PoliPage\ProjectModeInput;

$client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);

$pdf = $client->render->pdf(new ProjectModeInput(
    project: 'billing',
    template: 'invoice',
    data: ['invoiceNumber' => 'INV-001', 'total' => 1280],
));

file_put_contents('./invoice.pdf', $pdf);
