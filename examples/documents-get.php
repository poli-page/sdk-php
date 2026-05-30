<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PoliPage\PoliPage;

$client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);

$documentId = $argv[1] ?? throw new RuntimeException('usage: documents-get.php <documentId>');

$doc = $client->documents->get($documentId);

echo "documentId: {$doc->documentId}\n";
echo "pageCount: {$doc->pageCount}\n";
echo "presignedPdfUrl: {$doc->presignedPdfUrl}\n";
echo "expiresAt: {$doc->expiresAt}\n";
