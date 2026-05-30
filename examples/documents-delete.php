<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PoliPage\PoliPage;

$client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);

$documentId = $argv[1] ?? throw new RuntimeException('usage: documents-delete.php <documentId>');

$client->documents->delete($documentId);

echo "deleted {$documentId}\n";
