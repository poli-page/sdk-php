<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PoliPage\PoliPage;
use PoliPage\RenderInput;

$client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);

$stream = $client->render->pdfStream(new RenderInput(
    project: 'billing',
    template: 'invoice',
    data: ['invoiceNumber' => 'INV-001', 'total' => 1280],
));

$out = fopen('./invoice.pdf', 'wb');
try {
    while (!$stream->eof()) {
        $chunk = $stream->read(8192);
        if ($chunk === '') {
            break;
        }
        fwrite($out, $chunk);
    }
} finally {
    fclose($out);
    $stream->close();
}
