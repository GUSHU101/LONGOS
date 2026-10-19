<?php

/**
 * BinSearchLookup direct diagnostics, independent of Laravel.
 * Calls that can reach the provider are constrained to this plugin's synthetic
 * test-card catalog; never substitute real payment data.
 *
 * Examples:
 *   php Tools/binsearchlookup_diagnostics.php health
 *
 *   BINSEARCHLOOKUP_API_KEY="..." \
 *   BINSEARCHLOOKUP_USER_ID="..." \
 *   php Tools/binsearchlookup_diagnostics.php validate 4242424242424242
 *
 *   BINSEARCHLOOKUP_API_KEY="..." \
 *   BINSEARCHLOOKUP_USER_ID="..." \
 *   php Tools/binsearchlookup_diagnostics.php quota
 *
 *   BINSEARCHLOOKUP_API_KEY="..." \
 *   BINSEARCHLOOKUP_USER_ID="..." \
 *   php Tools/binsearchlookup_diagnostics.php batch 42424242 40000566 55555555
 */

require_once dirname(__DIR__) . '/Support/TestCardCatalog.php';

use Plugin\BeikeShopTestSandbox\Support\TestCardCatalog;

$mode    = strtolower($argv[1] ?? 'health');
$baseUrl = rtrim(getenv('BINSEARCHLOOKUP_BASE_URL') ?: 'https://api.binsearchlookup.com', '/');
$apiKey  = getenv('BINSEARCHLOOKUP_API_KEY') ?: '';
$userId  = getenv('BINSEARCHLOOKUP_USER_ID') ?: '';

function request(
    string $method,
    string $url,
    array $headers = [],
    ?array $json = null
): array {
    $ch          = curl_init($url);
    $httpHeaders = array_merge(['Accept: application/json'], $headers);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $httpHeaders,
    ]);

    if ($json !== null) {
        $body = json_encode($json, JSON_UNESCAPED_SLASHES);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $httpHeaders[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
    }

    $start   = microtime(true);
    $body    = curl_exec($ch);
    $elapsed = (int) round((microtime(true) - $start) * 1000);
    $http    = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error   = curl_error($ch);

    return [
        'http'      => $http,
        'elapsedMs' => $elapsed,
        'error'     => $error ?: null,
        'body'      => $body,
    ];
}

function authHeaders(string $apiKey, string $userId): array
{
    if ($apiKey === '' || $userId === '') {
        fwrite(STDERR, "Missing BINSEARCHLOOKUP_API_KEY or BINSEARCHLOOKUP_USER_ID.\n");
        exit(2);
    }

    return [
        'X-API-Key: ' . $apiKey,
        'X-User-ID: ' . $userId,
        'User-Agent: BeikeShop-Test-Sandbox-Diagnostics/2.8',
    ];
}

switch ($mode) {
    case 'health':
        $result = request('GET', $baseUrl . '/health');

        break;

    case 'quota':
        $result = request(
            'GET',
            $baseUrl . '/api/quota/' . rawurlencode($userId),
            authHeaders($apiKey, $userId)
        );

        break;

    case 'validate':
        $pan = TestCardCatalog::normalizePan($argv[2] ?? '');
        if (! TestCardCatalog::allowsPan($pan)) {
            fwrite(STDERR, "validate requires a configured TEST PAN in whitelist mode, or any 12-19 digit PAN in open mode.\n");
            exit(2);
        }

        $result = request(
            'POST',
            $baseUrl . '/validate',
            authHeaders($apiKey, $userId),
            ['cardNumber' => $pan]
        );

        break;

    case 'lookup':
        $bin = preg_replace('/\D+/', '', $argv[2] ?? '');
        if (! TestCardCatalog::allowsProviderBin($bin)) {
            fwrite(STDERR, "lookup requires a TEST-card BIN in whitelist mode, or any 6-8 digit BIN in open mode.\n");
            exit(2);
        }

        $result = request(
            'GET',
            $baseUrl . '/lookup?bin=' . rawurlencode($bin),
            authHeaders($apiKey, $userId)
        );

        break;

    case 'batch':
        $requestedBins = array_values(array_filter(array_map(
            static fn ($value) => preg_replace('/\D+/', '', (string) $value),
            array_slice($argv, 2)
        ), static fn ($value) => $value !== ''));

        if ($requestedBins === [] || array_filter(
            $requestedBins,
            static fn (string $bin): bool => ! TestCardCatalog::allowsProviderBin($bin)
        ) !== []) {
            fwrite(STDERR, "batch requires allowed BINs for the current card-input mode.\n");
            exit(2);
        }

        $bins = array_values(array_unique($requestedBins));

        $result = request(
            'POST',
            $baseUrl . '/lookup/batch',
            authHeaders($apiKey, $userId),
            ['bins' => array_slice($bins, 0, 50)]
        );

        break;

    default:
        fwrite(STDERR, "Modes: health | quota | validate <TEST_PAN> | lookup <BIN> | batch <BIN...>\n");
        exit(2);
}

echo "Mode: {$mode}\n";
echo "HTTP: {$result['http']}\n";
echo "Elapsed: {$result['elapsedMs']} ms\n";

if ($result['error']) {
    echo "cURL error: {$result['error']}\n";
    exit(1);
}

$decoded = json_decode((string) $result['body'], true);
if (is_array($decoded)) {
    echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo (string) $result['body'] . PHP_EOL;
}

exit($result['http'] >= 200 && $result['http'] < 300 ? 0 : 1);
