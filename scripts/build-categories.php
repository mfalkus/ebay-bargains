<?php

declare(strict_types=1);

/**
 * Build step: fetch category trees for EBAY_GB and EBAY_US from the eBay API
 * and write flattened lists to public/data/categories_{marketplace}.json.
 * The frontend loads these static files; the server never parses them per request.
 *
 * Run from project root: php scripts/build-categories.php
 * Requires .env with EBAY_CLIENT_ID and EBAY_CLIENT_SECRET.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/EbayApi.php';
require_once dirname(__DIR__) . '/src/CategoryTreeLoader.php';

$clientId = getenv('EBAY_CLIENT_ID') ?: '';
$clientSecret = getenv('EBAY_CLIENT_SECRET') ?: '';

if ($clientId === '' || $clientSecret === '') {
    fwrite(STDERR, "Set EBAY_CLIENT_ID and EBAY_CLIENT_SECRET in .env\n");
    exit(1);
}

$outDir = dirname(__DIR__) . '/public/data';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$marketplaces = ['EBAY_GB', 'EBAY_US'];
$api = new EbayApi($clientId, $clientSecret);
$loader = new CategoryTreeLoader($api);

foreach ($marketplaces as $marketplace) {
    echo "Fetching categories for {$marketplace}... ";
    try {
        $list = $loader->getFlattenedCategories($marketplace);
        $path = $outDir . '/categories_' . $marketplace . '.json';
        file_put_contents(
            $path,
            json_encode($list, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
        echo count($list) . " categories written to public/data/categories_{$marketplace}.json\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "Done.\n";
