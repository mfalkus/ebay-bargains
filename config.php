<?php

declare(strict_types=1);

// Load .env if present (simple parser, no dependency)
$envFile = __DIR__ . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            putenv(trim($m[1]) . '=' . trim($m[2], " \t\"'"));
        }
    }
}

$clientId = getenv('EBAY_CLIENT_ID') ?: '';
$clientSecret = getenv('EBAY_CLIENT_SECRET') ?: '';

$unlimitedIpsRaw = getenv('UNLIMITED_IPS') ?: '';
$unlimitedIps = array_values(array_filter(array_map('trim', explode(',', $unlimitedIpsRaw))));
