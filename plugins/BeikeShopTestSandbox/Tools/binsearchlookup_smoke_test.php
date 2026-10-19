<?php

/**
 * Standalone smoke test:
 *
 * BINSEARCHLOOKUP_API_KEY=... \
 * BINSEARCHLOOKUP_USER_ID=... \
 * php Tools/binsearchlookup_smoke_test.php 42424242
 */

require_once dirname(__DIR__) . '/Support/TestCardCatalog.php';

use Plugin\BeikeShopTestSandbox\Support\TestCardCatalog;

$bin = preg_replace('/\D+/', '', $argv[1] ?? '');
if (! TestCardCatalog::allowsProviderBin($bin)) {
    fwrite(STDERR, "Usage: php Tools/binsearchlookup_smoke_test.php <allowed 6-8 digit BIN for current mode>\n");
    exit(2);
}

$apiKey  = getenv('BINSEARCHLOOKUP_API_KEY') ?: '';
$userId  = getenv('BINSEARCHLOOKUP_USER_ID') ?: '';
$baseUrl = rtrim(getenv('BINSEARCHLOOKUP_BASE_URL') ?: 'https://api.binsearchlookup.com', '/');

if ($apiKey === '' || $userId === '') {
    fwrite(STDERR, "Missing BINSEARCHLOOKUP_API_KEY or BINSEARCHLOOKUP_USER_ID.\n");
    exit(2);
}

$url = $baseUrl . '/lookup?bin=' . rawurlencode($bin);
$ch  = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'X-API-Key: ' . $apiKey,
        'X-User-ID: ' . $userId,
        'User-Agent: BeikeShop-Test-Sandbox-Smoke/2.7',
    ],
]);

$body  = curl_exec($ch);
$http  = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$error = curl_error($ch);

echo "HTTP: {$http}\n";

if ($error !== '') {
    echo "cURL error: {$error}\n";
    exit(1);
}

$decoded = json_decode((string) $body, true);
if (is_array($decoded)) {
    echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    echo (string) $body . PHP_EOL;
}

exit($http >= 200 && $http < 300 ? 0 : 1);
