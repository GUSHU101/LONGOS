<?php

namespace Tests\Feature;

use App\Http\Middleware\ApplySecurityHeaders;
use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use Beike\Shop\Providers\PluginServiceProvider;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Plugin\LongosBotShield\Middleware\Shop\ProtectStorefront;
use Plugin\LongosBotShield\Services\BotRiskEngine;
use Plugin\LongosBotShield\Services\BotShieldSettings;
use Plugin\LongosBotShield\Services\ClearanceToken;
use Plugin\LongosBotShield\Services\CloudflareRadarClient;
use Plugin\LongosBotShield\Services\CrawlerVerifier;
use Plugin\LongosBotShield\Services\InternalHomepageRequest;
use Plugin\LongosBotShield\Services\IpInfoClient;
use Plugin\LongosBotShield\Services\MainSiteHomepage;
use Plugin\LongosBotShield\Services\NetworkIntelligenceClient;
use Plugin\LongosBotShield\Services\RiskAssessment;
use Plugin\LongosBotShield\Services\ThreatCatalog;
use Plugin\LongosBotShield\Support\CidrMatcher;
use Plugin\LongosBotShield\Support\IpAddress;
use Symfony\Component\HttpFoundation\Response;

class LongosBotShieldTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config([
            'cache.default'                                                 => 'array',
            'bk.plugin.longos_bot_shield.ipinfo_token'                      => 'ipinfo-test-token',
            'bk.plugin.longos_bot_shield.ipinfo_api_tier'                   => 'lookup',
            'bk.plugin.longos_bot_shield.cloudflare_radar_fallback_enabled' => true,
            'bk.plugin.longos_bot_shield.cloudflare_radar_token'            => '',
            'bk.plugin.longos_bot_shield.enforcement_mode'                  => 'homepage',
            'bk.plugin.longos_bot_shield.crawler_policy'                    => 'block_all',
            'bk.plugin.longos_bot_shield.requests_per_minute'               => 120,
            'bk.plugin.longos_bot_shield.burst_per_10_seconds'              => 35,
            'bk.plugin.longos_bot_shield.cache_minutes'                     => 1440,
            'bk.plugin.longos_bot_shield.provider_requests_per_minute'      => 300,
            'bk.plugin.longos_bot_shield.use_builtin_catalog'               => true,
            'bk.plugin.longos_bot_shield.vendor_allowlist_enabled'          => false,
            'bk.plugin.longos_bot_shield.allowed_asns'                      => '',
            'bk.plugin.longos_bot_shield.allowed_organization_keywords'     => '',
            'bk.plugin.longos_bot_shield.suspicious_path_detection'         => true,
            'bk.plugin.longos_bot_shield.header_anomaly_detection'          => true,
            'bk.plugin.longos_bot_shield.allow_cidrs'                       => '',
            'bk.plugin.longos_bot_shield.deny_cidrs'                        => '',
            'bk.plugin.longos_bot_shield.blocked_asns'                      => 'AS174, AS3356, AS6939',
            'bk.plugin.longos_bot_shield.organization_keywords'             => 'facebook, meta, google, tiktok, amazon, hosting, data center, microsoft azure, digitalocean, linode, akamai, ovh, hetzner, choopa, m2f',
            'bk.plugin.longos_bot_shield.diagnostic_log'                    => false,
        ]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_ipinfo_uses_bearer_auth_and_normalizes_security_fields(): void
    {
        Http::fake([
            'api.ipinfo.io/*' => Http::response([
                'ip' => '8.8.8.8',
                'as' => [
                    'asn'    => 'AS16509',
                    'name'   => 'Amazon.com, Inc.',
                    'domain' => 'amazon.com',
                    'type'   => 'hosting',
                ],
                'is_hosting'   => true,
                'is_anonymous' => false,
                'anonymous'    => [
                    'is_proxy' => false,
                    'is_vpn'   => false,
                    'is_tor'   => false,
                    'is_relay' => false,
                ],
            ], 200),
        ]);

        $result = app(IpInfoClient::class)->lookup('8.8.8.8');

        self::assertSame('ok', $result['status']);
        self::assertSame('AS16509', $result['asn']);
        self::assertSame('Amazon.com, Inc.', $result['name']);
        self::assertTrue($result['is_hosting']);
        self::assertFalse($result['is_anonymous']);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://api.ipinfo.io/lookup/8.8.8.8'
            && $request->hasHeader('Authorization', 'Bearer ipinfo-test-token')
            && ! str_contains($request->url(), 'token='));
    }

    public function test_builtin_catalog_and_ip_normalization_cover_requested_networks(): void
    {
        $settings = app(BotShieldSettings::class);

        self::assertContains('AS174', $settings->blockedAsns());
        self::assertContains('AS8075', $settings->blockedAsns());
        self::assertContains('AS16509', $settings->blockedAsns());
        self::assertContains('AS32934', $settings->blockedAsns());
        self::assertContains('AS396982', $settings->blockedAsns());
        self::assertContains('AS12076', $settings->blockedAsns());
        self::assertContains('digitalocean', $settings->organizationKeywords());
        self::assertContains('hetzner', $settings->organizationKeywords());
        self::assertContains('gptbot', ThreatCatalog::crawlerMarkers());
        self::assertContains('bingbot', ThreatCatalog::crawlerMarkers());
        self::assertContains('google-agent', ThreatCatalog::crawlerMarkers());
        self::assertContains('oai-adsbot', ThreatCatalog::crawlerMarkers());
        self::assertSame('8.8.8.8', IpAddress::normalize('::ffff:8.8.8.8'));
        self::assertTrue(CidrMatcher::contains('::ffff:8.8.8.8', ['8.8.8.0/24']));
        self::assertSame($settings->ipHash('8.8.8.8'), $settings->ipHash('::ffff:8.8.8.8'));
    }

    public function test_configuration_panel_exposes_scope_and_provider_controls_without_scores(): void
    {
        $columns = require base_path('plugins/LongosBotShield/columns.php');
        $names   = array_column($columns, 'name');

        self::assertContains('ipinfo_api_tier', $names);
        self::assertContains('cloudflare_radar_fallback_enabled', $names);
        self::assertContains('cloudflare_radar_token', $names);
        self::assertContains('blocked_asns', $names);
        self::assertContains('organization_keywords', $names);
        self::assertNotContains('challenge_threshold', $names);
        self::assertNotContains('block_threshold', $names);
        self::assertNotContains('provider_failure_policy', $names);
        self::assertNotContains('ip_api_key', $names);
    }

    public function test_cold_provider_chain_stays_within_the_conversion_latency_budget(): void
    {
        $ipinfoTimeout = (new \ReflectionClass(IpInfoClient::class))->getConstant('REQUEST_TIMEOUT_SECONDS');
        $radarTimeout  = (new \ReflectionClass(CloudflareRadarClient::class))->getConstant('REQUEST_TIMEOUT_SECONDS');

        self::assertSame(0.45, $ipinfoTimeout);
        self::assertSame(0.3, $radarTimeout);
        self::assertLessThanOrEqual(0.8, $ipinfoTimeout + $radarTimeout);
        self::assertStringNotContainsString(
            '->retry(',
            (string) file_get_contents(base_path('plugins/LongosBotShield/Services/IpInfoClient.php')),
        );
        self::assertStringNotContainsString(
            '->retry(',
            (string) file_get_contents(base_path('plugins/LongosBotShield/Services/CloudflareRadarClient.php')),
        );
    }

    public function test_ipinfo_residential_proxy_and_company_fields_enter_the_shielding_scope(): void
    {
        Http::fake(['api.ipinfo.io/*' => Http::response([
            'ip' => '8.8.4.4',
            'as' => [
                'asn'    => 'AS7922',
                'name'   => 'Residential ISP',
                'domain' => 'example.net',
                'type'   => 'isp',
            ],
            'company' => [
                'name'   => 'M2F Datacenter Proxy',
                'domain' => 'proxy.example',
                'type'   => 'hosting',
            ],
            'anonymous' => [
                'name'         => 'Residential proxy pool',
                'is_res_proxy' => true,
            ],
        ], 200)]);

        $result     = app(IpInfoClient::class)->lookup('8.8.4.4');
        $assessment = app(BotRiskEngine::class)->assess($this->request('8.8.4.4', $this->chromeUserAgent()));

        self::assertTrue($result['is_res_proxy']);
        self::assertTrue($result['is_anonymous']);
        self::assertSame('M2F Datacenter Proxy', $result['company_name']);
        self::assertContains('residential_proxy', $assessment->reasons);
        self::assertContains('anonymous_network', $assessment->reasons);
        self::assertContains('organization_keyword', $assessment->reasons);
    }

    public function test_cloudflare_radar_is_used_as_the_asn_fallback(): void
    {
        config(['bk.plugin.longos_bot_shield.cloudflare_radar_token' => 'radar-test-token']);
        Http::fake([
            'api.ipinfo.io/*'       => Http::response(['error' => 'temporarily unavailable'], 503),
            'api.cloudflare.com/*'  => Http::response($this->radarAsn('AS9009', 'M247 Europe SRL'), 200),
        ]);

        $result     = app(NetworkIntelligenceClient::class)->lookup('8.8.4.4');
        $assessment = app(BotRiskEngine::class)->assess($this->request('8.8.4.4', $this->chromeUserAgent()));

        self::assertSame('ok', $result['status']);
        self::assertSame('cloudflare-radar', $result['provider']);
        self::assertSame('AS9009', $result['asn']);
        self::assertFalse($result['security_data_present']);
        self::assertFalse($result['is_proxy']);
        self::assertContains('asn_denylist', $assessment->reasons);
        Http::assertSent(static fn (ClientRequest $request): bool => str_starts_with(
            $request->url(),
            'https://api.cloudflare.com/client/v4/radar/entities/asns/ip?',
        )   && str_contains($request->url(), 'ip=8.8.4.4')
            && $request->hasHeader('Authorization', 'Bearer radar-test-token'));
    }

    public function test_ipinfo_lite_asn_does_not_trigger_an_unnecessary_radar_lookup(): void
    {
        config([
            'bk.plugin.longos_bot_shield.ipinfo_api_tier'        => 'lite',
            'bk.plugin.longos_bot_shield.cloudflare_radar_token' => 'radar-test-token',
        ]);
        Http::fake(['api.ipinfo.io/*' => Http::response([
            'ip'        => '8.8.8.8',
            'asn'       => 'AS15169',
            'as_name'   => 'Google LLC',
            'as_domain' => 'google.com',
        ], 200)]);

        $result = app(NetworkIntelligenceClient::class)->lookup('8.8.8.8');

        self::assertSame('ipinfo', $result['provider']);
        self::assertSame('AS15169', $result['asn']);
        self::assertSame('Google LLC', $result['name']);
        self::assertSame('google.com', $result['domain']);
        self::assertFalse($result['security_data_present']);
        Http::assertSentCount(1);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://api.ipinfo.io/lite/8.8.8.8');
    }

    public function test_radar_enriches_only_a_missing_ipinfo_asn_without_inventing_security_flags(): void
    {
        config(['bk.plugin.longos_bot_shield.cloudflare_radar_token' => 'radar-test-token']);
        Http::fake([
            'api.ipinfo.io/*' => Http::response([
                'ip'           => '8.8.8.8',
                'is_hosting'   => false,
                'is_anonymous' => false,
            ], 200),
            'api.cloudflare.com/*' => Http::response($this->radarAsn('AS15169', 'Google LLC', 'https://google.com'), 200),
        ]);

        $result = app(NetworkIntelligenceClient::class)->lookup('8.8.8.8');

        self::assertSame('ipinfo+cloudflare-radar', $result['provider']);
        self::assertSame('AS15169', $result['asn']);
        self::assertSame('google.com', $result['domain']);
        self::assertFalse($result['is_hosting']);
        self::assertFalse($result['is_proxy']);
    }

    public function test_provider_clients_reject_oversized_json_before_normalization(): void
    {
        config(['bk.plugin.longos_bot_shield.cloudflare_radar_token' => 'radar-test-token']);
        $oversized = str_repeat('x', 524289);
        Http::fake([
            'api.ipinfo.io/*'       => Http::response($oversized, 200, ['Content-Type' => 'application/json']),
            'api.cloudflare.com/*'  => Http::response($oversized, 200, ['Content-Type' => 'application/json']),
        ]);

        self::assertSame('unavailable', app(IpInfoClient::class)->lookup('8.8.8.8')['status']);
        self::assertSame('unavailable', app(CloudflareRadarClient::class)->lookup('8.8.4.4')['status']);
        Http::assertSentCount(2);
    }

    public function test_invalid_ipinfo_response_falls_back_to_cloudflare_radar(): void
    {
        config(['bk.plugin.longos_bot_shield.cloudflare_radar_token' => 'radar-test-token']);
        Http::fake([
            'api.ipinfo.io/*' => Http::response([
                'ip' => '1.1.1.1',
                'as' => ['asn' => 'AS13335', 'name' => 'Wrong response'],
            ], 200),
            'api.cloudflare.com/*' => Http::response($this->radarAsn('AS15169', 'Google LLC'), 200),
        ]);

        $result = app(NetworkIntelligenceClient::class)->lookup('8.8.4.4');

        self::assertSame('cloudflare-radar', $result['provider']);
        self::assertSame('AS15169', $result['asn']);
    }

    public function test_cloudflare_radar_rejects_a_malformed_success_payload(): void
    {
        config(['bk.plugin.longos_bot_shield.cloudflare_radar_token' => 'radar-test-token']);
        Http::fake(['api.cloudflare.com/*' => Http::response([
            'success' => true,
            'result'  => ['asn' => ['asn' => 0, 'name' => 'Invalid']],
        ], 200)]);

        self::assertSame('unavailable', app(CloudflareRadarClient::class)->lookup('8.8.4.4')['status']);
    }

    public function test_cloudflare_radar_rate_limit_opens_its_own_circuit(): void
    {
        config(['bk.plugin.longos_bot_shield.cloudflare_radar_token' => 'radar-test-token']);
        Http::fake(['api.cloudflare.com/*' => Http::response([
            'success' => false,
            'errors'  => [['message' => 'rate limited']],
        ], 429, ['Retry-After' => '30'])]);
        $client = app(CloudflareRadarClient::class);

        self::assertSame('unavailable', $client->lookup('8.8.8.8')['status']);
        $sentBeforeOpenCircuit = Http::recorded()->count();
        self::assertSame('unavailable', $client->lookup('8.8.4.4')['status']);
        self::assertSame($sentBeforeOpenCircuit, Http::recorded()->count());
    }

    public function test_declared_crawlers_and_headless_automation_are_high_risk(): void
    {
        Http::fake(['api.ipinfo.io/*' => Http::response($this->residentialNetwork(), 200)]);
        $engine = app(BotRiskEngine::class);

        $google   = $engine->assess($this->request('8.8.8.8', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'));
        $headless = $engine->assess($this->request('1.1.1.1', 'Mozilla/5.0 HeadlessChrome/124.0 Puppeteer'));

        self::assertContains('crawler_declared', $google->reasons);
        self::assertContains('ua_automation', $headless->reasons);
    }

    public function test_google_verification_uses_the_official_published_cidr_feeds(): void
    {
        Http::fake([
            'developers.google.com/static/crawling/ipranges/*' => Http::response([
                'creationTime' => now()->toIso8601String(),
                'prefixes'     => [['ipv4Prefix' => '66.249.64.0/19']],
            ], 200, ['Content-Type' => 'application/json']),
        ]);
        $verifier = app(CrawlerVerifier::class);

        self::assertTrue($verifier->verify('google', '66.249.66.1', []));
        self::assertFalse($verifier->verify('google', '8.8.8.8', []));
        Http::assertSentCount(5);
    }

    public function test_google_range_refresh_failure_opens_a_short_circuit(): void
    {
        Http::fake([
            'developers.google.com/static/crawling/ipranges/*' => Http::response(['error' => 'unavailable'], 503),
        ]);
        $verifier = app(CrawlerVerifier::class);

        self::assertFalse($verifier->verify('google', '66.249.66.1', []));
        self::assertFalse($verifier->verify('google', '66.249.66.2', []));
        Http::assertSentCount(5);
    }

    public function test_requested_hosting_and_asn_signals_enter_the_shielding_scope(): void
    {
        Http::fake(['api.ipinfo.io/*' => Http::response([
            'ip' => '9.9.9.9',
            'as' => [
                'asn'    => 'AS174',
                'name'   => 'Cogent Communications',
                'domain' => 'cogentco.com',
                'type'   => 'hosting',
            ],
            'is_hosting'   => true,
            'is_anonymous' => false,
        ], 200)]);

        $assessment = app(BotRiskEngine::class)->assess($this->request('9.9.9.9', $this->chromeUserAgent()));

        self::assertContains('asn_denylist', $assessment->reasons);
        self::assertContains('hosting_network', $assessment->reasons);
        self::assertTrue($assessment->matchesConfiguredShieldingScope());
    }

    public function test_generic_crawlers_and_exploit_scan_paths_are_high_risk_without_provider_calls(): void
    {
        Http::fake();
        $engine = app(BotRiskEngine::class);

        $crawler     = $engine->assess($this->request('8.8.8.8', 'AcmeScanner/3.2'));
        $pathScan    = $engine->assess($this->request('1.1.1.1', $this->chromeUserAgent(), '/.env'));
        $encodedScan = $engine->assess($this->request(
            '9.9.9.9',
            $this->chromeUserAgent(),
            '/%25252eenv',
        ));
        $oversized = $engine->assess($this->request(
            '4.4.4.4',
            $this->chromeUserAgent(),
            '/' . str_repeat('a', 8193),
        ));

        self::assertContains('ua_generic_crawler', $crawler->reasons);
        self::assertContains('suspicious_path', $pathScan->reasons);
        self::assertContains('suspicious_path', $encodedScan->reasons);
        self::assertContains('request_target_oversized', $oversized->reasons);
        Http::assertNothingSent();
    }

    public function test_social_in_app_browsers_are_not_mistaken_for_link_preview_bots(): void
    {
        Http::fake(['api.ipinfo.io/*' => Http::response($this->residentialNetwork(), 200)]);
        $userAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1 WhatsApp/2.24.20';

        $assessment = app(BotRiskEngine::class)->assess($this->request('8.8.8.8', $userAgent));

        self::assertNotContains('crawler_declared', $assessment->reasons);
    }

    public function test_short_organization_keywords_require_token_boundaries(): void
    {
        Http::fake(['api.ipinfo.io/*' => Http::response([
            'ip' => '8.8.8.8',
            'as' => [
                'asn'    => 'AS7922',
                'name'   => 'Metacom Residential Networks',
                'domain' => 'metacom.example',
                'type'   => 'isp',
            ],
            'is_hosting'   => false,
            'is_anonymous' => false,
        ], 200)]);

        $assessment = app(BotRiskEngine::class)->assess($this->request('8.8.8.8', $this->chromeUserAgent()));

        self::assertNotContains('organization_keyword', $assessment->reasons);
    }

    public function test_ten_second_burst_limit_stops_short_scans(): void
    {
        config(['bk.plugin.longos_bot_shield.burst_per_10_seconds' => 5]);
        Http::fake(['api.ipinfo.io/*' => Http::response($this->residentialNetwork('9.9.9.9'), 200)]);
        $engine = app(BotRiskEngine::class);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $assessment = $engine->assess($this->request('9.9.9.9', $this->chromeUserAgent()));
            self::assertNotContains('rate_burst', $assessment->reasons);
        }

        $limited = $engine->assess($this->request('9.9.9.9', $this->chromeUserAgent()));
        self::assertContains('rate_burst', $limited->reasons);
    }

    public function test_rate_limits_and_provider_caches_are_isolated_per_tenant(): void
    {
        config(['bk.plugin.longos_bot_shield.burst_per_10_seconds' => 5]);
        Http::fake(['api.ipinfo.io/*' => Http::response($this->residentialNetwork('9.9.9.9'), 200)]);
        $engine = app(BotRiskEngine::class);

        $siteA     = new Site(['status' => Site::STATUS_ACTIVE]);
        $siteA->id = 101;
        app()->instance((string) config('multitenancy.current_tenant_container_key'), $siteA);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            self::assertNotContains(
                'rate_burst',
                $engine->assess($this->request('9.9.9.9', $this->chromeUserAgent()))->reasons,
            );
        }

        $siteB     = new Site(['status' => Site::STATUS_ACTIVE]);
        $siteB->id = 102;
        app()->instance((string) config('multitenancy.current_tenant_container_key'), $siteB);
        $assessment = $engine->assess($this->request('9.9.9.9', $this->chromeUserAgent()));

        self::assertNotContains('rate_burst', $assessment->reasons);
        self::assertSame(2, Http::recorded()->count());
    }

    public function test_provider_failure_is_not_treated_as_shielding_evidence(): void
    {
        config(['bk.plugin.longos_bot_shield.cloudflare_radar_token' => 'radar-test-token']);
        Http::fake([
            'api.ipinfo.io/*'       => Http::response(['error' => 'unavailable'], 503),
            'api.cloudflare.com/*'  => Http::response(['success' => false], 503),
        ]);

        $assessment = app(BotRiskEngine::class)->assess($this->request('8.8.8.8', $this->chromeUserAgent()));

        self::assertFalse($assessment->matchesEnforcementScope());
        Http::assertSent(static fn (ClientRequest $request): bool => str_starts_with($request->url(), 'https://api.ipinfo.io/'));
        Http::assertSent(static fn (ClientRequest $request): bool => str_starts_with($request->url(), 'https://api.cloudflare.com/'));
    }

    public function test_ipinfo_circuit_breaker_stops_failure_amplification(): void
    {
        Http::fake(['api.ipinfo.io/*' => Http::response(['error' => 'temporarily unavailable'], 503)]);
        $client = app(IpInfoClient::class);

        self::assertSame('unavailable', $client->lookup('8.8.8.1')['status']);
        self::assertSame('unavailable', $client->lookup('8.8.8.2')['status']);
        self::assertSame('unavailable', $client->lookup('8.8.8.3')['status']);
        $sentBeforeOpenCircuit = Http::recorded()->count();

        self::assertSame('unavailable', $client->lookup('8.8.8.4')['status']);
        self::assertSame($sentBeforeOpenCircuit, Http::recorded()->count());
    }

    public function test_ipinfo_authentication_failure_is_not_retried_and_opens_the_circuit(): void
    {
        Http::fake(['api.ipinfo.io/*' => Http::response(['error' => 'unauthorized'], 401)]);
        $client = app(IpInfoClient::class);

        self::assertSame('unavailable', $client->lookup('8.8.8.8')['status']);
        self::assertSame(1, Http::recorded()->count());
        self::assertSame('unavailable', $client->lookup('8.8.4.4')['status']);
        self::assertSame(1, Http::recorded()->count());
    }

    public function test_rotating_the_ipinfo_token_bypasses_old_failure_cache_and_circuit_state(): void
    {
        Http::fake(static function (ClientRequest $request) {
            if ($request->hasHeader('Authorization', 'Bearer ipinfo-test-token')) {
                return Http::response(['error' => 'unauthorized'], 401);
            }

            return Http::response([
                'ip' => '8.8.8.8',
                'as' => [
                    'asn'    => 'AS15169',
                    'name'   => 'Google LLC',
                    'domain' => 'google.com',
                    'type'   => 'hosting',
                ],
                'is_hosting'   => true,
                'is_anonymous' => false,
            ], 200);
        });
        $client = app(IpInfoClient::class);

        self::assertSame('unavailable', $client->lookup('8.8.8.8')['status']);
        config(['bk.plugin.longos_bot_shield.ipinfo_token' => 'rotated-ipinfo-token']);
        self::assertSame('ok', $client->lookup('8.8.8.8')['status']);
        Http::assertSentCount(2);
    }

    public function test_provider_lookup_budget_caps_uncached_rotating_ip_traffic(): void
    {
        config(['bk.plugin.longos_bot_shield.provider_requests_per_minute' => 10]);
        Http::fake(static function (ClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $ip   = rawurldecode((string) basename($path));

            return Http::response([
                'ip' => $ip,
                'as' => [
                    'asn'    => 'AS7922',
                    'name'   => 'Residential ISP',
                    'domain' => 'residential.example',
                    'type'   => 'isp',
                ],
                'is_hosting'   => false,
                'is_anonymous' => false,
            ], 200);
        });
        $client = app(IpInfoClient::class);

        for ($index = 1; $index <= 10; $index++) {
            self::assertSame('ok', $client->lookup("8.8.8.{$index}")['status']);
        }

        self::assertSame('unavailable', $client->lookup('8.8.9.1')['status']);
        Http::assertSentCount(10);
    }

    public function test_ipinfo_singleflight_marker_collapses_concurrent_cache_misses(): void
    {
        $ip       = '8.8.8.8';
        $settings = app(BotShieldSettings::class);
        $scope    = hash(
            'sha256',
            implode('|', [
                $settings->tenantKey(),
                $settings->ipinfoApiTier(),
                hash('sha256', $settings->ipinfoToken()),
            ]),
        );
        $cacheKey = 'longos:bot-shield:ipinfo:' . $scope . ':' . $settings->ipHash($ip);
        Cache::put($cacheKey . ':refreshing', true, now()->addSeconds(15));
        Http::fake(['api.ipinfo.io/*' => Http::response($this->residentialNetwork($ip), 200)]);
        $client = app(IpInfoClient::class);

        self::assertSame('unavailable', $client->lookup($ip)['status']);
        Http::assertNothingSent();

        Cache::forget($cacheKey . ':refreshing');
        self::assertSame('ok', $client->lookup($ip)['status']);
        Http::assertSentCount(1);
    }

    public function test_ipinfo_singleflight_contention_reuses_the_last_trusted_result(): void
    {
        $ip       = '8.8.8.8';
        $settings = app(BotShieldSettings::class);
        $scope    = hash('sha256', implode('|', [
            $settings->tenantKey(),
            $settings->ipinfoApiTier(),
            hash('sha256', $settings->ipinfoToken()),
        ]));
        $cacheKey = 'longos:bot-shield:ipinfo:' . $scope . ':' . $settings->ipHash($ip);
        Http::fake(['api.ipinfo.io/*' => Http::response($this->residentialNetwork($ip), 200)]);
        $client = app(IpInfoClient::class);

        self::assertSame('ok', $client->lookup($ip)['status']);
        Cache::forget($cacheKey);
        Cache::put($cacheKey . ':refreshing', true, now()->addSeconds(15));

        $stale = $client->lookup($ip);

        self::assertSame('ok', $stale['status']);
        self::assertSame('AS7922', $stale['asn']);
        Http::assertSentCount(1);
    }

    public function test_cloudflare_radar_singleflight_marker_collapses_concurrent_cache_misses(): void
    {
        config(['bk.plugin.longos_bot_shield.cloudflare_radar_token' => 'radar-test-token']);
        $ip       = '8.8.4.4';
        $settings = app(BotShieldSettings::class);
        $scope    = hash(
            'sha256',
            $settings->tenantKey() . '|' . hash('sha256', $settings->cloudflareRadarToken()),
        );
        $cacheKey = 'longos:bot-shield:cloudflare-radar:' . $scope . ':' . $settings->ipHash($ip);
        Cache::put($cacheKey . ':refreshing', true, now()->addSeconds(15));
        Http::fake(['api.cloudflare.com/*' => Http::response($this->radarAsn('AS7922', 'Residential ISP'), 200)]);
        $client = app(CloudflareRadarClient::class);

        self::assertSame('unavailable', $client->lookup($ip)['status']);
        Http::assertNothingSent();

        Cache::forget($cacheKey . ':refreshing');
        self::assertSame('ok', $client->lookup($ip)['status']);
        Http::assertSentCount(1);
    }

    public function test_allowlisted_cidr_bypasses_provider_and_all_other_signals(): void
    {
        config(['bk.plugin.longos_bot_shield.allow_cidrs' => "8.8.8.0/24\n2001:4860::/32"]);
        Http::fake();

        $assessment = app(BotRiskEngine::class)->assess($this->request('8.8.8.8', 'Googlebot/2.1 HeadlessChrome'));

        self::assertTrue($assessment->allowlisted);
        Http::assertNothingSent();
        self::assertTrue(CidrMatcher::contains('2001:4860:4860::8888', ['2001:4860::/32']));
        self::assertSame(['10.0.0.0/99', 'not-an-ip'], CidrMatcher::invalidEntries("10.0.0.0/99\nnot-an-ip"));
    }

    public function test_vendor_asn_allowlist_overrides_every_bot_and_network_block(): void
    {
        config([
            'bk.plugin.longos_bot_shield.vendor_allowlist_enabled' => true,
            'bk.plugin.longos_bot_shield.allowed_asns'             => 'AS15169',
            'bk.plugin.longos_bot_shield.deny_cidrs'               => '8.8.8.0/24',
            'bk.plugin.longos_bot_shield.requests_per_minute'      => 10,
            'bk.plugin.longos_bot_shield.burst_per_10_seconds'     => 5,
        ]);
        Http::fake(['api.ipinfo.io/*' => Http::response([
            'ip' => '8.8.8.8',
            'as' => [
                'asn'    => 'AS15169',
                'name'   => 'Google LLC',
                'domain' => 'google.com',
                'type'   => 'hosting',
            ],
            'is_hosting'   => true,
            'is_anonymous' => false,
        ], 200)]);

        $request = $this->request(
            '8.8.8.8',
            'Mozilla/5.0 HeadlessChrome/124.0 Puppeteer Googlebot/2.1',
            '/.env',
        );
        app()->instance('request', $request);
        $assessment = app(BotRiskEngine::class)->assess($request);

        self::assertTrue($assessment->allowlisted);
        self::assertSame(['vendor_allowlist_asn'], $assessment->reasons);

        $middleware = new ProtectStorefront(
            app(BotRiskEngine::class),
            app(BotShieldSettings::class),
            app(ClearanceToken::class),
            app(InternalHomepageRequest::class),
            new class(app(InternalHomepageRequest::class)) extends MainSiteHomepage
            {
                public function render(): ?array
                {
                    throw new \RuntimeException('An allowlisted vendor must not render the replacement homepage.');
                }
            },
        );
        $response = $middleware->handle($request, static fn (): Response => new Response('NORMAL STOREFRONT'));

        self::assertSame('NORMAL STOREFRONT', $response->getContent());
        self::assertNull($response->headers->get('X-Longos-Bot-Shield'));
        Http::assertSentCount(1);
    }

    public function test_vendor_name_or_domain_allowlist_can_bypass_a_blocked_hosting_network(): void
    {
        config([
            'bk.plugin.longos_bot_shield.vendor_allowlist_enabled'       => true,
            'bk.plugin.longos_bot_shield.allowed_organization_keywords'  => 'trusted-vendor.example',
        ]);
        Http::fake(['api.ipinfo.io/*' => Http::response([
            'ip' => '8.8.4.4',
            'as' => [
                'asn'    => 'AS64510',
                'name'   => 'Trusted Vendor Cloud',
                'domain' => 'trusted-vendor.example',
                'type'   => 'hosting',
            ],
            'company' => [
                'name'   => 'Trusted Vendor Limited',
                'domain' => 'trusted-vendor.example',
                'type'   => 'hosting',
            ],
            'is_hosting'   => true,
            'is_anonymous' => false,
        ], 200)]);

        $assessment = app(BotRiskEngine::class)->assess($this->request(
            '8.8.4.4',
            'facebookexternalhit/1.1 HeadlessChrome',
        ));

        self::assertTrue($assessment->allowlisted);
        self::assertSame(['vendor_allowlist_organization'], $assessment->reasons);

        config([
            'bk.plugin.longos_bot_shield.allowed_organization_keywords' => 'Trusted Vendor Limited',
        ]);
        $nameAssessment = app(BotRiskEngine::class)->assess($this->request(
            '8.8.4.4',
            'facebookexternalhit/1.1 HeadlessChrome',
        ));

        self::assertTrue($nameAssessment->allowlisted);
        self::assertSame(['vendor_allowlist_organization'], $nameAssessment->reasons);
        Http::assertSentCount(1);
    }

    public function test_vendor_allowlist_asns_are_canonicalized_and_invalid_values_fail_closed(): void
    {
        config([
            'bk.plugin.longos_bot_shield.allowed_asns' => 'AS15169, 16509, AS000174, AS0, AS4294967296, vendor123',
        ]);

        self::assertSame(
            ['AS15169', 'AS16509'],
            app(BotShieldSettings::class)->allowedAsns(),
        );
    }

    public function test_vendor_allowlist_never_trusts_a_spoofed_ua_or_unavailable_provider(): void
    {
        config([
            'bk.plugin.longos_bot_shield.vendor_allowlist_enabled'       => true,
            'bk.plugin.longos_bot_shield.allowed_asns'                   => 'AS15169',
            'bk.plugin.longos_bot_shield.allowed_organization_keywords'  => 'google.com',
        ]);
        Http::fake(['api.ipinfo.io/*' => static function (ClientRequest $request) {
            if (str_contains($request->url(), '/8.8.8.8')) {
                return Http::response([
                    'ip' => '8.8.8.8',
                    'as' => [
                        'asn'    => 'AS7922',
                        'name'   => 'Google.com Impersonator ISP',
                        'domain' => 'notgoogle.com',
                        'type'   => 'isp',
                    ],
                    'is_hosting'   => false,
                    'is_anonymous' => false,
                ], 200);
            }

            return Http::response(['error' => 'unavailable'], 503);
        }]);

        $spoofed = app(BotRiskEngine::class)->assess($this->request('8.8.8.8', 'Googlebot/2.1 HeadlessChrome'));

        self::assertFalse($spoofed->allowlisted);
        self::assertContains('crawler_declared', $spoofed->reasons);

        $unavailable = app(BotRiskEngine::class)->assess($this->request('8.8.4.4', 'Googlebot/2.1 HeadlessChrome'));

        self::assertFalse($unavailable->allowlisted);
        self::assertContains('crawler_declared', $unavailable->reasons);
    }

    public function test_disabled_vendor_allowlist_does_not_query_providers_or_bypass_detection(): void
    {
        config([
            'bk.plugin.longos_bot_shield.vendor_allowlist_enabled' => false,
            'bk.plugin.longos_bot_shield.allowed_asns'             => 'AS15169',
        ]);
        Http::fake();

        $assessment = app(BotRiskEngine::class)->assess($this->request(
            '8.8.8.8',
            'Mozilla/5.0 HeadlessChrome/124.0 Puppeteer',
        ));

        self::assertFalse($assessment->allowlisted);
        Http::assertNothingSent();
    }

    public function test_vendor_asn_allowlist_uses_cloudflare_radar_when_ipinfo_is_unavailable(): void
    {
        config([
            'bk.plugin.longos_bot_shield.vendor_allowlist_enabled' => true,
            'bk.plugin.longos_bot_shield.allowed_asns'             => 'AS64510',
            'bk.plugin.longos_bot_shield.cloudflare_radar_token'   => 'radar-test-token',
        ]);
        Http::fake([
            'api.ipinfo.io/*'       => Http::response(['error' => 'temporarily unavailable'], 503),
            'api.cloudflare.com/*'  => Http::response($this->radarAsn('AS64510', 'Trusted Vendor Limited'), 200),
        ]);

        $assessment = app(BotRiskEngine::class)->assess($this->request(
            '8.8.4.4',
            'facebookexternalhit/1.1 HeadlessChrome',
        ));

        self::assertTrue($assessment->allowlisted);
        self::assertSame(['vendor_allowlist_asn'], $assessment->reasons);
        $recorded = Http::recorded();
        self::assertNotEmpty($recorded->filter(
            static fn (array $exchange): bool => str_starts_with($exchange[0]->url(), 'https://api.ipinfo.io/'),
        ));
        self::assertCount(1, $recorded->filter(
            static fn (array $exchange): bool => str_starts_with($exchange[0]->url(), 'https://api.cloudflare.com/'),
        ));
    }

    public function test_enabled_vendor_allowlist_with_empty_lists_does_not_add_provider_traffic(): void
    {
        config(['bk.plugin.longos_bot_shield.vendor_allowlist_enabled' => true]);
        Http::fake();

        $assessment = app(BotRiskEngine::class)->assess($this->request(
            '8.8.8.8',
            'Mozilla/5.0 HeadlessChrome/124.0 Puppeteer',
        ));

        self::assertFalse($assessment->allowlisted);
        Http::assertNothingSent();
    }

    public function test_ipinfo_token_is_encrypted_at_rest_and_blank_runtime_values_fail_closed(): void
    {
        $stored = BotShieldSettings::normalizeForStorage([
            'ipinfo_token'            => 'secret-token-value',
            'cloudflare_radar_token'  => 'fallback-secret-value',
        ]);

        self::assertStringStartsWith(BotShieldSettings::ENCRYPTED_PREFIX, $stored['ipinfo_token']);
        self::assertStringNotContainsString('secret-token-value', $stored['ipinfo_token']);
        self::assertStringStartsWith(BotShieldSettings::ENCRYPTED_PREFIX, $stored['cloudflare_radar_token']);
        self::assertStringNotContainsString('fallback-secret-value', $stored['cloudflare_radar_token']);
        config(['bk.plugin.longos_bot_shield.ipinfo_token' => $stored['ipinfo_token']]);
        config(['bk.plugin.longos_bot_shield.cloudflare_radar_token' => $stored['cloudflare_radar_token']]);
        self::assertSame('secret-token-value', app(BotShieldSettings::class)->ipinfoToken());
        self::assertSame('fallback-secret-value', app(BotShieldSettings::class)->cloudflareRadarToken());

        config(['bk.plugin.longos_bot_shield.ipinfo_token' => BotShieldSettings::ENCRYPTED_PREFIX . 'damaged']);
        self::assertSame('', app(BotShieldSettings::class)->ipinfoToken());
    }

    public function test_internal_main_site_request_signature_is_host_bound_and_short_lived(): void
    {
        $signer  = app(InternalHomepageRequest::class);
        $request = Request::create('https://main.example.test/', 'GET');
        $request->headers->set(InternalHomepageRequest::HEADER, $signer->headerValue('main.example.test'));
        self::assertTrue($signer->valid($request));

        $other = Request::create('https://other.example.test/', 'GET');
        $other->headers->set(InternalHomepageRequest::HEADER, $request->header(InternalHomepageRequest::HEADER));
        self::assertFalse($signer->valid($other));

        $otherPath = Request::create('https://main.example.test/account', 'GET');
        $otherPath->headers->set(InternalHomepageRequest::HEADER, $request->header(InternalHomepageRequest::HEADER));
        self::assertFalse($signer->valid($otherPath));

        $post = Request::create('https://main.example.test/', 'POST');
        $post->headers->set(InternalHomepageRequest::HEADER, $request->header(InternalHomepageRequest::HEADER));
        self::assertFalse($signer->valid($post));

        $request->headers->set(InternalHomepageRequest::HEADER, $signer->headerValue('main.example.test', time() - 31));
        self::assertFalse($signer->valid($request));

        $originalKey = config('app.key');
        config(['app.key' => '']);
        self::assertSame('', $signer->headerValue('main.example.test'));
        self::assertFalse($signer->valid($request));
        config(['app.key' => $originalKey]);
    }

    public function test_internal_homepage_signature_is_consumed_before_application_handlers(): void
    {
        $settings = app(BotShieldSettings::class);
        $engine   = new class($settings, app(NetworkIntelligenceClient::class), app(CrawlerVerifier::class)) extends BotRiskEngine
        {
            public function assess(Request $request): RiskAssessment
            {
                throw new \RuntimeException('A valid internal request must bypass risk assessment.');
            }
        };
        $signer     = app(InternalHomepageRequest::class);
        $middleware = new ProtectStorefront(
            $engine,
            $settings,
            app(ClearanceToken::class),
            $signer,
            app(MainSiteHomepage::class),
        );
        $request = Request::create('https://main.example.test/', 'GET');
        $request->headers->set(InternalHomepageRequest::HEADER, $signer->headerValue('main.example.test'));
        app()->instance('request', $request);

        $response = $middleware->handle($request, static function (Request $handled): Response {
            self::assertNull($handled->headers->get(InternalHomepageRequest::HEADER));

            return new Response('CONTROL HOME');
        });

        self::assertSame('CONTROL HOME', $response->getContent());
    }

    public function test_plugin_provider_discovers_the_tenant_aware_shop_middleware(): void
    {
        $provider    = new PluginServiceProvider(app());
        $middlewares = Closure::bind(function (): array {
            $this->pluginBasePath = base_path('plugins');

            return $this->loadMiddlewares(base_path('plugins/LongosBotShield/Middleware/Shop'));
        }, $provider, PluginServiceProvider::class)();

        self::assertContains(ProtectStorefront::class, $middlewares);
    }

    public function test_bot_shield_is_prepended_to_shop_and_api_middleware_groups(): void
    {
        $provider = new PluginServiceProvider(app());
        Closure::bind(function (): void {
            $this->pluginBasePath = base_path('plugins');
            $this->registerMiddleware('LongosBotShield', 'longos_bot_shield');
        }, $provider, PluginServiceProvider::class)();

        $expected = 'plugin.runtime:longos_bot_shield,' . ProtectStorefront::class;
        $groups   = app('router')->getMiddlewareGroups();
        self::assertSame($expected, $groups['shop'][0]);
        self::assertSame($expected, $groups['api'][0]);
    }

    public function test_api_requests_are_structured_and_health_or_valid_preflight_bypasses_are_narrow(): void
    {
        config(['bk.plugin.longos_bot_shield.enforcement_mode' => 'block']);
        $settings = app(BotShieldSettings::class);
        $engine   = new class($settings, app(NetworkIntelligenceClient::class), app(CrawlerVerifier::class)) extends BotRiskEngine
        {
            public function assess(Request $request): RiskAssessment
            {
                return new RiskAssessment(['ua_script_client']);
            }
        };
        $middleware = new ProtectStorefront(
            $engine,
            $settings,
            app(ClearanceToken::class),
            app(InternalHomepageRequest::class),
            app(MainSiteHomepage::class),
        );

        $api = Request::create('https://store.example.test/api/products', 'GET', [], [], [], [
            'REMOTE_ADDR'     => '8.8.8.8',
            'HTTP_USER_AGENT' => 'curl/8.0',
            'HTTP_ACCEPT'     => '*/*',
        ]);
        app()->instance('request', $api);
        $blocked = $middleware->handle($api, static fn (): Response => new Response('MUST NOT RUN'));
        self::assertSame(403, $blocked->getStatusCode());
        self::assertSame('application/problem+json', $blocked->headers->get('Content-Type'));

        $health = Request::create('https://store.example.test/api/health', 'GET');
        app()->instance('request', $health);
        self::assertSame(
            'HEALTHY',
            $middleware->handle($health, static fn (): Response => new Response('HEALTHY'))->getContent(),
        );

        $preflight = Request::create('https://store.example.test/api/products', 'OPTIONS', [], [], [], [
            'HTTP_ORIGIN'                        => 'https://store.example.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        app()->instance('request', $preflight);
        self::assertSame(
            'PREFLIGHT',
            $middleware->handle($preflight, static fn (): Response => new Response('PREFLIGHT'))->getContent(),
        );

        $forgedOptions = Request::create('https://store.example.test/api/products', 'OPTIONS');
        app()->instance('request', $forgedOptions);
        self::assertSame(
            403,
            $middleware->handle($forgedOptions, static fn (): Response => new Response('MUST NOT RUN'))->getStatusCode(),
        );
    }

    public function test_expired_database_cache_and_lock_rows_are_pruned_in_bounded_batches(): void
    {
        config([
            'database.connections.bot_shield_cache_test' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
            'database.connections.bot_shield_cache_lock_test' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
            'cache.default'                    => 'bot_shield_database',
            'cache.stores.bot_shield_database' => [
                'driver'          => 'database',
                'connection'      => 'bot_shield_cache_test',
                'table'           => 'cache',
                'lock_connection' => 'bot_shield_cache_lock_test',
                'lock_table'      => 'cache_locks',
            ],
        ]);
        DB::purge('bot_shield_cache_test');
        DB::purge('bot_shield_cache_lock_test');
        Schema::connection('bot_shield_cache_test')->create('cache', static function ($table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        Schema::connection('bot_shield_cache_lock_test')->create('cache_locks', static function ($table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
        DB::connection('bot_shield_cache_test')->table('cache')->insert([
            ['key' => 'expired', 'value' => 'x', 'expiration' => time() - 1],
            ['key' => 'live', 'value' => 'x', 'expiration' => time() + 3600],
        ]);
        DB::connection('bot_shield_cache_lock_test')->table('cache_locks')->insert([
            ['key' => 'expired-lock', 'owner' => 'x', 'expiration' => time() - 1],
            ['key' => 'live-lock', 'owner' => 'x', 'expiration' => time() + 3600],
        ]);

        self::assertSame(0, Artisan::call('longos:cache:prune-expired', ['--batch' => 100, '--passes' => 2]));
        self::assertSame(['live'], DB::connection('bot_shield_cache_test')->table('cache')->pluck('key')->all());
        self::assertSame(['live-lock'], DB::connection('bot_shield_cache_lock_test')->table('cache_locks')->pluck('key')->all());
        self::assertStringContainsString(
            'saas:run longos:cache:prune-expired --continue-on-error',
            (string) file_get_contents(base_path('app/Console/Kernel.php')),
        );

        DB::purge('bot_shield_cache_test');
        DB::purge('bot_shield_cache_lock_test');
    }

    public function test_main_site_homepage_fetch_never_forwards_visitor_state(): void
    {
        config(['saas.enabled' => true]);
        $child = new Site([
            'status' => Site::STATUS_ACTIVE,
            'meta'   => ['control_site' => false],
        ]);
        $child->id = 22;
        app()->instance((string) config('multitenancy.current_tenant_container_key'), $child);

        $control = new Site([
            'status' => Site::STATUS_ACTIVE,
            'meta'   => ['control_site' => true],
        ]);
        $control->id = 1;
        $control->setRelation('domains', collect([new Domain([
            'host'             => 'main.example.test',
            'scheme'           => 'https',
            'is_primary'       => true,
            'routing_behavior' => Domain::ROUTING_REDIRECT,
            'status'           => Domain::STATUS_ACTIVE,
        ])]));

        $homepageRequests = 0;
        Http::fake([
            'https://main.example.test/' => static function () use (&$homepageRequests) {
                $homepageRequests++;
                if ($homepageRequests > 1) {
                    return Http::response('temporary failure', 503, ['Content-Type' => 'text/plain']);
                }

                return Http::response('<!doctype html><html><head><base href="https://untrusted.example/"><title>Control homepage</title></head></html>', 200, [
                    'Content-Type' => 'text/html; charset=UTF-8',
                    'Set-Cookie'   => 'control_session=must-not-be-forwarded',
                ]);
            },
        ]);
        $internal = app(InternalHomepageRequest::class);
        $service  = new class($internal, $control) extends MainSiteHomepage
        {
            public int $controlLookups = 0;

            public function __construct(InternalHomepageRequest $internal, private readonly Site $site)
            {
                parent::__construct($internal);
            }

            protected function controlSite(): ?Site
            {
                $this->controlLookups++;

                return $this->site;
            }
        };

        $result = $service->render();
        $cached = $service->render();
        Cache::forget('longos:bot-shield:main-home:1:' . hash('sha256', 'https://main.example.test/'));
        $stale = $service->render();

        self::assertSame('control', $result['source']);
        self::assertSame('control-cache', $cached['source']);
        self::assertSame('control-cache-stale', $stale['source']);
        self::assertSame($result['html'], $cached['html']);
        self::assertSame($result['html'], $stale['html']);
        self::assertStringContainsString('Control homepage', $result['html']);
        self::assertStringContainsString('<base data-longos-bot-shield="1" href="https://main.example.test/">', $result['html']);
        self::assertSame(1, $service->controlLookups);
        self::assertLessThan(
            strpos($result['html'], '<base href="https://untrusted.example/">'),
            strpos($result['html'], '<base data-longos-bot-shield="1" href="https://main.example.test/">'),
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
        Http::assertSentCount(3);
    }

    public function test_child_store_fails_closed_when_control_site_homepage_is_unavailable(): void
    {
        config(['saas.enabled' => true]);
        $child = new Site([
            'status' => Site::STATUS_ACTIVE,
            'meta'   => ['control_site' => false],
        ]);
        $child->id = 23;
        app()->instance((string) config('multitenancy.current_tenant_container_key'), $child);

        $service = new class(app(InternalHomepageRequest::class)) extends MainSiteHomepage
        {
            public int $controlLookups = 0;

            protected function controlSite(): ?Site
            {
                $this->controlLookups++;

                return null;
            }
        };

        self::assertNull($service->render());
        self::assertNull($service->render());
        self::assertSame(1, $service->controlLookups);
    }

    public function test_main_site_homepage_rejects_nonstandard_control_origin_before_network(): void
    {
        config(['saas.enabled' => true]);
        $child     = new Site(['status' => Site::STATUS_ACTIVE, 'meta' => ['control_site' => false]]);
        $child->id = 24;
        app()->instance((string) config('multitenancy.current_tenant_container_key'), $child);

        $control     = new Site(['status' => Site::STATUS_ACTIVE, 'meta' => ['control_site' => true]]);
        $control->id = 2;
        $control->setRelation('domains', collect([new Domain([
            'host'             => 'main.example.test:8443',
            'scheme'           => 'https',
            'is_primary'       => true,
            'routing_behavior' => Domain::ROUTING_REDIRECT,
            'status'           => Domain::STATUS_ACTIVE,
        ])]));
        Http::fake();
        $service = new class(app(InternalHomepageRequest::class), $control) extends MainSiteHomepage
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

        self::assertNull($service->render());
        Http::assertNothingSent();
    }

    public function test_saas_request_without_a_resolved_tenant_cannot_render_a_default_homepage(): void
    {
        config(['saas.enabled' => true]);
        app()->forgetInstance((string) config('multitenancy.current_tenant_container_key'));

        self::assertNull(app(MainSiteHomepage::class)->render());
    }

    public function test_browser_challenge_uses_a_one_time_proof_and_httponly_clearance_cookie(): void
    {
        config(['bk.plugin.longos_bot_shield.enforcement_mode' => 'challenge']);
        app('view')->addNamespace('LongosBotShield', base_path('plugins/LongosBotShield/Views'));
        app('translator')->addNamespace('LongosBotShield', base_path('plugins/LongosBotShield/Lang'));

        $settings = app(BotShieldSettings::class);
        $engine   = new class($settings, app(NetworkIntelligenceClient::class), app(CrawlerVerifier::class)) extends BotRiskEngine
        {
            public function assess(Request $request): RiskAssessment
            {
                return new RiskAssessment(['hosting_network']);
            }
        };
        $clearance  = app(ClearanceToken::class);
        $middleware = new ProtectStorefront(
            $engine,
            $settings,
            $clearance,
            app(InternalHomepageRequest::class),
            app(MainSiteHomepage::class),
        );
        $request = $this->request('8.8.8.8', $this->chromeUserAgent(), '/products/challenge');
        app()->instance('request', $request);

        $challenge = $middleware->handle($request, static fn (): Response => new Response('ORIGINAL'));
        $html      = (string) $challenge->getContent();

        self::assertSame(503, $challenge->getStatusCode());
        self::assertStringNotContainsString('longos_bot_clearance=', $html);
        self::assertMatchesRegularExpression('/<script nonce="[A-Za-z0-9_-]+">/', $html);
        self::assertStringContainsString("connect-src 'self'", (string) $challenge->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('new AbortController()', $html);
        self::assertSame(1, preg_match('/const token = ("(?:\\\\.|[^"\\\\])+");/', $html, $match));
        $proof = json_decode($match[1], true, 8, JSON_THROW_ON_ERROR);
        self::assertIsString($proof);
        self::assertStringNotContainsString('crypto.subtle', $html);
        self::assertStringNotContainsString('powChallenge', $html);

        $wrongTarget = Request::create('https://store.example.test/products/other', 'GET', [], [], [], [
            'REMOTE_ADDR'                       => '8.8.8.8',
            'HTTP_USER_AGENT'                   => $this->chromeUserAgent(),
            'HTTP_ACCEPT'                       => 'application/json',
            'HTTP_X_LONGOS_BOT_CHALLENGE_PROOF' => $proof,
        ]);
        app()->instance('request', $wrongTarget);
        self::assertSame(
            403,
            $middleware->handle($wrongTarget, static fn (): Response => new Response('MUST NOT RUN'))->getStatusCode(),
        );

        $proofRequest = Request::create('https://store.example.test/products/challenge', 'GET', [], [], [], [
            'REMOTE_ADDR'                       => '8.8.8.8',
            'HTTP_USER_AGENT'                   => $this->chromeUserAgent(),
            'HTTP_ACCEPT'                       => 'application/json',
            'HTTP_X_LONGOS_BOT_CHALLENGE_PROOF' => $proof,
        ]);
        app()->instance('request', $proofRequest);
        $cleared = $middleware->handle($proofRequest, static fn (): Response => new Response('MUST NOT RUN'));

        self::assertSame(204, $cleared->getStatusCode());
        self::assertSame('challenge-cleared', $cleared->headers->get('X-Longos-Bot-Shield'));
        $cookie = collect($cleared->headers->getCookies())
            ->first(static fn ($candidate): bool => $candidate->getName() === ClearanceToken::COOKIE);
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame('lax', $cookie->getSameSite());

        $proofRequest->cookies->set(ClearanceToken::COOKIE, $cookie->getValue());
        self::assertTrue($clearance->valid($proofRequest));

        $replay = Request::create('https://store.example.test/products/challenge', 'GET', [], [], [], [
            'REMOTE_ADDR'                       => '8.8.8.8',
            'HTTP_USER_AGENT'                   => $this->chromeUserAgent(),
            'HTTP_ACCEPT'                       => 'application/json',
            'HTTP_X_LONGOS_BOT_CHALLENGE_PROOF' => $proof,
        ]);
        app()->instance('request', $replay);
        self::assertSame(
            403,
            $middleware->handle($replay, static fn (): Response => new Response('MUST NOT RUN'))->getStatusCode(),
        );
    }

    public function test_challenge_clearance_allows_non_cacheable_network_scope_requests(): void
    {
        config(['bk.plugin.longos_bot_shield.enforcement_mode' => 'challenge']);

        $settings = app(BotShieldSettings::class);
        $engine   = new class($settings, app(NetworkIntelligenceClient::class), app(CrawlerVerifier::class)) extends BotRiskEngine
        {
            public function assess(Request $request): RiskAssessment
            {
                return new RiskAssessment(['hosting_network']);
            }
        };
        $clearance  = app(ClearanceToken::class);
        $middleware = new ProtectStorefront(
            $engine,
            $settings,
            $clearance,
            app(InternalHomepageRequest::class),
            app(MainSiteHomepage::class),
        );
        $verified = $this->request('8.8.8.8', $this->chromeUserAgent(), '/products/verified');
        app()->instance('request', $verified);
        $token = $clearance->issue($verified);

        $post = Request::create('https://store.example.test/checkout', 'POST', [], [
            ClearanceToken::COOKIE => $token,
        ], [], [
            'REMOTE_ADDR'     => '8.8.8.8',
            'HTTP_USER_AGENT' => $this->chromeUserAgent(),
            'HTTP_ACCEPT'     => 'application/json',
        ]);
        app()->instance('request', $post);

        $response = $middleware->handle($post, static fn (): Response => new Response('CHECKOUT ACCEPTED'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('CHECKOUT ACCEPTED', $response->getContent());
        self::assertNull($response->headers->get('X-Longos-Bot-Shield'));

        $post->cookies->remove(ClearanceToken::COOKIE);
        $blocked = $middleware->handle($post, static fn (): Response => new Response('MUST NOT RUN'));

        self::assertSame(403, $blocked->getStatusCode());
        self::assertSame('blocked', $blocked->headers->get('X-Longos-Bot-Shield'));
    }

    public function test_homepage_shielding_scope_covers_network_crawler_and_automation_targets(): void
    {
        $scopeReasons = [
            'ip_denylist',
            'asn_denylist',
            'organization_keyword',
            'hosting_network',
            'residential_proxy',
            'anonymous_network',
            'crawler_declared',
            'crawler_spoofed',
            'crawler_verified',
            'ua_generic_crawler',
            'ua_automation',
            'ua_script_client',
            'client_hints_automation',
            'automation_header',
        ];
        foreach ($scopeReasons as $reason) {
            self::assertTrue(
                (new RiskAssessment([$reason]))->matchesConfiguredShieldingScope(),
                $reason,
            );
        }

        foreach (['client_hints_missing', 'accept_missing', 'rate_limit'] as $reason) {
            self::assertFalse(
                (new RiskAssessment([$reason]))->matchesConfiguredShieldingScope(),
                $reason,
            );
        }
    }

    public function test_homepage_mode_replaces_html_without_redirecting_or_changing_url(): void
    {
        config(['bk.plugin.longos_bot_shield.diagnostic_log' => true]);
        $logger = new class
        {
            public array $entries = [];

            public function notice(string $message, array $context = []): void
            {
                $this->entries[] = compact('message', 'context');
            }
        };
        Log::swap($logger);
        $settings = app(BotShieldSettings::class);
        $internal = app(InternalHomepageRequest::class);
        $engine   = new class($settings, app(NetworkIntelligenceClient::class), app(CrawlerVerifier::class)) extends BotRiskEngine
        {
            public function assess(Request $request): RiskAssessment
            {
                return new RiskAssessment(['asn_denylist']);
            }
        };
        $homepage = new class($internal) extends MainSiteHomepage
        {
            public function render(): ?array
            {
                return ['html' => '<!doctype html><title>LONGOS Main</title>', 'source' => 'control'];
            }
        };
        $middleware = new ProtectStorefront(
            $engine,
            $settings,
            app(ClearanceToken::class),
            $internal,
            $homepage,
        );
        $request = $this->request('8.8.4.4', 'facebookexternalhit/1.1', '/products/private-offer');
        $request->headers->set('X-Longos-Bot-Challenge-Proof', 'stale-proof-from-previous-mode');
        app()->instance('request', $request);

        $response = (new ApplySecurityHeaders)->handle(
            $request,
            static fn (): Response => $middleware->handle(
                $request,
                static fn (): Response => new Response('ORIGINAL'),
            ),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('LONGOS Main', (string) $response->getContent());
        self::assertStringNotContainsString('ORIGINAL', (string) $response->getContent());
        self::assertSame('homepage', $response->headers->get('X-Longos-Bot-Shield'));
        self::assertSame('control', $response->headers->get('X-Longos-Bot-Homepage-Source'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame("sandbox; frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertStringContainsString('payment=()', $response->headers->get('Permissions-Policy'));
        self::assertCount(1, $logger->entries);
        self::assertSame('LONGOS Bot Shield evaluated a storefront request.', $logger->entries[0]['message']);
        self::assertSame($response->headers->get('X-Longos-Bot-Reference'), $logger->entries[0]['context']['reference']);
        self::assertArrayNotHasKey('ip', $logger->entries[0]['context']);
        self::assertSame('/products/private-offer', $request->getPathInfo());
        self::assertFalse($response->isRedirect());
    }

    public function test_homepage_mode_ignores_signals_outside_the_configured_shielding_scope(): void
    {
        $settings = app(BotShieldSettings::class);
        $internal = app(InternalHomepageRequest::class);
        $engine   = new class($settings, app(NetworkIntelligenceClient::class), app(CrawlerVerifier::class)) extends BotRiskEngine
        {
            public function assess(Request $request): RiskAssessment
            {
                return new RiskAssessment(['client_hints_missing']);
            }
        };
        $middleware = new ProtectStorefront(
            $engine,
            $settings,
            app(ClearanceToken::class),
            $internal,
            new class($internal) extends MainSiteHomepage
            {
                public function render(): ?array
                {
                    throw new \RuntimeException('Medium-risk requests must not fetch the main-site homepage.');
                }
            },
        );
        $request = $this->request('8.8.4.4', $this->chromeUserAgent(), '/products/normal-offer');
        app()->instance('request', $request);

        $response = $middleware->handle($request, static fn (): Response => new Response('ORIGINAL STOREFRONT'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ORIGINAL STOREFRONT', $response->getContent());
        self::assertNull($response->headers->get('X-Longos-Bot-Shield'));
    }

    public function test_homepage_mode_replaces_structured_mutating_requests_in_the_configured_scope(): void
    {
        $settings = app(BotShieldSettings::class);
        $internal = app(InternalHomepageRequest::class);
        $engine   = new class($settings, app(NetworkIntelligenceClient::class), app(CrawlerVerifier::class)) extends BotRiskEngine
        {
            public function assess(Request $request): RiskAssessment
            {
                return new RiskAssessment(['organization_keyword']);
            }
        };
        $middleware = new ProtectStorefront(
            $engine,
            $settings,
            app(ClearanceToken::class),
            $internal,
            new class($internal) extends MainSiteHomepage
            {
                public function render(): ?array
                {
                    return ['html' => '<!doctype html><title>LONGOS Main</title>', 'source' => 'control'];
                }
            },
        );
        $request = Request::create('https://store.example.test/checkout', 'POST', [], [], [], [
            'REMOTE_ADDR'     => '8.8.4.4',
            'HTTP_USER_AGENT' => 'Playwright HeadlessChrome',
            'HTTP_ACCEPT'     => 'application/json',
        ]);
        app()->instance('request', $request);

        $response = $middleware->handle($request, static fn (): Response => new Response('ORIGINAL'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('LONGOS Main', (string) $response->getContent());
        self::assertSame('homepage', $response->headers->get('X-Longos-Bot-Shield'));
        self::assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
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

    private function residentialNetwork(string $ip = '8.8.8.8'): array
    {
        return [
            'ip' => $ip,
            'as' => [
                'asn'    => 'AS7922',
                'name'   => 'Comcast Cable Communications',
                'domain' => 'comcast.net',
                'type'   => 'isp',
            ],
            'is_hosting'   => false,
            'is_anonymous' => false,
        ];
    }

    private function radarAsn(string $asn, string $name, string $website = ''): array
    {
        return [
            'success' => true,
            'result'  => [
                'asn' => [
                    'asn'     => (int) preg_replace('/^AS/i', '', $asn),
                    'name'    => $name,
                    'orgName' => $name,
                    'website' => $website,
                    'country' => 'US',
                    'source'  => 'ARIN',
                ],
            ],
        ];
    }
}
