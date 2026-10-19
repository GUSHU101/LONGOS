<?php

namespace Beike\Shop\Services;

use Composer\CaBundle\CaBundle;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class AddressAutocompleteService
{
    private const MAX_RESPONSE_BYTES = 524288;

    private const PROVIDER_RESULT_LIMIT = 10;

    /** Photon sometimes classifies transport stops as houses. */
    private const NON_ADDRESS_OSM_VALUES = [
        'bus_stop', 'platform', 'station', 'tram_stop', 'subway_entrance',
    ];

    private ClientInterface $client;

    public function __construct(?ClientInterface $client = null)
    {
        $caBundle = trim((string) config('services.address_lookup.ca_bundle', ''));
        if ($caBundle !== '' && ! @is_readable($caBundle)) {
            $caBundle = '';
        }
        if ($caBundle === '' && class_exists(CaBundle::class)) {
            $detectedBundle = CaBundle::getSystemCaRootBundlePath();
            // Hosting panels can restrict PHP to the site root. Composer may
            // still discover a system CA path outside that boundary; treat it
            // as unavailable instead of leaking an open_basedir warning.
            $caBundle       = is_string($detectedBundle) && @is_readable($detectedBundle) ? $detectedBundle : '';
        }
        $this->client = $client ?? new Client([
            'connect_timeout' => 3,
            'timeout'         => 8,
            'verify'          => $caBundle !== '' ? $caBundle : true,
            'allow_redirects' => false,
            'http_errors'     => false,
        ]);
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function search(string $query, string $language = 'en', string $countryCode = ''): array
    {
        if (! (bool) config('services.address_lookup.enabled', true)) {
            return [];
        }

        $query       = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        $language    = strtolower(substr(preg_replace('/[^a-z]/i', '', $language) ?: 'en', 0, 2));
        $countryCode = strtoupper(trim($countryCode));
        if (! preg_match('/^[A-Z]{2}$/', $countryCode)) {
            $countryCode = '';
        }
        if (mb_strlen($query) < 3 || mb_strlen($query) > 160) {
            return [];
        }

        $configuredEndpoint = (string) config('services.address_lookup.endpoint', 'https://photon.komoot.io/api/');
        $cacheKey           = 'checkout:address-suggestions:' . hash('sha256', $configuredEndpoint . "\0" . $language . "\0" . $countryCode . "\0" . mb_strtolower($query));

        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable) {
            // Address lookup remains available when an optional cache backend
            // is temporarily unavailable.
        }

        $parsedQuery = $this->splitSecondaryAddress($query);
        $features    = $this->providerFeatures($configuredEndpoint, [
            'q' => $query, 'limit' => self::PROVIDER_RESULT_LIMIT, 'lang' => $language,
        ], $language);
        $features = $this->prioritizeCountry($features, $countryCode);
        $results  = $this->normalizeFeatures(
            $features,
            $parsedQuery['provider_query'],
            $parsedQuery['address_2'],
            $countryCode,
        );

        // Geocoders generally do not index apartment/suite identifiers. Keep
        // the customer's unit value locally, retry only the street portion,
        // and return the unit separately so checkout never discards it.
        if ($results === [] && $parsedQuery['provider_query'] !== $query) {
            $features = $this->providerFeatures($configuredEndpoint, [
                'q' => $parsedQuery['provider_query'], 'limit' => self::PROVIDER_RESULT_LIMIT, 'lang' => $language,
            ], $language);
            $features = $this->prioritizeCountry($features, $countryCode);
            $results  = $this->normalizeFeatures(
                $features,
                $parsedQuery['provider_query'],
                $parsedQuery['address_2'],
                $countryCode,
            );
        }

        try {
            Cache::put($cacheKey, $results, now()->addMinutes($results === [] ? 2 : 15));
        } catch (\Throwable) {
            // The provider response is still useful for the current request.
        }

        return $results;
    }

    /** @return array<int, mixed> */
    private function providerFeatures(string $configuredEndpoint, array $query, string $language): array
    {
        [$endpoint, $host, $port, $address] = $this->validatedEndpoint($configuredEndpoint);
        $pinnedAddress                      = str_contains($address, ':') ? "[{$address}]" : $address;
        $response                           = $this->client->request('GET', $endpoint, [
            'query'   => $query,
            'headers' => [
                'Accept'          => 'application/json',
                'Accept-Language' => $language,
                'User-Agent'      => platform_brand_name() . '/checkout-address-lookup (+' . config('app.url') . ')',
            ],
            'curl'    => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinnedAddress}"]],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Address lookup provider returned a non-success status.');
        }
        if (! $this->isJsonContentType($response->getHeaderLine('Content-Type'))) {
            throw new RuntimeException('Address lookup provider returned an unexpected content type.');
        }
        if ((int) $response->getHeaderLine('Content-Length') > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('Address lookup response is too large.');
        }

        $contents = (string) $response->getBody();
        if ($contents === '' || strlen($contents) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('Address lookup response is invalid.');
        }

        try {
            $payload = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Address lookup response is not valid JSON.', 0, $e);
        }

        return is_array($payload['features'] ?? null) ? $payload['features'] : [];
    }

    private function isJsonContentType(string $contentType): bool
    {
        $contentType = strtolower(trim($contentType));

        return str_starts_with($contentType, 'application/json')
            || preg_match('/^application\/[a-z0-9.+-]+\+json(?:\s*;|$)/', $contentType) === 1;
    }

    /**
     * @param array<int, mixed> $features
     * @return array<int, array<string, string>>
     */
    private function normalizeFeatures(
        array $features,
        string $fallback,
        string $address2 = '',
        string $countryCode = ''
    ): array {
        $ranked         = [];
        $requestedHouse = $this->requestedHouseNumber($fallback);

        foreach (array_slice($features, 0, self::PROVIDER_RESULT_LIMIT) as $index => $feature) {
            $properties = is_array($feature) && is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $score      = $this->featureRelevance($properties, $fallback, $countryCode, $index);
            if ($score === null) {
                continue;
            }

            $street     = $this->bestStreetValue($properties, $fallback);
            $house      = $this->stringValue($properties['housenumber'] ?? null);
            if ($house === '' && $requestedHouse !== '' && $street !== '') {
                $house = $requestedHouse;
            }
            $address1   = trim(implode(' ', array_filter([$house, $street])));
            if ($address1 === '') {
                $address1 = $fallback;
            }

            $city = $this->firstString($properties, ['city', 'town', 'village', 'locality', 'municipality', 'district', 'borough', 'county']);
            $item = [
                'address_1'    => mb_substr($address1, 0, 255),
                'address_2'    => mb_substr($address2, 0, 255),
                'city'         => mb_substr($city, 0, 120),
                'zone'         => mb_substr($this->stringValue($properties['state'] ?? null), 0, 120),
                'zone_code'    => mb_substr($this->stringValue($properties['statecode'] ?? null), 0, 32),
                'zipcode'      => mb_substr($this->stringValue($properties['postcode'] ?? null), 0, 32),
                'country'      => mb_substr($this->stringValue($properties['country'] ?? null), 0, 120),
                'country_code' => strtoupper(mb_substr($this->stringValue($properties['countrycode'] ?? null), 0, 2)),
            ];
            $item['label'] = implode(', ', array_filter([$item['address_1'], $item['address_2'], $item['city'], $item['zone'], $item['zipcode'], $item['country']]));

            $ranked[] = ['score' => $score, 'index' => $index, 'item' => $item];
        }

        usort($ranked, static fn (array $left, array $right): int => $right['score'] <=> $left['score'] ?: $left['index'] <=> $right['index']
        );

        $results = [];
        $seen    = [];
        foreach ($ranked as $candidate) {
            $item     = $candidate['item'];

            $identity = mb_strtolower($item['label']);
            if ($identity === '' || isset($seen[$identity])) {
                continue;
            }

            $seen[$identity] = true;
            $results[]       = $item;
            if (count($results) === 5) {
                break;
            }
        }

        return $results;
    }

    /** @return array{provider_query: string, address_2: string} */
    private function splitSecondaryAddress(string $query): array
    {
        $pattern = '/(?:\s*,?\s+)(?<unit>(?:apt(?:artment)?|unit|suite|ste|room|rm|floor|fl|building|bldg|lot)\.?\s+[\p{L}\p{N}][\p{L}\p{N}.\-\/ ]{0,30}|#\s*[\p{L}\p{N}][\p{L}\p{N}.\-\/]{0,20})$/iu';
        if (preg_match($pattern, $query, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return ['provider_query' => $query, 'address_2' => ''];
        }

        $unit = trim((string) $matches['unit'][0]);
        $base = rtrim(substr($query, 0, (int) $matches[0][1]), " \t\n\r\0\x0B,");
        if (mb_strlen($base) < 3 || $unit === '') {
            return ['provider_query' => $query, 'address_2' => ''];
        }

        return [
            'provider_query' => $base,
            'address_2'      => mb_substr($unit, 0, 255),
        ];
    }

    private function requestedHouseNumber(string $query): string
    {
        return preg_match('/^\s*(\d+[\p{L}]?(?:[-\/]\d+[\p{L}]?)?)\b/u', $query, $matches) === 1
            ? $matches[1]
            : '';
    }

    private function bestStreetValue(array $properties, string $query): string
    {
        $street = $this->stringValue($properties['street'] ?? null);
        $name   = $this->stringValue($properties['name'] ?? null);
        if ($street === '') {
            return $name;
        }
        if ($name === '') {
            return $street;
        }

        return $this->textMatchScore($name, $query) > $this->textMatchScore($street, $query)
            ? $name
            : $street;
    }

    private function featureRelevance(array $properties, string $query, string $countryCode, int $index): ?int
    {
        $osmValue = strtolower($this->stringValue($properties['osm_value'] ?? null));
        if (in_array($osmValue, self::NON_ADDRESS_OSM_VALUES, true)) {
            return null;
        }

        $candidateHouse = $this->stringValue($properties['housenumber'] ?? null);
        $requestedHouse = $this->requestedHouseNumber($query);
        if ($requestedHouse                                                        !== '' && $candidateHouse !== ''
                                                                                          && strcasecmp($requestedHouse, $candidateHouse) !== 0) {
            return null;
        }

        $candidateText = implode(' ', array_filter([
            $this->stringValue($properties['name'] ?? null),
            $this->stringValue($properties['street'] ?? null),
            $this->firstString($properties, ['city', 'town', 'village', 'locality', 'municipality']),
            $this->stringValue($properties['state'] ?? null),
            $this->stringValue($properties['postcode'] ?? null),
        ]));
        $matchScore = $this->textMatchScore($candidateText, $query);

        // For Latin street queries, a result with no meaningful street-token
        // overlap is more dangerous than an empty suggestion list because it
        // can silently populate an unrelated address with the same number.
        $streetTokens = $this->meaningfulStreetTokens($query);
        if ($streetTokens !== [] && $matchScore === 0) {
            return null;
        }

        $score = $matchScore * 20 - $index;
        if ($requestedHouse !== '' && strcasecmp($requestedHouse, $candidateHouse) === 0) {
            $score += 80;
        } elseif ($requestedHouse !== '' && $candidateHouse === '') {
            $score += 15;
        }
        if ($countryCode !== ''
            && strtoupper($this->stringValue($properties['countrycode'] ?? null)) === $countryCode) {
            $score += 40;
        }

        return $score;
    }

    private function textMatchScore(string $candidate, string $query): int
    {
        $candidate = $this->normalizeLookupText($candidate);
        if ($candidate === '') {
            return 0;
        }

        $score = 0;
        foreach ($this->meaningfulStreetTokens($query) as $token) {
            if (preg_match('/(?:^| )' . preg_quote($token, '/') . '(?: |$)/u', $candidate) === 1) {
                $score++;
            }
        }

        return $score;
    }

    /** @return array<int, string> */
    private function meaningfulStreetTokens(string $value): array
    {
        $normalized = $this->normalizeLookupText($value);
        if (! preg_match('/[a-z]/', $normalized)) {
            return [];
        }

        $ignored = [
            'road', 'rd', 'street', 'st', 'avenue', 'ave', 'boulevard', 'blvd',
            'drive', 'dr', 'lane', 'ln', 'court', 'ct', 'highway', 'hwy',
            'parkway', 'pkwy', 'way', 'place', 'pl',
        ];

        return array_values(array_unique(array_filter(
            preg_split('/\s+/u', $normalized) ?: [],
            static fn (string $token): bool => strlen($token) >= 3
                && ! ctype_digit($token)
                && ! in_array($token, $ignored, true),
        )));
    }

    private function normalizeLookupText(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /**
     * Put addresses from the country selected at checkout first while
     * preserving the provider's relevance order inside both groups.
     *
     * @param array<int, mixed> $features
     * @return array<int, mixed>
     */
    private function prioritizeCountry(array $features, string $countryCode): array
    {
        if ($countryCode === '') {
            return $features;
        }

        $matching = [];
        $other    = [];
        foreach ($features as $feature) {
            $properties  = is_array($feature) && is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $featureCode = strtoupper($this->stringValue($properties['countrycode'] ?? null));
            if ($featureCode === $countryCode) {
                $matching[] = $feature;
            } else {
                $other[] = $feature;
            }
        }

        return array_merge($matching, $other);
    }

    private function firstString(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $this->stringValue($data[$key] ?? null);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function stringValue(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }

    /** @return array{string, string, int, string} */
    private function validatedEndpoint(string $endpoint): array
    {
        $endpoint = trim($endpoint);
        $parts    = parse_url($endpoint);
        if (! is_array($parts)) {
            throw new RuntimeException('Address lookup endpoint must be a public HTTPS URL on port 443.');
        }

        $scheme   = strtolower((string) ($parts['scheme'] ?? ''));
        $host     = strtolower((string) ($parts['host'] ?? ''));
        $port     = (int) ($parts['port'] ?? 443);

        if ($scheme !== 'https'
            || $host === ''
            || $port !== 443
            || isset($parts['user'])
            || isset($parts['pass'])
            || ! preg_match('/^[a-z0-9.-]+$/', $host)
        ) {
            throw new RuntimeException('Address lookup endpoint must be a public HTTPS URL on port 443.');
        }

        $addresses = $this->resolvePublicAddresses($host);
        if ($addresses === []) {
            throw new RuntimeException('Address lookup endpoint does not resolve to a public address.');
        }

        return [$endpoint, $host, $port, $addresses[0]];
    }

    /** @return array<int, string> */
    protected function resolvePublicAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicAddress($host) ? [$host] : [];
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records) || $records === []) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (! is_string($address) || ! $this->isPublicAddress($address)) {
                return [];
            }
            $addresses[] = $address;
        }

        usort($addresses, static fn (string $left, string $right): int => str_contains($left, ':') <=> str_contains($right, ':'));

        return array_values(array_unique($addresses));
    }

    protected function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
