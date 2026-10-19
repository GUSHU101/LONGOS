<?php

namespace Tests\Unit;

use Beike\Shop\Services\AddressAutocompleteService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AddressAutocompleteServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config([
            'cache.default'                    => 'array',
            'services.address_lookup.enabled'  => true,
            'services.address_lookup.endpoint' => 'https://photon.example.test/api/',
        ]);
        Cache::clear();
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_it_normalizes_remote_address_suggestions(): void
    {
        $payload = json_encode([
            'features' => [[
                'properties' => [
                    'housenumber' => '1600',
                    'street'      => 'Pennsylvania Avenue NW',
                    'city'        => 'Washington',
                    'state'       => 'District of Columbia',
                    'statecode'   => 'DC',
                    'postcode'    => '20500',
                    'country'     => 'United States',
                    'countrycode' => 'us',
                ],
            ]],
        ], JSON_THROW_ON_ERROR);

        $service = $this->serviceWithResponses([new Response(200, ['Content-Type' => 'application/json'], $payload)]);
        $result  = $service->search('1600 Pennsylvania Avenue', 'en-US');

        self::assertCount(1, $result);
        self::assertSame('1600 Pennsylvania Avenue NW', $result[0]['address_1']);
        self::assertSame('Washington', $result[0]['city']);
        self::assertSame('DC', $result[0]['zone_code']);
        self::assertSame('US', $result[0]['country_code']);
        self::assertStringContainsString('20500', $result[0]['label']);

        $this->assertApartmentFallbackPreservesUnit();
        $this->assertUnsafeProviderResultsAreFiltered();
    }

    public function test_it_rejects_non_https_or_private_lookup_endpoints(): void
    {
        config(['services.address_lookup.endpoint' => 'http://127.0.0.1/internal']);

        $this->expectException(RuntimeException::class);
        $this->serviceWithResponses([])->search('different private endpoint query', 'en');
    }

    public function test_it_removes_duplicate_provider_results_and_caps_the_response(): void
    {
        $feature = static fn (int $number): array => [
            'properties' => [
                'housenumber' => (string) $number,
                'street'      => 'Main Street',
                'city'        => 'Example City',
                'country'     => 'United States',
                'countrycode' => 'us',
            ],
        ];
        $features = [$feature(1), $feature(1), $feature(2), $feature(3), $feature(4), $feature(5), $feature(6)];
        $service  = $this->serviceWithResponses([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['features' => $features], JSON_THROW_ON_ERROR)),
        ]);

        $result = $service->search('Main Street Example City', 'en');

        self::assertCount(5, $result);
        self::assertSame('1 Main Street', $result[0]['address_1']);
        self::assertSame('5 Main Street', $result[4]['address_1']);
    }

    public function test_it_prioritizes_addresses_from_the_selected_country(): void
    {
        $payload = json_encode(['features' => [
            [
                'properties' => [
                    'street'  => 'People Road', 'city' => 'Example City',
                    'country' => 'United States', 'countrycode' => 'us',
                ],
            ],
            [
                'properties' => [
                    'street'  => '人民路', 'city' => '上海',
                    'country' => '中国', 'countrycode' => 'cn',
                ],
            ],
        ]], JSON_THROW_ON_ERROR);
        $service = $this->serviceWithResponses([new Response(200, ['Content-Type' => 'application/json'], $payload)]);

        $result = $service->search('人民路', 'zh-CN', 'CN');

        self::assertCount(2, $result);
        self::assertSame('CN', $result[0]['country_code']);
        self::assertSame('人民路', $result[0]['address_1']);
        self::assertSame('US', $result[1]['country_code']);
    }

    public function test_it_normalizes_provider_whitespace_and_uses_district_as_a_city_fallback(): void
    {
        $payload = json_encode(['features' => [[
            'properties' => [
                'housenumber' => "  88\n",
                'street'      => "  Example\tRoad  ",
                'district'    => "  Central\nDistrict ",
                'country'     => 'China',
                'countrycode' => 'cn',
            ],
        ]]], JSON_THROW_ON_ERROR);
        $service = $this->serviceWithResponses([new Response(200, ['Content-Type' => 'application/json'], $payload)]);

        $result = $service->search('88 Example Road', 'en', 'CN');

        self::assertSame('88 Example Road', $result[0]['address_1']);
        self::assertSame('Central District', $result[0]['city']);
        self::assertSame('88 Example Road, Central District, China', $result[0]['label']);
    }

    public function test_it_accepts_the_standard_geojson_media_type(): void
    {
        $payload = json_encode(['features' => [[
            'properties' => [
                'street'  => 'GeoJSON Road', 'city' => 'Example City',
                'country' => 'United Kingdom', 'countrycode' => 'gb',
            ],
        ]]], JSON_THROW_ON_ERROR);
        $service = $this->serviceWithResponses([new Response(200, ['Content-Type' => 'application/geo+json; charset=utf-8'], $payload)]);

        $result = $service->search('GeoJSON Road', 'en', 'GB');

        self::assertSame('GeoJSON Road', $result[0]['address_1']);
        self::assertSame('GB', $result[0]['country_code']);
    }

    private function assertApartmentFallbackPreservesUnit(): void
    {
        $payload = json_encode(['features' => [[
            'properties' => [
                'name'        => 'Chesford Road',
                'city'        => 'Columbus',
                'state'       => 'Ohio',
                'statecode'   => 'OH',
                'postcode'    => '43231',
                'country'     => 'United States',
                'countrycode' => 'us',
                'osm_value'   => 'residential',
            ],
        ]]], JSON_THROW_ON_ERROR);
        $service = $this->serviceWithResponses([
            new Response(200, ['Content-Type' => 'application/json'], '{"features":[]}'),
            new Response(200, ['Content-Type' => 'application/json'], $payload),
        ]);

        $result = $service->search('4321 CHESFORD RD APT 3E', 'en', 'US');

        self::assertCount(1, $result);
        self::assertSame('4321 Chesford Road', $result[0]['address_1']);
        self::assertSame('APT 3E', $result[0]['address_2']);
        self::assertStringContainsString('APT 3E', $result[0]['label']);
    }

    private function assertUnsafeProviderResultsAreFiltered(): void
    {
        $payload = json_encode(['features' => [
            [
                'properties' => [
                    'name'        => 'Morse Rd & Chesford Rd', 'street' => 'Morse Road',
                    'countrycode' => 'us', 'osm_value' => 'bus_stop',
                ],
            ],
            [
                'properties' => [
                    'name'        => 'Chesford Road', 'city' => 'Columbus',
                    'countrycode' => 'us', 'osm_value' => 'residential',
                ],
            ],
            [
                'properties' => [
                    'housenumber' => '4321', 'street' => '23rd Street',
                    'city'        => 'San Francisco', 'countrycode' => 'us', 'osm_value' => 'yes',
                ],
            ],
        ]], JSON_THROW_ON_ERROR);
        $service = $this->serviceWithResponses([
            new Response(200, ['Content-Type' => 'application/json'], $payload),
        ]);

        $result = $service->search('4321 CHESFORD RD', 'en', 'US');

        self::assertCount(1, $result);
        self::assertSame('4321 Chesford Road', $result[0]['address_1']);
        self::assertSame('Columbus', $result[0]['city']);
    }

    /** @param array<int, Response> $responses */
    private function serviceWithResponses(array $responses): AddressAutocompleteService
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);

        return new class($client) extends AddressAutocompleteService
        {
            protected function resolvePublicAddresses(string $host): array
            {
                return $host === 'photon.example.test' ? ['93.184.216.34'] : [];
            }
        };
    }
}
