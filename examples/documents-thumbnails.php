<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PoliPage\PoliPage;
use PoliPage\ThumbnailOptions;

$client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);

$documentId = $argv[1] ?? throw new RuntimeException('usage: documents-thumbnails.php <documentId>');

$thumbnails = $client->documents->thumbnails($documentId, new ThumbnailOptions(
    width: 320,
    format: 'png',
));

foreach ($thumbnails as $thumb) {
    $path = sprintf('./thumb-%02d.png', $thumb->page);
    file_put_contents($path, base64_decode($thumb->data, strict: true));
    echo "wrote {$path} ({$thumb->width}x{$thumb->height})\n";
}
