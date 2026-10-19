<?php

namespace Tests\Feature;

use Beike\Admin\Services\SettingService;
use Beike\Services\CurrencyCatalog;
use Beike\Services\CurrencyService;
use Beike\Services\VisitorCurrencyService;
use Database\Seeders\CountriesSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class CurrencyLocalizationTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $this->databasePath = tempnam(sys_get_temp_dir(), 'longos-currency-');
        config([
            'app.key'                            => 'base64:' . base64_encode(random_bytes(32)),
            'cache.default'                      => 'array',
            'database.default'                   => 'currency_test',
            'database.connections.currency_test' => [
                'driver'                  => 'sqlite',
                'database'                => $this->databasePath,
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
            'bk'                           => ['system' => ['base' => ['rate_api_key' => '']]],
            'currency.minimum_rate_count'  => 2,
            'currency.required_rate_codes' => ['USD', 'EUR'],
        ]);
        DB::purge('currency_test');
        DB::setDefaultConnection('currency_test');
        CurrencyService::resetRuntimeCache();

        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->string('currency_code', 3)->primary();
            $table->string('base_currency', 3)->default('USD');
            $table->decimal('rate', 30, 12);
            $table->string('currency_symbol', 32)->default('');
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();
        });
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('space');
            $table->string('name');
            $table->text('value');
            $table->boolean('json')->default(false);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        CurrencyService::resetRuntimeCache();
        DB::disconnect('currency_test');
        @unlink($this->databasePath);
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_saving_api_key_verifies_and_warms_rates_without_a_cli_command(): void
    {
        Http::fake([
            'https://v6.exchangerate-api.com/*' => Http::response($this->providerPayload([
                'USD' => 1,
                'EUR' => 0.91,
                'JPY' => 154.25,
            ])),
        ]);

        SettingService::storeSettings(['rate_api_key' => 'valid_api_key_123']);

        $saved = (string) DB::table('settings')->where('name', 'rate_api_key')->value('value');
        self::assertStringStartsWith('encrypted:', $saved);
        self::assertSame('valid_api_key_123', Crypt::decryptString(substr($saved, strlen('encrypted:'))));
        self::assertSame(3, DB::table('exchange_rates')->count());
        self::assertSame(0.91, CurrencyService::getInstance()->rateFor('EUR'));
    }

    public function test_failed_replacement_key_preserves_saved_key_and_last_known_good_snapshot(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response($this->providerPayload(['USD' => 1, 'EUR' => 0.91]))
                : Http::response(['result' => 'error-type'], 401);
        });
        SettingService::storeSettings(['rate_api_key' => 'valid_api_key_123']);
        $savedBefore = (string) DB::table('settings')->where('name', 'rate_api_key')->value('value');
        $ratesBefore = DB::table('exchange_rates')->orderBy('currency_code')->pluck('rate', 'currency_code')->all();

        try {
            SettingService::storeSettings(['rate_api_key' => 'replacement_key_123']);
            self::fail('An invalid replacement key must be rejected.');
        } catch (\Exception $exception) {
            self::assertStringContainsString('原有密钥和汇率快照均未修改', $exception->getMessage());
        }

        self::assertSame($savedBefore, DB::table('settings')->where('name', 'rate_api_key')->value('value'));
        self::assertEquals($ratesBefore, DB::table('exchange_rates')->orderBy('currency_code')->pluck('rate', 'currency_code')->all());
    }

    public function test_blank_secret_field_upgrades_a_legacy_plaintext_key_without_revealing_it(): void
    {
        config(['bk.system.base.rate_api_key' => 'legacy_api_key_123']);
        DB::table('exchange_rates')->insert([
            $this->rateRow('USD', 1.0),
            $this->rateRow('EUR', 0.91),
        ]);
        CurrencyService::resetRuntimeCache();

        SettingService::storeSettings(['rate_api_key' => '']);

        $saved = (string) DB::table('settings')->where('name', 'rate_api_key')->value('value');
        self::assertStringStartsWith('encrypted:', $saved);
        self::assertSame('legacy_api_key_123', Crypt::decryptString(substr($saved, strlen('encrypted:'))));
    }

    public function test_every_seeded_country_has_a_supported_currency_mapping(): void
    {
        $mapping = config('currency.country_currency', []);

        foreach ((new CountriesSeeder)->getItems() as $country) {
            self::assertArrayHasKey($country['code'], $mapping, "Missing currency mapping for {$country['code']}");
            self::assertTrue(CurrencyCatalog::isSupported($mapping[$country['code']]), "Unsupported currency for {$country['code']}");
        }

        self::assertSame('EUR', $mapping['BG']);
        self::assertSame('USD', $mapping['CW']);
        self::assertSame('USD', $mapping['SX']);
        self::assertSame(2, CurrencyCatalog::decimalPlaces('HUF'));
        self::assertSame(2, CurrencyCatalog::decimalPlaces('TWD'));
    }

    public function test_currency_format_and_symbols_are_deterministic_across_servers_and_old_snapshots(): void
    {
        $row                    = $this->rateRow('USD', 1.0);
        $row['currency_symbol'] = 'US$';
        $row['decimal_places']  = 0;
        DB::table('exchange_rates')->insert($row);
        CurrencyService::resetRuntimeCache();

        $service = CurrencyService::getInstance();

        self::assertSame('$12.50 USD', $service->format(12.5, 'USD', 1));
        self::assertSame('-¥12 JPY', $service->format(-12, 'JPY', 1));
        self::assertSame('€12.50 EUR', $service->format(12.5, 'EUR', 1));
        self::assertSame('₫12 VND', $service->format(12, 'VND', 1));
        self::assertSame('CA$', CurrencyCatalog::symbol('CAD'));
        self::assertSame('NT$', CurrencyCatalog::symbol('TWD'));
        self::assertSame('Bds$', CurrencyCatalog::symbol('BBD'));
        self::assertSame('₦', CurrencyCatalog::symbol('NGN'));
    }

    public function test_only_a_verified_cloudflare_request_can_supply_the_country(): void
    {
        config([
            'currency.trust_cdn_country_headers' => true,
            'currency.geolite2_path'             => sys_get_temp_dir() . '/longos-missing-geolite-' . bin2hex(random_bytes(8)) . '.mmdb',
        ]);
        $resolver = new VisitorCurrencyService;

        $untrusted = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.20']);
        $untrusted->headers->set('CF-IPCountry', 'JP');
        self::assertNull($resolver->countryCode($untrusted));

        $trusted = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.20']);
        $trusted->headers->set('CF-IPCountry', 'JP');
        $trusted->attributes->set('longos.cloudflare.verified', true);
        self::assertSame('JP', $resolver->countryCode($trusted));
        self::assertSame('JPY', $resolver->currencyForCountry('JP'));
    }

    public function test_verified_chinese_cloudflare_visitor_receives_converted_cny_with_the_cny_symbol(): void
    {
        config([
            'currency.trust_cdn_country_headers' => true,
            'currency.geolite2_path'             => sys_get_temp_dir() . '/longos-missing-geolite-' . bin2hex(random_bytes(8)) . '.mmdb',
        ]);
        DB::table('exchange_rates')->insert([
            $this->rateRow('USD', 1.0),
            $this->rateRow('CNY', 7.2),
        ]);
        CurrencyService::resetRuntimeCache();
        $resolver = new VisitorCurrencyService;

        $trusted = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '104.16.10.20']);
        $trusted->headers->set('CF-IPCountry', 'CN');
        $trusted->attributes->set('longos.cloudflare.verified', true);
        $resolved = $resolver->resolve($trusted, false);

        self::assertSame(['currency' => 'CNY', 'country' => 'CN', 'source' => 'ip'], $resolved);
        self::assertSame('¥72.00 CNY', CurrencyService::getInstance()->format(10, $resolved['currency']));

        $untrusted = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.20']);
        $untrusted->headers->set('CF-IPCountry', 'CN');
        self::assertSame('USD', $resolver->resolve($untrusted, false)['currency']);
    }

    public function test_runtime_rate_cache_is_isolated_by_tenant_prefix(): void
    {
        config(['database.connections.currency_test.prefix' => 'tenant_a_']);
        DB::purge('currency_test');
        DB::setDefaultConnection('currency_test');
        $this->createPrefixedRateTable();
        DB::table('exchange_rates')->insert($this->rateRow('EUR', 0.80));
        $tenantA = CurrencyService::getInstance();
        self::assertSame(0.8, $tenantA->rateFor('EUR'));
        self::assertTrue($tenantA->rateStatus()['storage_ready']);

        config(['database.connections.currency_test.prefix' => 'tenant_b_']);
        DB::purge('currency_test');
        DB::setDefaultConnection('currency_test');
        $this->createPrefixedRateTable();
        DB::table('exchange_rates')->insert($this->rateRow('EUR', 0.95));
        $tenantB = CurrencyService::getInstance();
        self::assertNotSame($tenantA, $tenantB);
        self::assertSame(0.95, $tenantB->rateFor('EUR'));
        self::assertTrue($tenantB->rateStatus()['storage_ready']);

        config(['database.connections.currency_test.prefix' => 'tenant_a_']);
        DB::purge('currency_test');
        DB::setDefaultConnection('currency_test');
        self::assertSame(0.8, CurrencyService::getInstance()->rateFor('EUR'));
    }

    public function test_failed_scheduled_refresh_is_reported_even_when_an_old_snapshot_exists(): void
    {
        config(['bk.system.base.rate_api_key' => 'valid_api_key_123']);
        DB::table('exchange_rates')->insert([
            $this->rateRow('USD', 1.0),
            $this->rateRow('EUR', 0.91),
        ]);
        DB::table('exchange_rates')->update(['fetched_at' => now()->subDays(2)]);
        CurrencyService::resetRuntimeCache();
        Http::fake(['https://v6.exchangerate-api.com/*' => Http::response(['result' => 'error-type'], 503)]);

        $service = CurrencyService::getInstance();
        $rates   = $service->refreshRatesIfDue(true);

        self::assertSame(0.91, $rates['EUR']);
        self::assertSame([
            'attempted'   => true,
            'success'     => false,
            'reason'      => 'provider_unavailable',
            'rates_count' => 2,
        ], $service->lastRefreshOutcome());
        self::assertSame(1, Artisan::call('fetch:currency-rate', ['--force' => true]));
    }

    public function test_provider_timestamps_are_stored_in_mysql_compatible_utc_format(): void
    {
        Http::fake(['https://v6.exchangerate-api.com/*' => Http::response($this->providerPayload([
            'USD' => 1,
            'EUR' => 0.91,
        ]))]);

        CurrencyService::getInstance()->verifyAndRefreshRates('valid_api_key_123');

        $timestamp = (string) DB::table('exchange_rates')->where('currency_code', 'EUR')->value('provider_updated_at');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timestamp);
        self::assertStringNotContainsString('T', $timestamp);
        self::assertStringNotContainsString('+', $timestamp);
    }

    public function test_rate_status_exposes_storage_and_runtime_policy_readiness(): void
    {
        $status = CurrencyService::getInstance()->rateStatus();
        self::assertTrue($status['storage_ready']);
        self::assertSame(CurrencyService::POLICY_REVISION, $status['policy_revision']);

        Schema::drop('exchange_rates');
        CurrencyService::resetRuntimeCache();
        $missingTable = CurrencyService::getInstance()->rateStatus();
        self::assertFalse($missingTable['storage_ready']);
        self::assertSame(['exchange_rates'], $missingTable['storage_missing']);

        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->string('currency_code', 3)->primary();
            $table->string('base_currency', 3)->default('USD');
            $table->decimal('rate', 30, 12);
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();
        });
        CurrencyService::resetRuntimeCache();
        $partialTable = CurrencyService::getInstance()->rateStatus();
        self::assertFalse($partialTable['storage_ready']);
        self::assertSame(['currency_symbol', 'decimal_places'], $partialTable['storage_missing']);
    }

    public function test_incomplete_provider_snapshot_cannot_replace_last_known_good_rates(): void
    {
        DB::table('exchange_rates')->insert([
            $this->rateRow('USD', 1.0),
            $this->rateRow('EUR', 0.91),
        ]);
        CurrencyService::resetRuntimeCache();
        Http::fake(['https://v6.exchangerate-api.com/*' => Http::response($this->providerPayload([
            'USD' => 999,
        ]))]);

        $service = CurrencyService::getInstance();
        self::assertSame([], $service->verifyAndRefreshRates('valid_api_key_123'));
        self::assertSame('provider_payload_incomplete', $service->lastRefreshOutcome()['reason']);
        self::assertSame(0.91, (float) DB::table('exchange_rates')->where('currency_code', 'EUR')->value('rate'));
        self::assertSame(1.0, (float) DB::table('exchange_rates')->where('currency_code', 'USD')->value('rate'));
    }

    public function test_provider_cannot_change_the_usd_base_rate(): void
    {
        Http::fake(['https://v6.exchangerate-api.com/*' => Http::response($this->providerPayload([
            'USD' => 999,
            'EUR' => 0.91,
        ]))]);

        $rates = CurrencyService::getInstance()->verifyAndRefreshRates('valid_api_key_123');

        self::assertSame(1.0, $rates['USD']);
        self::assertSame(1.0, (float) DB::table('exchange_rates')->where('currency_code', 'USD')->value('rate'));
    }

    public function test_country_diagnostics_distinguish_configured_from_verified_cloudflare(): void
    {
        config([
            'currency.trust_cdn_country_headers' => true,
            'currency.geolite2_path'             => sys_get_temp_dir() . '/longos-missing-geolite-' . bin2hex(random_bytes(8)) . '.mmdb',
        ]);
        $resolver = new VisitorCurrencyService;

        $untrusted = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.20']);
        $untrusted->headers->set('CF-IPCountry', 'JP');
        $untrustedDiagnostics = $resolver->diagnostics($untrusted);
        self::assertTrue($untrustedDiagnostics['cdn_headers_enabled']);
        self::assertFalse($untrustedDiagnostics['cloudflare_verified']);
        self::assertSame('untrusted_country_header', $untrustedDiagnostics['blocking_issue']);

        $trusted = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.20']);
        $trusted->headers->set('CF-IPCountry', 'JP');
        $trusted->attributes->set('longos.cloudflare.verified', true);
        $trustedDiagnostics = $resolver->diagnostics($trusted);
        self::assertTrue($trustedDiagnostics['cloudflare_verified']);
        self::assertTrue($trustedDiagnostics['detection_ready']);
        self::assertSame('cloudflare', $trustedDiagnostics['resolution_source']);
        self::assertSame('JPY', $trustedDiagnostics['detected_currency']);
    }

    /** @param array<string, float|int> $rates */
    private function providerPayload(array $rates): array
    {
        return [
            'result'                => 'success',
            'base_code'             => 'USD',
            'time_last_update_unix' => now()->subHour()->getTimestamp(),
            'time_next_update_unix' => now()->addDay()->getTimestamp(),
            'conversion_rates'      => $rates,
        ];
    }

    private function createPrefixedRateTable(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->string('currency_code', 3)->primary();
            $table->string('base_currency', 3)->default('USD');
            $table->decimal('rate', 30, 12);
            $table->string('currency_symbol', 32)->default('');
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();
        });
    }

    /** @return array<string, mixed> */
    private function rateRow(string $currency, float $rate): array
    {
        return [
            'currency_code'       => $currency,
            'base_currency'       => 'USD',
            'rate'                => $rate,
            'currency_symbol'     => CurrencyCatalog::symbol($currency),
            'decimal_places'      => CurrencyCatalog::decimalPlaces($currency),
            'provider_updated_at' => now(),
            'fetched_at'          => now(),
            'created_at'          => now(),
            'updated_at'          => now(),
        ];
    }
}
