<?php

declare(strict_types=1);

// Load .env into a local array (avoids putenv() which is disabled on many hosts)
$env = [];
$envFile = __DIR__ . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            $env[trim($m[1])] = trim($m[2], " \t\"'");
        }
    }
}

$getEnv = function (string $key) use (&$env): string {
    return $env[$key] ?? (string) getenv($key);
};

$clientId = $getEnv('EBAY_CLIENT_ID') ?: '';
$clientSecret = $getEnv('EBAY_CLIENT_SECRET') ?: '';

$unlimitedIpsRaw = $getEnv('UNLIMITED_IPS') ?: '';
$unlimitedIps = array_values(array_filter(array_map('trim', explode(',', $unlimitedIpsRaw))));
