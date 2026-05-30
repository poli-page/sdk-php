<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PoliPage\PoliPage;
use PoliPage\ProjectModeInput;

use function PoliPage\renderToFile;

$client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);

renderToFile(
    $client,
    new ProjectModeInput(
        project: 'billing',
        template: 'invoice',
        data: ['invoiceNumber' => 'INV-001', 'total' => 1280],
    ),
    './invoices/INV-001.pdf',
);

echo "wrote ./invoices/INV-001.pdf\n";
