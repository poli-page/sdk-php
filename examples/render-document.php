<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PoliPage\PoliPage;
use PoliPage\ProjectModeInput;

$client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);

$doc = $client->render->document(new ProjectModeInput(
    project: 'billing',
    template: 'invoice',
    data: ['invoiceNumber' => 'INV-001', 'total' => 1280],
));

echo "stored: {$doc->documentId}\n";
echo "presigned URL (15-minute TTL): {$doc->presignedPdfUrl}\n";

$pdf = $doc->downloadPdf();
file_put_contents('./invoice.pdf', $pdf);
