<?php

namespace Tests\Feature;

use App\Http\Middleware\ShareViewData;
use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use App\Services\BundledPluginSynchronizer;
use Beike\Admin\Http\Controllers\PluginController;
use Beike\Plugin\Plugin;
use Beike\Repositories\SettingRepo;
use Beike\Services\CloudflareIpRangeService;
use Beike\Shop\Http\Controllers\HomeController;
use Beike\Shop\Providers\PluginServiceProvider;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Plugin\LongosBotShield\Middleware\Shop\ProtectStorefront;
use Plugin\LongosBotShield\Services\BotRiskEngine;
use Plugin\LongosBotShield\Services\BotShieldSettings;
use Plugin\LongosBotShield\Services\InternalHomepageRequest;
use Plugin\LongosBotShield\Services\IpInfoClient;
use Plugin\LongosBotShield\Services\IpInfoLocalDatabase;
use Plugin\LongosBotShield\Services\MainSiteHomepage;
use Plugin\LongosBotShield\Services\NetworkIntelligenceClient;
use Plugin\LongosBotShield\Services\RiskAssessment;
use Plugin\LongosBotShield\Services\ShieldedDocumentPresenter;
use Plugin\LongosBotShield\Services\ThreatCatalog;
use Plugin\LongosBotShield\Support\CidrMatcher;
use Symfony\Component\HttpFoundation\Response;

class LongosBotShieldTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config([
            'cache.default'                                                => 'array',
            'bk.plugin.longos_bot_shield.ipinfo_token'                     => 'ipinfo-test-token',
            'bk.plugin.longos_bot_shield.cache_minutes'                    => 1440,
            'bk.plugin.longos_bot_shield.provider_requests_per_minute'     => 300,
            'bk.plugin.longos_bot_shield.block_china_regions'              => false,
            'bk.plugin.longos_bot_shield.vendor_allowlist_enabled'         => false,
            'bk.plugin.longos_bot_shield.allowed_asns'                     => '',
            'bk.plugin.longos_bot_shield.allowed_organization_keywords'    => '',
            'bk.plugin.longos_bot_shield.allow_cidrs'                      => '',
            'bk.plugin.longos_bot_shield.deny_cidrs'                       => '',
            'bk.plugin.longos_bot_shield.blocked_asns'                     => '',
            'bk.plugin.longos_bot_shield.organization_keywords'            => '',
            'bk.plugin.longos_bot_shield.diagnostic_log'                   => false,
        ]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_configuration_panel_exposes_one_action_and_one_provider(): void
    {
        $columns = require base_path('plugins/LongosBotShield/columns.php');
        $names   = array_column($columns, 'name');

        self::assertContains('ipinfo_token', $names);
        self::assertSame(
            'plugins.bot_shield_health',
            collect($columns)->firstWhere('name', 'ipinfo_health')['action_route'],
        );
        self::assertTrue(collect($columns)->firstWhere('name', 'ipinfo_health')['action_form']);
        self::assertSame('guide', collect($columns)->firstWhere('name', 'builtin_catalog')['type']);
        self::assertSame('bool', collect($columns)->firstWhere('name', 'block_china_regions')['type']);
        self::assertContains('blocked_asns', $names);
        self::assertContains('organization_keywords', $names);
        self::assertContains('allow_cidrs', $names);
        self::assertContains('deny_cidrs', $names);
        foreach ([
            'enforcement_mode', 'crawler_policy', 'use_builtin_catalog',
            'challenge_threshold', 'block_threshold', 'requests_per_minute',
            'burst_per_10_seconds', 'cloudflare_radar_fallback_enabled',
            'cloudflare_radar_token', 'ipinfo_api_tier',
        ] as $removed) {
            self::assertNotContains($removed, $names, $removed);
        }

        $middleware = (string) file_get_contents(base_path(
            'plugins/LongosBotShield/Middleware/Shop/ProtectStorefront.php',
        ));
        self::assertStringNotContainsString('enforcementMode', $middleware);
        self::assertStringNotContainsString('challenge', mb_strtolower($middleware));
        self::assertStringContainsString("'homepage'", $middleware);
    }

    public function test_local_catalog_contains_requested_platform_asns_and_signatures(): void
    {
        self::assertSame(
            ['AS32934', 'AS54115', 'AS63293'],
            ThreatCatalog::PLATFORM_ASNS['meta_facebook'],
        );
        self::assertSame(
            ['AS11983', 'AS138699', 'AS396986'],
            ThreatCatalog::PLATFORM_ASNS['tiktok'],
        );
        foreach (['AS801', 'AS7224', 'AS8987', 'AS14618', 'AS16509', 'AS21664', 'AS36263', 'AS62785', 'AS214101', 'AS401395'] as $asn) {
            self::assertContains($asn, ThreatCatalog::PLATFORM_ASNS['amazon']);
        }
        foreach ([
            'AS15169', 'AS16550', 'AS19527', 'AS22577', 'AS33715', 'AS36040',
            'AS36383', 'AS36384', 'AS36385', 'AS36411', 'AS45566', 'AS139070',
            'AS139190', 'AS214609', 'AS214611', 'AS394089', 'AS395973',
            'AS396178', 'AS396982',
        ] as $asn) {
            self::assertContains($asn, ThreatCatalog::PLATFORM_ASNS['google']);
        }
        self::assertNotContains('AS16591', ThreatCatalog::platformAsns(), 'Google Fiber is consumer access.');
        self::assertNotContains('AS36492', ThreatCatalog::platformAsns(), 'Google WiFi is consumer access.');
        foreach (['AS174', 'AS3356', 'AS6939', 'AS8100'] as $consumerOrTransitAsn) {
            self::assertNotContains($consumerOrTransitAsn, ThreatCatalog::HOSTING_ASNS);
            self::assertNotContains($consumerOrTransitAsn, app(BotShieldSettings::class)->blockedAsns());
        }

        $settings = app(BotShieldSettings::class);
        foreach (ThreatCatalog::platformAsns() as $asn) {
            self::assertContains($asn, $settings->blockedAsns());
        }
        foreach (['facebookexternalhit', 'tiktokspider', 'googlebot', 'gptbot'] as $marker) {
            self::assertContains($marker, ThreatCatalog::crawlerMarkers());
        }
        foreach (['playwright', 'puppeteer', 'selenium', 'webdriver'] as $marker) {
            self::assertContains($marker, ThreatCatalog::AUTOMATION_MARKERS);
        }
        foreach (['python-requests', 'curl/', 'okhttp', 'node-fetch'] as $marker) {
            self::assertContains($marker, ThreatCatalog::SCRIPT_CLIENT_MARKERS);
        }
        foreach (['bright data', 'oxylabs', 'mullvad', 'proton vpn'] as $marker) {
            self::assertContains($marker, ThreatCatalog::PROXY_ORGANIZATION_KEYWORDS);
        }
        self::assertNotContains('vpn', ThreatCatalog::PROXY_ORGANIZATION_KEYWORDS);
        self::assertNotContains('proxy', ThreatCatalog::PROXY_ORGANIZATION_KEYWORDS);
        foreach (['cogent', 'lumen', 'level 3', 'hurricane electric', '1&1 internet'] as $transitIdentity) {
            self::assertNotContains($transitIdentity, ThreatCatalog::ORGANIZATION_KEYWORDS);
        }
    }

    public function test_ipinfo_token_is_encrypted_persisted_preserved_and_decrypted(): void
    {
        $originalDefault = config('database.default');
        config([
            'database.default'                              => 'bot_shield_settings_test',
            'database.connections.bot_shield_settings_test' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('bot_shield_settings_test');
        Schema::connection('bot_shield_settings_test')->create('settings', static function ($table): void {
            $table->id();
            $table->string('type');
            $table->string('space');
            $table->string('name');
            $table->text('value')->nullable();
            $table->boolean('json')->default(false);
            $table->timestamps();
        });

        try {
            $normalized = BotShieldSettings::normalizeForStorage([
                'status'       => '1',
                'ipinfo_token' => 'secret-token-value',
                'allowed_asns' => '64496, AS64496; as15169',
                'blocked_asns' => 'AS64496,AS64496',
            ]);
            self::assertStringStartsWith(BotShieldSettings::ENCRYPTED_PREFIX, $normalized['ipinfo_token']);
            self::assertStringNotContainsString('secret-token-value', $normalized['ipinfo_token']);
            self::assertSame('AS64496, AS15169', $normalized['allowed_asns']);
            self::assertSame('AS64496', $normalized['blocked_asns']);

            SettingRepo::update('plugin', 'longos_bot_shield', $normalized);
            $stored = DB::connection('bot_shield_settings_test')->table('settings')
                ->where('type', 'plugin')
                ->where('space', 'longos_bot_shield')
                ->where('name', 'ipinfo_token')
                ->value('value');
            self::assertSame($normalized['ipinfo_token'], $stored);
            self::assertSame('secret-token-value', app(BotShieldSettings::class)->ipinfoToken());

            $package = json_decode(
                (string) file_get_contents(base_path('plugins/LongosBotShield/config.json')),
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
            $plugin = (new Plugin(base_path('plugins/LongosBotShield'), $package))
                ->setDirname('LongosBotShield')
                ->setColumns();
            $controller = new PluginController;
            $preserved  = Closure::bind(
                fn (): array => $this->preserveBlankPluginSecrets($plugin, ['ipinfo_token' => '']),
                $controller,
                PluginController::class,
            )();
            self::assertSame($normalized['ipinfo_token'], $preserved['ipinfo_token']);
            self::assertSame($preserved, BotShieldSettings::normalizeForStorage($preserved));

            $form = (string) file_get_contents(base_path('resources/beike/admin/views/pages/plugins/form.blade.php'));
            self::assertStringContainsString('$hasSavedSecret', $form);
            self::assertStringContainsString(':required="$secretRequired"', $form);
            self::assertStringContainsString("__('admin/plugin.secret_saved')", $form);

            $safeInput = Closure::bind(
                fn (): array => $this->safePluginInput($plugin, [
                    'status'        => '1',
                    'ipinfo_token'  => 'must-not-enter-session',
                    'cache_minutes' => '1440',
                ]),
                $controller,
                PluginController::class,
            )();
            self::assertArrayNotHasKey('ipinfo_token', $safeInput);
            self::assertSame('1440', $safeInput['cache_minutes']);
        } finally {
            config(['database.default' => $originalDefault]);
            DB::purge('bot_shield_settings_test');
        }
    }

    public function test_upgrade_removes_retired_settings_and_legacy_transit_defaults_only(): void
    {
        $originalDefault = config('database.default');
        config([
            'database.default'                              => 'bot_shield_cleanup_test',
            'database.connections.bot_shield_cleanup_test'  => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('bot_shield_cleanup_test');
        Schema::connection('bot_shield_cleanup_test')->create('settings', static function ($table): void {
            $table->id();
            $table->string('type');
            $table->string('space');
            $table->string('name');
            $table->text('value')->nullable();
            $table->boolean('json')->default(false);
            $table->timestamps();
        });

        try {
            $now = now();
            DB::table('settings')->insert([
                ['type' => 'plugin', 'space' => 'longos_bot_shield', 'name' => 'cloudflare_radar_token', 'value' => 'retired-secret', 'json' => false, 'created_at' => $now, 'updated_at' => $now],
                ['type' => 'plugin', 'space' => 'longos_bot_shield', 'name' => 'enforcement_mode', 'value' => 'challenge', 'json' => false, 'created_at' => $now, 'updated_at' => $now],
                ['type' => 'plugin', 'space' => 'longos_bot_shield', 'name' => 'blocked_asns', 'value' => 'AS6939, 174; AS3356', 'json' => false, 'created_at' => $now, 'updated_at' => $now],
                ['type' => 'plugin', 'space' => 'longos_bot_shield', 'name' => 'organization_keywords', 'value' => 'facebook, meta, google, tiktok, amazon, hosting, data center, microsoft azure, digitalocean, linode, akamai, ovh, hetzner, choopa, m2f', 'json' => false, 'created_at' => $now, 'updated_at' => $now],
            ]);
            $synchronizer = app(BundledPluginSynchronizer::class);
            Closure::bind(
                fn (): mixed => $this->cleanupBotShieldLegacySettings(),
                $synchronizer,
                BundledPluginSynchronizer::class,
            )();

            self::assertFalse(DB::table('settings')->where('name', 'cloudflare_radar_token')->exists());
            self::assertFalse(DB::table('settings')->where('name', 'enforcement_mode')->exists());
            self::assertSame('', DB::table('settings')->where('name', 'blocked_asns')->value('value'));
            self::assertSame(
                'hosting, data center, datacenter, microsoft azure, digitalocean, linode, akamai, ovh, hetzner, choopa, m2f',
                DB::table('settings')->where('name', 'organization_keywords')->value('value'),
            );

            DB::table('settings')->where('name', 'blocked_asns')->update(['value' => 'AS174, AS65000, AS64496']);
            DB::table('settings')->where('name', 'organization_keywords')->update(['value' => 'my private host']);
            Closure::bind(
                fn (): mixed => $this->cleanupBotShieldLegacySettings(),
                $synchronizer,
                BundledPluginSynchronizer::class,
            )();
            self::assertSame('AS65000, AS64496', DB::table('settings')->where('name', 'blocked_asns')->value('value'));
            self::assertSame('my private host', DB::table('settings')->where('name', 'organization_keywords')->value('value'));

            DB::table('settings')->where('name', 'blocked_asns')->update(['value' => 'AS64496,AS64496']);
            Closure::bind(
                fn (): mixed => $this->cleanupBotShieldLegacySettings(),
                $synchronizer,
                BundledPluginSynchronizer::class,
            )();
            self::assertSame('AS64496', DB::table('settings')->where('name', 'blocked_asns')->value('value'));
        } finally {
            config(['database.default' => $originalDefault]);
            DB::purge('bot_shield_cleanup_test');
        }
    }

    public function test_ipinfo_lite_uses_bearer_auth_and_normalizes_asn_identity(): void
    {
        Http::fake(['api.ipinfo.io/*' => Http::response([
            'ip'             => '8.8.8.8',
            'asn'            => 'AS15169',
            'as_name'        => 'Google LLC',
            'as_domain'      => 'google.com',
            'country'        => 'United States',
            'country_code'   => 'US',
            'continent'      => 'North America',
            'continent_code' => 'NA',
        ], 200)]);

        $result = app(IpInfoClient::class)->lookup('8.8.8.8');

        self::assertSame('ok', $result['status']);
        self::assertSame('AS15169', $result['asn']);
        self::assertSame('Google LLC', $result['name']);
        self::assertSame('google.com', $result['domain']);
        self::assertSame('US', $result['country_code']);
        self::assertSame('NA', $result['continent_code']);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://api.ipinfo.io/lite/8.8.8.8'
            && $request->hasHeader('Authorization', 'Bearer ipinfo-test-token')
            && ! str_contains($request->url(), 'token='));
    }

    public function test_ipinfo_live_probe_validates_the_entered_token_and_warms_trusted_cache(): void
    {
        Http::fake(static function (ClientRequest $request) {
            if ($request->hasHeader('Authorization', 'Bearer valid-token')) {
                return Http::response([
                    'ip'        => '8.8.8.8',
                    'asn'       => 'AS15169',
                    'as_name'   => 'Google LLC',
                    'as_domain' => 'google.com',
                ], 200);
            }

            return Http::response(['error' => ['title' => 'Unauthorized']], 401);
        });

        $client = app(IpInfoClient::class);
        $valid  = $client->probe('valid-token');
        self::assertTrue($valid['healthy']);
        self::assertSame('healthy', $valid['status']);
        self::assertSame('AS15169', $valid['asn']);
        self::assertIsInt($valid['latency_ms']);
        self::assertSame('unauthorized', $client->probe('invalid-token')['status']);
        self::assertSame('missing', $client->probe('')['status']);
        self::assertSame('invalid_token', $client->probe("invalid\r\ntoken")['status']);
        self::assertSame('invalid_target', $client->probe('valid-token', '127.0.0.1')['status']);

        $malformed = (new PluginController)->botShieldHealth(
            Request::create('/admin/plugins/longos-bot-shield/health', 'POST', [
                'ipinfo_token' => ['not-a-scalar'],
            ]),
            $client,
            app(BotRiskEngine::class),
            app(BotShieldSettings::class),
            app(IpInfoLocalDatabase::class),
            app(MainSiteHomepage::class),
        );
        self::assertSame(422, $malformed->getStatusCode());
        self::assertSame('invalid_token', $malformed->getData(true)['data']['status']);
        Http::assertSentCount(2);
    }

    public function test_ipinfo_health_check_reports_the_current_ip_decision_and_warms_its_cache(): void
    {
        config([
            'bk.plugin.longos_bot_shield.blocked_asns'             => 'AS64496, AS64496',
            'bk.plugin.longos_bot_shield.organization_keywords'    => 'test node',
            'bk.plugin.longos_bot_shield.vendor_allowlist_enabled' => true,
            'bk.plugin.longos_bot_shield.allowed_asns'             => 'AS65000',
        ]);
        Http::fake(static function (ClientRequest $request) {
            $ip = rawurldecode((string) basename(parse_url($request->url(), PHP_URL_PATH)));

            return Http::response([
                'ip'        => $ip,
                'asn'       => 'AS64496',
                'as_name'   => 'Test Node Lab',
                'as_domain' => 'node.example',
            ], 200);
        });

        $request = Request::create(
            '/admin/plugins/longos-bot-shield/health',
            'POST',
            ['ipinfo_token' => ''],
            [],
            [],
            [
                'REMOTE_ADDR'     => '8.8.4.4',
                'HTTP_USER_AGENT' => 'Mozilla/5.0',
            ],
        );
        $client   = app(IpInfoClient::class);
        $database = $this->createMock(IpInfoLocalDatabase::class);
        $database->expects(self::once())
            ->method('syncIfStale')
            ->with('ipinfo-test-token')
            ->willReturn([
                'ready'     => true,
                'status'    => 'downloaded',
                'age_hours' => 0,
                'size_mb'   => 24.93,
            ]);
        $homepage = $this->createMock(MainSiteHomepage::class);
        $homepage->expects(self::once())
            ->method('render')
            ->willReturn(['html' => '<html><head></head></html>', 'source' => 'control']);
        $response = (new PluginController)->botShieldHealth(
            $request,
            $client,
            app(BotRiskEngine::class),
            app(BotShieldSettings::class),
            $database,
            $homepage,
        );
        $data = $response->getData(true)['data'];

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('8.8.4.4', $data['ip']);
        self::assertSame('AS64496', $data['asn']);
        self::assertSame('Test Node Lab', $data['name']);
        self::assertTrue($data['shielded']);
        self::assertContains('asn_denylist', $data['reasons']);
        self::assertContains('organization_keyword', $data['reasons']);
        self::assertTrue($data['database']['ready']);
        self::assertTrue($data['homepage']['ready']);
        self::assertSame('direct', $data['visitor_ip_source']);

        self::assertSame('ok', $client->lookup('8.8.4.4')['status']);
        Http::assertSentCount(1);
    }

    public function test_health_remains_operational_when_live_api_fails_but_local_data_and_homepage_are_ready(): void
    {
        $request = $this->browserRequest('8.8.8.8', '/admin/plugins/longos-bot-shield/health');
        $ipInfo  = $this->createMock(IpInfoClient::class);
        $ipInfo->expects(self::once())
            ->method('probe')
            ->willReturn([
                'healthy'    => false,
                'status'     => 'unavailable',
                'provider'   => 'ipinfo',
                'ip'         => '8.8.8.8',
                'latency_ms' => 3000,
            ]);
        $database = $this->createMock(IpInfoLocalDatabase::class);
        $database->expects(self::once())
            ->method('syncIfStale')
            ->willReturn(['ready' => true, 'status' => 'fresh', 'age_hours' => 2, 'size_mb' => 25.92]);
        $database->expects(self::once())
            ->method('lookup')
            ->with('8.8.8.8')
            ->willReturn([
                'status'   => 'ok',
                'provider' => 'ipinfo-local',
                'asn'      => 'AS32934',
                'name'     => 'Meta Platforms, Inc.',
                'domain'   => 'meta.com',
            ]);
        $homepage = $this->createMock(MainSiteHomepage::class);
        $homepage->expects(self::once())
            ->method('render')
            ->willReturn(['html' => '<html><head></head></html>', 'source' => 'control-cache']);

        $response = (new PluginController)->botShieldHealth(
            $request,
            $ipInfo,
            app(BotRiskEngine::class),
            app(BotShieldSettings::class),
            $database,
            $homepage,
        );
        $data = $response->getData(true)['data'];

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['operational']);
        self::assertTrue($data['shielded']);
        self::assertSame('AS32934', $data['asn']);
        self::assertContains('asn_denylist', $data['reasons']);
    }

    public function test_ipinfo_auth_failure_opens_a_token_scoped_circuit_and_rotation_recovers(): void
    {
        config(['bk.plugin.longos_bot_shield.ipinfo_token' => 'bad-token']);
        Http::fake(static function (ClientRequest $request) {
            if ($request->hasHeader('Authorization', 'Bearer bad-token')) {
                return Http::response(['error' => ['title' => 'Unauthorized']], 401);
            }

            $ip = rawurldecode((string) basename(parse_url($request->url(), PHP_URL_PATH)));

            return Http::response([
                'ip'        => $ip,
                'asn'       => 'AS65001',
                'as_name'   => 'Residential Example',
                'as_domain' => 'residential.example',
            ], 200);
        });

        $client = app(IpInfoClient::class);
        self::assertSame('unavailable', $client->lookup('8.8.8.8')['status']);
        self::assertSame('unavailable', $client->lookup('1.1.1.1')['status']);
        Http::assertSentCount(1);

        config(['bk.plugin.longos_bot_shield.ipinfo_token' => 'rotated-token']);
        self::assertSame('ok', $client->lookup('9.9.9.9')['status']);
        Http::assertSentCount(2);
    }

    public function test_successful_health_probe_closes_the_saved_token_circuit(): void
    {
        config(['bk.plugin.longos_bot_shield.ipinfo_token' => 'recover-token']);
        $attempt = 0;
        Http::fake(static function (ClientRequest $request) use (&$attempt) {
            $attempt++;
            if ($attempt === 1) {
                return Http::response(['error' => ['title' => 'Unauthorized']], 401);
            }
            $ip = rawurldecode((string) basename(parse_url($request->url(), PHP_URL_PATH)));

            return Http::response([
                'ip'        => $ip,
                'asn'       => 'AS64496',
                'as_name'   => 'Test Node Lab',
                'as_domain' => 'node.example',
            ], 200);
        });

        $client = app(IpInfoClient::class);
        self::assertSame('unavailable', $client->lookup('8.8.8.8')['status']);
        self::assertSame('unavailable', $client->lookup('1.1.1.1')['status']);
        Http::assertSentCount(1);

        self::assertTrue($client->probe(null, '1.1.1.1')['healthy']);
        self::assertSame('ok', $client->lookup('9.9.9.9')['status']);
        Http::assertSentCount(3);
    }

    public function test_provider_budget_caps_uncached_ipinfo_requests_without_blocking_the_next_visitor(): void
    {
        config(['bk.plugin.longos_bot_shield.provider_requests_per_minute' => 10]);
        Http::fake(static function (ClientRequest $request) {
            $ip = rawurldecode((string) basename(parse_url($request->url(), PHP_URL_PATH)));

            return Http::response([
                'ip'        => $ip,
                'asn'       => 'AS65001',
                'as_name'   => 'Residential Example',
                'as_domain' => 'residential.example',
            ], 200);
        });

        $client = app(IpInfoClient::class);
        foreach (range(1, 10) as $octet) {
            self::assertSame('ok', $client->lookup("8.8.4.{$octet}")['status']);
        }
        self::assertSame('unavailable', $client->lookup('8.8.4.11')['status']);
        Http::assertSentCount(10);
    }

    public function test_network_singleflight_contention_never_starts_a_second_provider_request(): void
    {
        Http::fake();
        $network = app(NetworkIntelligenceClient::class);
        $method  = new \ReflectionMethod($network, 'refreshKey');
        $key     = $method->invoke($network, '8.8.8.8');
        Cache::put($key, true, now()->addSeconds(15));

        self::assertSame('unavailable', $network->lookup('8.8.8.8')['status']);
        Http::assertNothingSent();
    }

    public function test_ipinfo_rejects_mismatched_payload_and_fails_open(): void
    {
        Http::fake(['api.ipinfo.io/*' => Http::response([
            'ip'      => '1.1.1.1',
            'asn'     => 'AS15169',
            'as_name' => 'Google LLC',
        ], 200)]);

        $result = app(IpInfoClient::class)->lookup('8.8.8.8');
        self::assertSame('unavailable', $result['status']);

        $assessment = app(BotRiskEngine::class)->assess($this->browserRequest('8.8.8.8'));
        self::assertFalse($assessment->matchesConfiguredShieldingScope());
    }

    public function test_ipinfo_has_a_bounded_no_retry_conversion_budget(): void
    {
        $reflection = new \ReflectionClass(IpInfoClient::class);
        self::assertSame(0.3, $reflection->getConstant('CONNECT_TIMEOUT_SECONDS'));
        self::assertSame(0.8, $reflection->getConstant('REQUEST_TIMEOUT_SECONDS'));
        self::assertSame(1.0, $reflection->getConstant('PROBE_CONNECT_TIMEOUT_SECONDS'));
        self::assertSame(3.0, $reflection->getConstant('PROBE_TIMEOUT_SECONDS'));
        self::assertStringNotContainsString(
            '->retry(',
            (string) file_get_contents(base_path('plugins/LongosBotShield/Services/IpInfoClient.php')),
        );
        self::assertSame(1.0, MainSiteHomepage::CONNECT_TIMEOUT_SECONDS);
        self::assertSame(5.0, MainSiteHomepage::REQUEST_TIMEOUT_SECONDS);
    }

    public function test_local_dataset_uses_streamed_indexed_mmdb_instead_of_loading_csv(): void
    {
        $source = (string) file_get_contents(base_path(
            'plugins/LongosBotShield/Services/IpInfoLocalDatabase.php',
        ));

        self::assertStringContainsString('ipinfo_lite.mmdb', $source);
        self::assertStringContainsString("'sink'", $source);
        self::assertStringContainsString('MaxMind\\Db\\Reader', $source);
        self::assertStringContainsString('@rename', $source);
        self::assertStringContainsString('pruneAbandonedTemporaryFiles', $source);
        self::assertStringNotContainsString('ipinfo_lite.csv', $source);
        self::assertStringNotContainsString('->body()', $source);
        self::assertStringNotContainsString('.backup', $source);
    }

    public function test_local_ipinfo_database_resolves_platform_asn_without_an_api_request(): void
    {
        $database = $this->createMock(IpInfoLocalDatabase::class);
        $database->expects(self::once())
            ->method('lookup')
            ->with('8.8.8.8')
            ->willReturn([
                'status'                => 'ok',
                'provider'              => 'ipinfo-local',
                'security_data_present' => false,
                'asn'                   => 'AS32934',
                'name'                  => 'Meta Platforms, Inc.',
                'domain'                => 'meta.com',
            ]);
        $database->expects(self::once())->method('scheduleSyncIfStale');
        Http::fake();

        $settings = app(BotShieldSettings::class);
        $network  = new NetworkIntelligenceClient($database, app(IpInfoClient::class), $settings);
        $result   = $network->lookup('8.8.8.8');

        self::assertSame('ipinfo-local', $result['provider']);
        self::assertSame('AS32934', $result['asn']);
        self::assertContains('AS32934', $settings->blockedAsns());
        Http::assertNothingSent();
    }

    public function test_incomplete_local_identity_uses_the_bounded_live_api_fallback(): void
    {
        $database = $this->createMock(IpInfoLocalDatabase::class);
        $database->expects(self::once())
            ->method('lookup')
            ->willReturn([
                'status'              => 'ok',
                'provider'            => 'ipinfo-local',
                'identity_incomplete' => true,
                'asn'                 => 'AS32934',
                'name'                => '',
                'domain'              => '',
            ]);
        $database->expects(self::once())->method('scheduleSyncIfStale');
        Http::fake(['api.ipinfo.io/*' => Http::response([
            'ip'        => '8.8.4.4',
            'asn'       => 'AS32934',
            'as_name'   => 'Meta Platforms, Inc.',
            'as_domain' => 'meta.com',
        ], 200)]);

        $network = new NetworkIntelligenceClient(
            $database,
            app(IpInfoClient::class),
            app(BotShieldSettings::class),
        );
        $result = $network->lookup('8.8.4.4');

        self::assertSame('ok', $result['status']);
        self::assertSame('AS32934', $result['asn']);
        Http::assertSentCount(1);
    }

    public function test_failed_identity_enrichment_keeps_the_local_asn_match(): void
    {
        $local = [
            'status'              => 'ok',
            'provider'            => 'ipinfo-local',
            'identity_incomplete' => true,
            'asn'                 => 'AS32934',
            'name'                => '',
            'domain'              => '',
        ];
        $database = $this->createMock(IpInfoLocalDatabase::class);
        $database->expects(self::once())->method('lookup')->willReturn($local);
        $database->expects(self::once())->method('scheduleSyncIfStale');
        Http::fake(['api.ipinfo.io/*' => Http::response([], 503)]);

        $network = new NetworkIntelligenceClient(
            $database,
            app(IpInfoClient::class),
            app(BotShieldSettings::class),
        );
        $result = $network->lookup('8.8.4.4');

        self::assertSame('ok', $result['status']);
        self::assertSame('ipinfo-local', $result['provider']);
        self::assertSame('AS32934', $result['asn']);
        Http::assertSentCount(1);
    }

    public function test_platform_asn_from_ipinfo_lite_enters_the_shielding_scope(): void
    {
        $cases = [
            '8.8.8.8'        => ['AS32934', 'Facebook, Inc.'],
            '1.1.1.1'        => ['AS138699', 'TikTok Pte. Ltd.'],
            '9.9.9.9'        => ['AS16509', 'Amazon.com, Inc.'],
            '208.67.222.222' => ['AS396982', 'Google Cloud Platform'],
        ];
        Http::fake(static function (ClientRequest $request) use ($cases) {
            $ip           = rawurldecode((string) basename(parse_url($request->url(), PHP_URL_PATH)));
            [$asn, $name] = $cases[$ip];

            return Http::response([
                'ip'        => $ip,
                'asn'       => $asn,
                'as_name'   => $name,
                'as_domain' => 'example.test',
            ], 200);
        });

        foreach ($cases as $ip => [$asn]) {
            $assessment = app(BotRiskEngine::class)->assess($this->browserRequest($ip));
            self::assertContains('asn_denylist', $assessment->reasons, $asn);
            self::assertTrue($assessment->matchesConfiguredShieldingScope(), $asn);
        }
    }

    public function test_platform_looking_as_domain_never_blocks_an_unrelated_as_name(): void
    {
        Http::fake(['api.ipinfo.io/*' => Http::response([
            'ip'             => '8.8.4.4',
            'asn'            => 'AS268543',
            'as_name'        => 'S R P Ramon Telecomunicacoes',
            'as_domain'      => 'facebook.com',
            'country_code'   => 'BR',
            'continent_code' => 'SA',
        ], 200)]);

        $assessment = app(BotRiskEngine::class)->assess($this->browserRequest('8.8.4.4'));

        self::assertFalse($assessment->matchesConfiguredShieldingScope());
        self::assertNotContains('organization_keyword', $assessment->reasons);
        self::assertNotContains('asn_denylist', $assessment->reasons);
    }

    public function test_china_region_switch_uses_only_verified_cloudflare_country_context(): void
    {
        config(['bk.plugin.longos_bot_shield.block_china_regions' => true]);
        Http::fake();

        foreach (['CN', 'HK', 'MO'] as $countryCode) {
            $request = $this->browserRequest('1.1.1.1');
            $request->attributes->set(CloudflareIpRangeService::REQUEST_ATTRIBUTE, true);
            $request->attributes->set(CloudflareIpRangeService::VISITOR_CONTEXT_ATTRIBUTE, [
                'country_code' => $countryCode,
            ]);

            $assessment = app(BotRiskEngine::class)->assess($request);
            self::assertContains('country_region_denylist', $assessment->reasons, $countryCode);
            self::assertTrue($assessment->matchesConfiguredShieldingScope(), $countryCode);
        }

        $forged = $this->browserRequest('1.1.1.1');
        $forged->headers->set('CF-IPCountry', 'CN');
        $assessment = app(BotRiskEngine::class)->assessWithNetwork($forged, [
            'status'       => 'ok',
            'country_code' => 'US',
        ]);
        self::assertNotContains('country_region_denylist', $assessment->reasons);

        $verifiedOutsideRegion = $this->browserRequest('1.1.1.1');
        $verifiedOutsideRegion->attributes->set(CloudflareIpRangeService::REQUEST_ATTRIBUTE, true);
        $verifiedOutsideRegion->attributes->set(CloudflareIpRangeService::VISITOR_CONTEXT_ATTRIBUTE, [
            'country_code' => 'US',
        ]);
        $assessment = app(BotRiskEngine::class)->assessWithNetwork($verifiedOutsideRegion, [
            'status'       => 'ok',
            'country_code' => 'HK',
        ]);
        self::assertNotContains('country_region_denylist', $assessment->reasons);
        Http::assertNothingSent();
    }

    public function test_china_region_switch_falls_back_to_local_ipinfo_country_and_defaults_off(): void
    {
        $network = [
            'status'       => 'ok',
            'country_code' => 'HK',
        ];

        $disabled = app(BotRiskEngine::class)->assessWithNetwork($this->browserRequest('1.1.1.1'), $network);
        self::assertNotContains('country_region_denylist', $disabled->reasons);

        config(['bk.plugin.longos_bot_shield.block_china_regions' => true]);
        $enabled = app(BotRiskEngine::class)->assessWithNetwork($this->browserRequest('1.1.1.1'), $network);
        self::assertContains('country_region_denylist', $enabled->reasons);
        self::assertTrue($enabled->matchesConfiguredShieldingScope());
    }

    public function test_crawler_headless_and_script_clients_are_local_and_skip_ipinfo(): void
    {
        Http::fake();
        $cases = [
            'facebookexternalhit/1.1'                              => 'crawler_declared',
            'TikTokSpider/1.0'                                     => 'crawler_declared',
            'Mozilla/5.0 HeadlessChrome/124.0 Playwright'          => 'ua_automation',
            'python-requests/2.32'                                 => 'ua_script_client',
            'Mozilla/5.0 Chrome/124.0 Safari/537.36 genericbot/1'  => 'ua_generic_crawler',
        ];

        foreach ($cases as $userAgent => $reason) {
            $assessment = app(BotRiskEngine::class)->assess($this->request('8.8.8.8', $userAgent));
            self::assertContains($reason, $assessment->reasons, $userAgent);
            self::assertTrue($assessment->matchesConfiguredShieldingScope(), $userAgent);
        }
        Http::assertNothingSent();
    }

    public function test_explicit_automation_headers_are_local_scope_matches(): void
    {
        Http::fake();
        $request = $this->browserRequest('1.1.1.1');
        $request->headers->set('X-Playwright', '1');

        $assessment = app(BotRiskEngine::class)->assess($request);
        self::assertContains('automation_header', $assessment->reasons);
        Http::assertNothingSent();
    }

    public function test_social_in_app_browser_is_not_mistaken_for_a_preview_crawler(): void
    {
        Http::fake(['api.ipinfo.io/*' => Http::response([
            'ip'        => '8.8.4.4',
            'asn'       => 'AS65001',
            'as_name'   => 'Residential Example',
            'as_domain' => 'residential.example',
        ], 200)]);
        $request = $this->request(
            '8.8.4.4',
            'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/124.0 Mobile Safari/537.36 [FBAN/FB4A;FBAV/500.0]',
        );

        $assessment = app(BotRiskEngine::class)->assess($request);
        self::assertFalse($assessment->matchesConfiguredShieldingScope());
        self::assertNotContains('crawler_declared', $assessment->reasons);
    }

    public function test_explicit_vendor_asn_allowlist_has_priority_over_local_automation_signals(): void
    {
        config([
            'bk.plugin.longos_bot_shield.block_china_regions'      => true,
            'bk.plugin.longos_bot_shield.vendor_allowlist_enabled' => true,
            'bk.plugin.longos_bot_shield.allowed_asns'             => '15169',
        ]);
        Http::fake(['api.ipinfo.io/*' => Http::response([
            'ip'        => '8.8.8.8',
            'asn'       => 'AS15169',
            'as_name'   => 'Google LLC',
            'as_domain' => 'google.com',
        ], 200)]);

        $request = $this->request(
            '8.8.8.8',
            'Mozilla/5.0 HeadlessChrome/124.0 Playwright',
        );
        $request->attributes->set(CloudflareIpRangeService::REQUEST_ATTRIBUTE, true);
        $request->attributes->set(CloudflareIpRangeService::VISITOR_CONTEXT_ATTRIBUTE, [
            'country_code' => 'CN',
        ]);

        $assessment = app(BotRiskEngine::class)->assess($request);
        self::assertTrue($assessment->allowlisted);
        self::assertSame(['vendor_allowlist_asn'], $assessment->reasons);
    }

    public function test_normal_browser_and_checkout_continue_when_scope_does_not_match(): void
    {
        $engine     = $this->engineReturning(new RiskAssessment([]));
        $internal   = app(InternalHomepageRequest::class);
        $middleware = new ProtectStorefront(
            $engine,
            app(BotShieldSettings::class),
            $internal,
            new class($internal) extends MainSiteHomepage
            {
                public function renderFor(Request $request): ?array
                {
                    throw new \RuntimeException('Unmatched shopping traffic must not fetch the control homepage.');
                }
            },
        );
        foreach (['/products/1', '/cart', '/checkout', '/stripe/capture', '/paypal/capture'] as $path) {
            $request = Request::create('https://store.example.test' . $path, $path === '/products/1' ? 'GET' : 'POST', [], [], [], [
                'REMOTE_ADDR'     => '1.1.1.1',
                'HTTP_USER_AGENT' => $this->chromeUserAgent(),
                'HTTP_ACCEPT'     => 'application/json',
            ]);
            app()->instance('request', $request);
            $response = $middleware->handle($request, static fn (): Response => new Response('NORMAL SHOPPING'));
            self::assertSame('NORMAL SHOPPING', $response->getContent(), $path);
            self::assertNull($response->headers->get('X-Longos-Bot-Shield'));
        }
    }

    public function test_scope_match_always_serves_homepage_at_the_same_url(): void
    {
        $internal   = app(InternalHomepageRequest::class);
        $middleware = new ProtectStorefront(
            $this->engineReturning(new RiskAssessment(['asn_denylist'])),
            app(BotShieldSettings::class),
            $internal,
            new class($internal) extends MainSiteHomepage
            {
                public function renderFor(Request $request): ?array
                {
                    return ['html' => '<!doctype html><title>LONGOS Main</title>', 'source' => 'control'];
                }
            },
        );
        $request = $this->request('8.8.8.8', 'facebookexternalhit/1.1', '/products/private');
        app()->instance('request', $request);

        $response = $middleware->handle($request, static fn (): Response => new Response('MUST NOT RUN'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('LONGOS Main', (string) $response->getContent());
        self::assertSame('homepage', $response->headers->get('X-Longos-Bot-Shield'));
        self::assertSame('control', $response->headers->get('X-Longos-Bot-Homepage-Source'));
        self::assertSame('/products/private', $request->getPathInfo());
        self::assertFalse($response->isRedirect());
        self::assertSame("sandbox; frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_shielded_document_hydrates_lazy_media_and_keeps_navigation_on_visitor_origin(): void
    {
        $presenter = new ShieldedDocumentPresenter;
        $canonical = $presenter->canonicalize(
            '<html><head><meta http-equiv="refresh" content="0;url=/elsewhere"></head><body>'
            . '<a href="/products/42">Product</a>'
            . '<a href="https://main.example.test/categories/7">Category</a>'
            . '<a href="https://external.example/path">External</a>'
            . '<img class="image-before lazyload" src="data:image/gif;base64,placeholder" data-src="/image/product.jpg" data-srcset="/image/product-2x.jpg 2x">'
            . '</body></html>',
            'https://main.example.test/',
        );
        $request = Request::create('https://store.example.test/original/path', 'GET');
        $html    = $presenter->forVisitor(
            $canonical,
            'https://main.example.test/',
            'https://main.example.test/',
            $request,
        );

        self::assertStringContainsString('<base data-longos-bot-shield="1" href="https://main.example.test/">', $html);
        self::assertStringNotContainsString('http-equiv="refresh"', $html);
        self::assertStringContainsString('src="/image/product.jpg"', $html);
        self::assertStringContainsString('srcset="/image/product-2x.jpg 2x"', $html);
        self::assertStringContainsString('class="image-before lazyloaded"', $html);
        self::assertStringContainsString('href="https://store.example.test/products/42"', $html);
        self::assertStringContainsString('href="https://store.example.test/categories/7"', $html);
        self::assertStringContainsString('href="https://external.example/path"', $html);
    }

    public function test_shielded_browser_can_follow_a_deep_control_document_without_changing_origin(): void
    {
        config(['saas.enabled' => true]);
        $child     = new Site(['status' => Site::STATUS_ACTIVE, 'meta' => ['control_site' => false]]);
        $child->id = 22;
        app()->instance((string) config('multitenancy.current_tenant_container_key'), $child);

        $control     = new Site(['status' => Site::STATUS_ACTIVE, 'meta' => ['control_site' => true]]);
        $control->id = 1;
        $control->setRelation('domains', collect([new Domain([
            'host'             => 'main.example.test',
            'scheme'           => 'https',
            'is_primary'       => true,
            'routing_behavior' => Domain::ROUTING_REDIRECT,
            'status'           => Domain::STATUS_ACTIVE,
        ])]));

        Http::fake([
            'https://main.example.test/products/42?page=2' => Http::response(
                '<html><head></head><body><a href="/categories/7">Category</a><img data-src="/image/42.jpg"></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
            'https://main.example.test/' => Http::response(
                '<html><head></head><body>SAFE ROOT</body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
            'https://main.example.test/products/404' => Http::response('missing', 404, ['Content-Type' => 'text/html']),
        ]);
        $internal = app(InternalHomepageRequest::class);
        $service  = new class($internal, $control) extends MainSiteHomepage
        {
            public function __construct(InternalHomepageRequest $internal, private readonly Site $site)
            {
                parent::__construct($internal);
            }

            protected function controlSite(): ?Site
            {
                return $this->site;
            }
        };
        $request  = Request::create('https://store.example.test/products/42?page=2&tracking=ignored', 'GET', [], [], [], [
            'HTTP_ACCEPT' => 'text/html',
        ]);
        $document = $service->renderFor($request);

        self::assertNotNull($document);
        self::assertSame('control', $document['source']);
        self::assertStringContainsString('href="https://store.example.test/categories/7"', $document['html']);
        self::assertStringContainsString('src="/image/42.jpg"', $document['html']);
        self::assertStringContainsString('<base data-longos-bot-shield="1" href="https://main.example.test/">', $document['html']);

        $privateRequest = Request::create('https://store.example.test/checkout', 'GET', [], [], [], [
            'HTTP_ACCEPT' => 'text/html',
        ]);
        $privateDocument = $service->renderFor($privateRequest);
        self::assertNotNull($privateDocument);
        self::assertStringContainsString('SAFE ROOT', $privateDocument['html']);

        $missingRequest = Request::create('https://store.example.test/products/404', 'GET', [], [], [], [
            'HTTP_ACCEPT' => 'text/html',
        ]);
        self::assertStringContainsString('SAFE ROOT', (string) $service->renderFor($missingRequest)['html']);
        self::assertStringContainsString('SAFE ROOT', (string) $service->renderFor($missingRequest)['html']);
        Http::assertSent(function (ClientRequest $outgoing) use ($internal): bool {
            $verification = Request::create($outgoing->url(), 'GET');
            $verification->headers->set(
                InternalHomepageRequest::HEADER,
                $outgoing->header(InternalHomepageRequest::HEADER)[0] ?? '',
            );

            return $outgoing->url() === 'https://main.example.test/products/42?page=2'
                && $internal->valid($verification)
                && ! $outgoing->hasHeader('Cookie');
        });
        Http::assertSent(static fn (ClientRequest $outgoing): bool => $outgoing->url() === 'https://main.example.test/');
        self::assertCount(1, collect(Http::recorded())->filter(
            static fn (array $exchange): bool => $exchange[0]->url() === 'https://main.example.test/products/404',
        ));
    }

    public function test_presentation_assets_bypass_shielding_without_network_assessment(): void
    {
        $engine = new class(app(BotShieldSettings::class), app(NetworkIntelligenceClient::class)) extends BotRiskEngine
        {
            public int $calls = 0;

            public function assess(Request $request): RiskAssessment
            {
                $this->calls++;

                return new RiskAssessment(['asn_denylist']);
            }
        };
        $internal   = app(InternalHomepageRequest::class);
        $middleware = new ProtectStorefront($engine, app(BotShieldSettings::class), $internal, app(MainSiteHomepage::class));

        foreach (['/plugin/longos_product_reviews/cards.css', '/image/catalog/item.webp', '/fonts/store.woff2'] as $path) {
            $request  = Request::create('https://store.example.test' . $path, 'GET');
            $response = $middleware->handle($request, static fn (): Response => new Response('ASSET'));
            self::assertSame('ASSET', $response->getContent());
        }
        self::assertSame(0, $engine->calls);
    }

    public function test_scope_match_replaces_a_structured_mutating_request_with_the_homepage(): void
    {
        $internal   = app(InternalHomepageRequest::class);
        $middleware = new ProtectStorefront(
            $this->engineReturning(new RiskAssessment(['ua_script_client'])),
            app(BotShieldSettings::class),
            $internal,
            new class($internal) extends MainSiteHomepage
            {
                public function renderFor(Request $request): ?array
                {
                    return ['html' => '<title>CONTROL HOME</title>', 'source' => 'control-cache'];
                }
            },
        );
        $request = Request::create(
            'https://store.example.test/checkout/confirm',
            'POST',
            [],
            [],
            [],
            [
                'REMOTE_ADDR'     => '8.8.8.8',
                'HTTP_USER_AGENT' => 'python-requests/2.32',
                'HTTP_ACCEPT'     => 'application/json',
            ],
        );
        app()->instance('request', $request);

        $response = $middleware->handle($request, static fn (): Response => new Response('MUST NOT RUN'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('homepage', $response->headers->get('X-Longos-Bot-Shield'));
        self::assertStringContainsString('CONTROL HOME', (string) $response->getContent());
    }

    public function test_scope_match_returns_temporary_unavailable_when_no_homepage_exists(): void
    {
        app('view')->addNamespace('LongosBotShield', base_path('plugins/LongosBotShield/Views'));
        app('translator')->addNamespace('LongosBotShield', base_path('plugins/LongosBotShield/Lang'));
        $internal   = app(InternalHomepageRequest::class);
        $middleware = new ProtectStorefront(
            $this->engineReturning(new RiskAssessment(['ua_automation'])),
            app(BotShieldSettings::class),
            $internal,
            new class($internal) extends MainSiteHomepage
            {
                public function renderFor(Request $request): ?array
                {
                    return null;
                }
            },
        );
        $request = $this->request('8.8.8.8', 'HeadlessChrome Playwright');
        app()->instance('request', $request);

        $response = $middleware->handle($request, static fn (): Response => new Response('MUST NOT RUN'));
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('unavailable', $response->headers->get('X-Longos-Bot-Shield'));
        self::assertSame('5', $response->headers->get('Retry-After'));
    }

    public function test_payment_provider_continuations_bypass_scope_but_capture_does_not(): void
    {
        $engine = new class(app(BotShieldSettings::class), app(NetworkIntelligenceClient::class)) extends BotRiskEngine
        {
            public int $calls = 0;

            public function assess(Request $request): RiskAssessment
            {
                $this->calls++;

                return new RiskAssessment(['ua_script_client']);
            }
        };
        $internal   = app(InternalHomepageRequest::class);
        $middleware = new ProtectStorefront(
            $engine,
            app(BotShieldSettings::class),
            $internal,
            new class($internal) extends MainSiteHomepage
            {
                public function renderFor(Request $request): ?array
                {
                    return ['html' => '<title>MAIN</title>', 'source' => 'control'];
                }
            },
        );
        foreach ([
            ['POST', '/callback/stripe'], ['POST', '/paypal/nvp/notify'],
            ['POST', '/paypal/nvp/return'], ['GET', '/paypal/rest/return'],
        ] as [$method, $path]) {
            $request = Request::create('https://store.example.test' . $path, $method);
            app()->instance('request', $request);
            self::assertSame(
                'PAYMENT CONTINUATION',
                $middleware->handle($request, static fn (): Response => new Response('PAYMENT CONTINUATION'))->getContent(),
                $path,
            );
        }
        self::assertSame(0, $engine->calls);

        $capture = Request::create('https://store.example.test/stripe/capture', 'POST');
        app()->instance('request', $capture);
        $response = $middleware->handle($capture, static fn (): Response => new Response('MUST NOT RUN'));
        self::assertSame('homepage', $response->headers->get('X-Longos-Bot-Shield'));
        self::assertSame(1, $engine->calls);
    }

    public function test_control_homepage_is_cached_and_never_receives_visitor_state(): void
    {
        config(['saas.enabled' => true]);
        $child     = new Site(['status' => Site::STATUS_ACTIVE, 'meta' => ['control_site' => false]]);
        $child->id = 22;
        app()->instance((string) config('multitenancy.current_tenant_container_key'), $child);

        $control     = new Site(['status' => Site::STATUS_ACTIVE, 'meta' => ['control_site' => true]]);
        $control->id = 1;
        $control->setRelation('domains', collect([new Domain([
            'host'             => 'main.example.test',
            'scheme'           => 'https',
            'is_primary'       => true,
            'routing_behavior' => Domain::ROUTING_REDIRECT,
            'status'           => Domain::STATUS_ACTIVE,
        ])]));

        Http::fake(['https://main.example.test/' => Http::response(
            '<!doctype html><html><head><title>Control</title></head></html>',
            200,
            ['Content-Type' => 'text/html'],
        )]);
        $internal = app(InternalHomepageRequest::class);
        $service  = new class($internal, $control) extends MainSiteHomepage
        {
            public int $lookups = 0;

            public function __construct(InternalHomepageRequest $internal, private readonly Site $site)
            {
                parent::__construct($internal);
            }

            protected function controlSite(): ?Site
            {
                $this->lookups++;

                return $this->site;
            }
        };

        $first  = $service->render();
        $cached = $service->render();
        self::assertSame('control', $first['source']);
        self::assertSame('control-cache', $cached['source']);
        self::assertSame('ready_cache', $service->lastStatus());
        self::assertSame(1, $service->lookups);
        self::assertStringContainsString(
            '<base data-longos-bot-shield="1" href="https://main.example.test/">',
            $first['html'],
        );
        Http::assertSent(function (ClientRequest $request) use ($internal): bool {
            $verification = Request::create($request->url(), 'GET');
            $verification->headers->set(
                InternalHomepageRequest::HEADER,
                $request->header(InternalHomepageRequest::HEADER)[0] ?? '',
            );

            return ! $request->hasHeader('Cookie')
                && ! $request->hasHeader('Authorization')
                && $internal->valid($verification);
        });
        Http::assertSentCount(1);
    }

    public function test_current_control_site_prepares_layout_data_before_direct_homepage_render(): void
    {
        config(['saas.enabled' => false]);
        $visitor = Request::create(
            'https://main.example.test/products/private?iframe=1&design=1',
            'POST',
            ['secret'          => 'visitor-body'],
            ['visitor_session' => 'must-not-be-forwarded'],
        );
        app()->instance('request', $visitor);
        app('url')->setRequest($visitor);
        $shareData = new class extends ShareViewData
        {
            public int $calls = 0;

            public function loadShopShareViewData(): void
            {
                $this->calls++;
                view()->share('shop_base_url', 'https://main.example.test/');
            }
        };
        app()->instance(ShareViewData::class, $shareData);
        app()->instance(HomeController::class, new class extends HomeController
        {
            public function index(): mixed
            {
                return new class
                {
                    public function render(): string
                    {
                        return '<html><head></head><body>'
                            . e((string) view()->shared('shop_base_url')) . '|'
                            . e(request()->getMethod()) . '|'
                            . e(request()->getPathInfo()) . '|'
                            . e((string) request('iframe')) . '|'
                            . e((string) request()->cookie('visitor_session')) . '|'
                            . e((string) request('secret'))
                            . '</body></html>';
                    }
                };
            }
        });

        $homepage = app(MainSiteHomepage::class)->render();
        self::assertSame(1, $shareData->calls);
        self::assertSame('current', $homepage['source']);
        self::assertStringContainsString('https://main.example.test/|GET|/|||', $homepage['html']);
        self::assertSame('/products/private', request()->getPathInfo());
        self::assertSame('1', request('iframe'));

        $cached = app(MainSiteHomepage::class)->render();
        self::assertSame('current-cache', $cached['source']);
        self::assertSame($homepage['html'], $cached['html']);
        self::assertSame(1, $shareData->calls);
    }

    public function test_internal_homepage_signature_is_host_bound_and_short_lived(): void
    {
        $signature = app(InternalHomepageRequest::class);
        $timestamp = time();
        $header    = $signature->headerValue('main.example.test', $timestamp, '/');

        $valid = Request::create('https://main.example.test/', 'GET');
        $valid->headers->set(InternalHomepageRequest::HEADER, $header);
        self::assertTrue($signature->valid($valid));

        $wrongHost = Request::create('https://other.example.test/', 'GET');
        $wrongHost->headers->set(InternalHomepageRequest::HEADER, $header);
        self::assertFalse($signature->valid($wrongHost));

        $expired = Request::create('https://main.example.test/', 'GET');
        $expired->headers->set(
            InternalHomepageRequest::HEADER,
            $signature->headerValue('main.example.test', $timestamp - 31, '/'),
        );
        self::assertFalse($signature->valid($expired));
    }

    public function test_middleware_registration_and_removed_radar_configuration_are_consistent(): void
    {
        $provider    = new PluginServiceProvider(app());
        $middlewares = Closure::bind(function (): array {
            $this->pluginBasePath = base_path('plugins');

            return $this->loadMiddlewares(base_path('plugins/LongosBotShield/Middleware/Shop'));
        }, $provider, PluginServiceProvider::class)();
        self::assertContains(ProtectStorefront::class, $middlewares);

        foreach (['.env.example', 'docker-compose.yml', 'config/longos.php', 'docs/COOLIFY.md', 'docs/CONFIGURATION.md'] as $file) {
            self::assertStringNotContainsString(
                'CLOUDFLARE_RADAR_API_TOKEN',
                (string) file_get_contents(base_path($file)),
                $file,
            );
        }
        self::assertFileDoesNotExist(base_path('plugins/LongosBotShield/Services/CloudflareRadarClient.php'));
        self::assertFileExists(base_path('plugins/LongosBotShield/Console/SyncIpInfoDatabase.php'));
        self::assertStringContainsString(
            'longos:bot-shield:sync-ipinfo',
            (string) file_get_contents(base_path('app/Console/Kernel.php')),
        );
    }

    public function test_allow_and_deny_cidrs_remain_deterministic_and_local(): void
    {
        config([
            'bk.plugin.longos_bot_shield.allow_cidrs' => '8.8.8.0/24',
            'bk.plugin.longos_bot_shield.deny_cidrs'  => '1.1.1.0/24',
        ]);
        Http::fake();

        $allowed = app(BotRiskEngine::class)->assess($this->browserRequest('8.8.8.8'));
        self::assertTrue($allowed->allowlisted);
        $denied = app(BotRiskEngine::class)->assess($this->browserRequest('1.1.1.1'));
        self::assertContains('ip_denylist', $denied->reasons);
        Http::assertNothingSent();
        self::assertTrue(CidrMatcher::contains('2001:4860:4860::8888', ['2001:4860::/32']));
    }

    private function engineReturning(RiskAssessment $assessment): BotRiskEngine
    {
        return new class(app(BotShieldSettings::class), app(NetworkIntelligenceClient::class), $assessment) extends BotRiskEngine
        {
            public function __construct(
                BotShieldSettings $settings,
                NetworkIntelligenceClient $network,
                private readonly RiskAssessment $assessment,
            ) {
                parent::__construct($settings, $network);
            }

            public function assess(Request $request): RiskAssessment
            {
                return $this->assessment;
            }
        };
    }

    private function browserRequest(string $ip, string $path = '/products/1'): Request
    {
        return $this->request($ip, $this->chromeUserAgent(), $path);
    }

    private function request(string $ip, string $userAgent, string $path = '/products/1'): Request
    {
        return Request::create('https://store.example.test' . $path, 'GET', [], [], [], [
            'REMOTE_ADDR'          => $ip,
            'HTTP_USER_AGENT'      => $userAgent,
            'HTTP_ACCEPT'          => 'text/html,application/xhtml+xml',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
            'HTTP_SEC_CH_UA'       => '"Chromium";v="124", "Not-A.Brand";v="99"',
        ]);
    }

    private function chromeUserAgent(): string
    {
        return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36';
    }
}
