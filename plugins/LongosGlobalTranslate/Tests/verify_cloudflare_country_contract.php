<?php

// Independent checks for Cloudflare header validation and network ranges.
// Run with PHP 8.2+: php LongosGlobalTranslate/Tests/verify_cloudflare_country_contract.php
require dirname(__DIR__) . '/Services/CloudflareVisitorCountry.php';

use Plugin\LongosGlobalTranslate\Services\CloudflareVisitorCountry as Edge;

$expect = static function (bool $pass, string $message): void {
    if (! $pass) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

$expect(Edge::normalizedCountry(' us ') === 'US', 'Uppercase ISO country');
$expect(Edge::normalizedCountry('DE') === 'DE', 'Valid two-letter country');
foreach (['XX', 'T1', 'EU', 'AP', 'ZZ', 'US,DE', '', '123', 'USA'] as $value) {
    $expect(Edge::normalizedCountry($value) === null, 'Reject invalid/special country: ' . $value);
}
$expect(Edge::ipInRanges('173.245.48.9', Edge::EDGE_CIDRS), 'Recognize official Cloudflare IPv4 peer');
$expect(Edge::ipInRanges('2606:4700::1111', Edge::EDGE_CIDRS), 'Recognize official Cloudflare IPv6 peer');
$expect(! Edge::ipInRanges('203.0.113.15', Edge::EDGE_CIDRS), 'Reject direct IPv4 origin access');
$expect(! Edge::ipInRanges('2001:db8::12', Edge::EDGE_CIDRS), 'Reject direct IPv6 origin access');
$expect(Edge::ipInCidr('173.245.63.255', '173.245.48.0/20'), 'IPv4 prefix upper boundary');
$expect(! Edge::ipInCidr('173.245.64.0', '173.245.48.0/20'), 'IPv4 prefix beyond boundary');
$expect(Edge::ipInCidr('2a06:98c0:ffff::1', '2a06:98c0::/29'), 'IPv6 prefix partial-byte matching');
$expect(! Edge::ipInCidr('2a06:98c8::1', '2a06:98c0::/29'), 'IPv6 prefix out of range');
$expect(! Edge::ipInCidr('127.0.0.1', '::1/128'), 'No cross-family address match');
foreach (['0.0.0.0/33', '::/129', '192.0.2.0/', '192.0.2.0/oops', 'not-a-range'] as $range) {
    $expect(! Edge::ipInCidr('192.0.2.7', $range), 'Reject invalid CIDR: ' . $range);
}

echo "PASS: Cloudflare header country validation + IPv4/IPv6 trusted edge CIDR checks\n";
