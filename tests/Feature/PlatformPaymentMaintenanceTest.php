<?php

namespace Tests\Feature;

use App\Saas\Models\Domain;
use App\Saas\Models\PlatformPaymentSession;
use App\Saas\Models\Site;
use App\Saas\TenantManager;
use App\Services\PlatformPayment\PlatformPaymentOperations;
use App\Services\PlatformPayment\PlatformPaymentSessionService;
use App\Services\PlatformPayment\PlatformPaymentSettings;
use Beike\Repositories\SettingRepo;
use Beike\Services\TimezoneService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Plugin\LongosPlatformPay\Services\PlatformPaymentCredentials;
use RuntimeException;

class PlatformPaymentMaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config([
            'saas.enabled'              => true,
            'database.default'          => 'saas',
            'database.connections.saas' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('saas');
        DB::setDefaultConnection('saas');
        self::assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]));
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_maintenance_expires_abandoned_pending_sessions_and_prunes_old_terminal_sessions(): void
    {
        self::assertTrue(Schema::connection('saas')->hasIndex(
            'saas_platform_payment_sessions',
            'spp_provider_status_created_idx'
        ));
        self::assertTrue(Schema::connection('saas')->hasIndex(
            'saas_platform_payment_sessions',
            'spp_site_status_created_idx'
        ));

        $site = Site::query()->create([
            'uuid'          => '11111111-1111-4111-8111-111111111111',
            'code'          => 'payment-maintenance',
            'name'          => 'Payment Maintenance',
            'database_name' => 'payment_maintenance',
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'en',
            'timezone'      => 'UTC',
            'meta'          => [],
        ]);

        $pending = $this->session($site, [
            'uuid'       => '22222222-2222-4222-8222-222222222222',
            'token_hash' => str_repeat('a', 64),
            'active_key' => str_repeat('b', 64),
            'status'     => PlatformPaymentSession::STATUS_PENDING,
            'expires_at' => now()->subMinute(),
        ]);
        $completed = $this->session($site, [
            'uuid'         => '33333333-3333-4333-8333-333333333333',
            'token_hash'   => str_repeat('c', 64),
            'active_key'   => null,
            'status'       => PlatformPaymentSession::STATUS_COMPLETED,
            'expires_at'   => now()->subDays(400),
            'completed_at' => now()->subDays(400),
            'created_at'   => now()->subDays(400),
            'updated_at'   => now()->subDays(400),
        ]);
        $staleProviderLock = $this->session($site, [
            'uuid'              => '34343434-3434-4343-8343-343434343434',
            'token_hash'        => str_repeat('4', 64),
            'active_key'        => str_repeat('5', 64),
            'provider'          => 'paypal',
            'provider_order_id' => 'STALEORDER123456',
            'status'            => PlatformPaymentSession::STATUS_EXPIRED,
            'expires_at'        => now()->subDays(5),
            'created_at'        => now()->subDays(5),
            'updated_at'        => now()->subDays(5),
        ]);

        self::assertSame(0, Artisan::call('saas:platform-pay:reconcile', [
            '--limit'      => 10,
            '--prune-days' => 365,
        ]), Artisan::output());

        self::assertSame(PlatformPaymentSession::STATUS_EXPIRED, $pending->fresh()->status);
        self::assertNull($pending->fresh()->active_key);
        self::assertNull($completed->fresh());
        self::assertNull($staleProviderLock->fresh()->active_key);

        $oldStripe = $this->session($site, [
            'uuid'              => '56565656-5656-4565-8565-565656565656',
            'token_hash'        => str_repeat('6', 64),
            'active_key'        => str_repeat('7', 64),
            'provider'          => 'stripe',
            'provider_order_id' => 'pi_old_unresolved_12345678',
            'status'            => PlatformPaymentSession::STATUS_EXPIRED,
            'expires_at'        => now()->subDays(5),
            'created_at'        => now()->subDays(5),
            'updated_at'        => now()->subDays(5),
        ]);
        self::assertTrue($oldStripe->isFinanciallyRecoverable());
        self::assertTrue(PlatformPaymentSession::query()->financiallyUnsettled()->whereKey($oldStripe->id)->exists());
    }

    public function test_control_dashboard_receives_an_aggregate_payment_operations_snapshot(): void
    {
        $control = Site::query()->create([
            'uuid'          => '12121212-1212-4212-8212-121212121212',
            'code'          => 'payment-control-dashboard',
            'name'          => 'Payment Control Dashboard',
            'database_name' => 'payment_control_dashboard',
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'en',
            'timezone'      => 'UTC',
            'meta'          => ['control_site' => true],
        ]);
        $child = Site::query()->create([
            'uuid'          => '13131313-1313-4313-8313-131313131313',
            'code'          => 'payment-child-dashboard',
            'name'          => 'Payment Child Dashboard',
            'database_name' => 'payment_child_dashboard',
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'en',
            'timezone'      => 'UTC',
            'meta'          => [],
        ]);
        $otherChild = Site::query()->create([
            'uuid'          => '16161616-1616-4616-8616-161616161616',
            'code'          => 'payment-other-child-dashboard',
            'name'          => 'Other Payment Child Dashboard',
            'database_name' => 'payment_other_child_dashboard',
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'en',
            'timezone'      => 'UTC',
            'meta'          => [],
        ]);

        $this->session($child, [
            'uuid'                    => '14141414-1414-4414-8414-141414141414',
            'token_hash'              => str_repeat('1', 64),
            'active_key'              => null,
            'provider'                => 'paypal',
            'provider_order_id'       => 'PAYPALORDER141414',
            'provider_transaction_id' => 'CAPTURE141414',
            'status'                  => PlatformPaymentSession::STATUS_COMPLETED,
            'completed_at'            => now(),
        ]);
        $this->session($child, [
            'uuid'              => '15151515-1515-4515-8515-151515151515',
            'token_hash'        => str_repeat('2', 64),
            'active_key'        => str_repeat('3', 64),
            'provider'          => 'stripe',
            'provider_order_id' => 'pi_processing_15151515',
            'status'            => PlatformPaymentSession::STATUS_PROCESSING,
        ]);
        $this->session($otherChild, [
            'uuid'       => '17171717-1717-4717-8717-171717171717',
            'token_hash' => str_repeat('4', 64),
            'active_key' => str_repeat('5', 64),
            'status'     => PlatformPaymentSession::STATUS_PENDING,
        ]);

        $containerKey = (string) config('multitenancy.current_tenant_container_key', 'currentTenant');
        app()->instance($containerKey, $control);
        $settings = $this->createMock(PlatformPaymentSettings::class);
        $settings->method('snapshot')->willReturn(['enabled' => true]);
        $settings->method('availableProvidersForSnapshot')->willReturn(['paypal', 'stripe']);

        $snapshot = (new PlatformPaymentOperations($settings))->snapshot([now()->subHour(), now()->addHour()]);

        self::assertTrue($snapshot['available']);
        self::assertSame('all_sites', $snapshot['scope']);
        self::assertSame('ready', $snapshot['state']);
        self::assertSame(3, $snapshot['started']);
        self::assertSame(1, $snapshot['completed']);
        self::assertSame(1, $snapshot['processing']);
        self::assertSame(33.3, $snapshot['completion_rate']);
        self::assertSame(['paypal' => 1], $snapshot['by_provider']);

        app()->instance($containerKey, $child);
        $childSnapshot = (new PlatformPaymentOperations($settings))->snapshot([now()->subHour(), now()->addHour()]);

        self::assertSame('current_site', $childSnapshot['scope']);
        self::assertSame(2, $childSnapshot['started']);
        self::assertSame(1, $childSnapshot['completed']);
        self::assertSame(50.0, $childSnapshot['completion_rate']);
    }

    public function test_central_payment_timestamps_remain_absolute_across_tenant_timezones(): void
    {
        $site = Site::query()->create([
            'uuid'          => '18181818-1818-4818-8818-181818181818',
            'code'          => 'payment-timezone-child',
            'name'          => 'Payment Timezone Child',
            'database_name' => 'payment_timezone_child',
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'en',
            'timezone'      => 'Asia/Shanghai',
            'meta'          => [],
        ]);

        try {
            TimezoneService::apply('Asia/Shanghai');
            $expires = now()->addHours(3);
            $session = $this->session($site, [
                'uuid'           => '19191919-1919-4919-8919-191919191919',
                'token_hash'     => str_repeat('6', 64),
                'active_key'     => str_repeat('7', 64),
                'status'         => PlatformPaymentSession::STATUS_PENDING,
                'timestamps_utc' => true,
                'expires_at'     => $expires,
            ]);

            self::assertSame(
                $expires->copy()->utc()->format('Y-m-d H:i:s'),
                DB::connection('saas')->table('saas_platform_payment_sessions')->where('id', $session->id)->value('expires_at'),
            );

            TimezoneService::apply('America/Los_Angeles');
            $reloaded = PlatformPaymentSession::query()->findOrFail($session->id);
            self::assertSame($expires->timestamp, $reloaded->expires_at->timestamp);
            self::assertTrue($reloaded->isOpen());
        } finally {
            TimezoneService::apply('UTC');
        }
    }

    public function test_payment_dashboard_uses_the_current_sites_local_day_boundary(): void
    {
        $site = Site::query()->create([
            'uuid'          => '22222222-2222-4222-8222-222222222220',
            'code'          => 'payment-dashboard-timezone',
            'name'          => 'Payment Dashboard Timezone',
            'database_name' => 'payment_dashboard_timezone',
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'en',
            'timezone'      => 'America/Los_Angeles',
            'meta'          => [],
        ]);
        $containerKey = (string) config('multitenancy.current_tenant_container_key', 'currentTenant');
        app()->instance($containerKey, $site);
        $settings = $this->createMock(PlatformPaymentSettings::class);
        $settings->method('snapshot')->willReturn(['enabled' => true]);
        $settings->method('availableProvidersForSnapshot')->willReturn(['paypal']);

        try {
            TimezoneService::apply('America/Los_Angeles');
            foreach ([
                ['23', '2026-01-01 07:59:59'],
                ['24', '2026-01-01 08:00:00'],
                ['25', '2026-01-02 07:59:59'],
                ['26', '2026-01-02 08:00:00'],
            ] as [$suffix, $createdAt]) {
                $this->session($site, [
                    'uuid'       => "23232323-2323-4323-8323-2323232323{$suffix}",
                    'token_hash' => str_repeat(substr($suffix, -1), 64),
                    'active_key' => hash('sha256', "dashboard-boundary-{$suffix}"),
                    'status'     => PlatformPaymentSession::STATUS_PENDING,
                    'created_at' => Carbon::parse($createdAt, 'UTC'),
                    'updated_at' => Carbon::parse($createdAt, 'UTC'),
                ]);
            }

            $snapshot = (new PlatformPaymentOperations($settings))->snapshot([
                Carbon::parse('2026-01-01 00:00:00', 'America/Los_Angeles'),
                Carbon::parse('2026-01-01 23:59:59', 'America/Los_Angeles'),
            ]);

            self::assertTrue($snapshot['available']);
            self::assertSame(2, $snapshot['started']);
            self::assertSame(0, $snapshot['completed']);
        } finally {
            TimezoneService::apply('UTC');
        }
    }

    public function test_utc_migration_converts_legacy_child_session_dates_once(): void
    {
        $site = Site::query()->create([
            'uuid'          => '20202020-2020-4020-8020-202020202020',
            'code'          => 'payment-timezone-migration',
            'name'          => 'Payment Timezone Migration',
            'database_name' => 'payment_timezone_migration',
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'en',
            'timezone'      => 'Asia/Shanghai',
            'meta'          => [],
        ]);
        DB::connection('saas')->table('saas_platform_payment_sessions')->insert([
            'uuid'           => '21212121-2121-4121-8121-212121212121',
            'token_hash'     => str_repeat('8', 64),
            'active_key'     => null,
            'site_id'        => $site->id,
            'order_id'       => 21,
            'order_number'   => 'TZ-21',
            'amount'         => '21.00000000',
            'currency'       => 'USD',
            'locale'         => 'en',
            'timestamps_utc' => false,
            'status'         => PlatformPaymentSession::STATUS_COMPLETED,
            'provider'       => 'paypal',
            'expires_at'     => '2026-01-01 13:00:00',
            'completed_at'   => '2026-01-01 11:00:00',
            'created_at'     => '2026-01-01 10:00:00',
            'updated_at'     => '2026-01-01 11:00:00',
        ]);

        $migration = require database_path('migrations/2026_10_26_020000_normalize_platform_payment_timestamps_to_utc.php');
        $migration->up();
        $stored = DB::connection('saas')->table('saas_platform_payment_sessions')
            ->where('uuid', '21212121-2121-4121-8121-212121212121')
            ->first();

        self::assertSame(1, (int) $stored->timestamps_utc);
        self::assertSame('2026-01-01 02:00:00', $stored->created_at);
        self::assertSame('2026-01-01 05:00:00', $stored->expires_at);
        self::assertSame('2026-01-01 03:00:00', $stored->completed_at);

        $migration->up();
        self::assertSame(
            '2026-01-01 02:00:00',
            DB::connection('saas')->table('saas_platform_payment_sessions')
                ->where('uuid', '21212121-2121-4121-8121-212121212121')
                ->value('created_at'),
        );
    }

    public function test_only_an_unbound_provider_claim_can_be_released(): void
    {
        $site = Site::query()->create([
            'uuid'          => '44444444-4444-4444-8444-444444444444',
            'code'          => 'payment-claim-release',
            'name'          => 'Payment Claim Release',
            'database_name' => 'payment_claim_release',
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'en',
            'timezone'      => 'UTC',
            'meta'          => [],
        ]);

        $unbound = $this->session($site, [
            'uuid'       => '55555555-5555-4555-8555-555555555555',
            'token_hash' => str_repeat('d', 64),
            'active_key' => str_repeat('e', 64),
            'provider'   => 'paypal',
            'status'     => PlatformPaymentSession::STATUS_PROCESSING,
        ]);
        $bound = $this->session($site, [
            'uuid'              => '66666666-6666-4666-8666-666666666666',
            'token_hash'        => str_repeat('f', 64),
            'active_key'        => str_repeat('0', 64),
            'provider'          => 'stripe',
            'provider_order_id' => 'pi_bound_12345678',
            'status'            => PlatformPaymentSession::STATUS_PROCESSING,
        ]);

        $service = app(PlatformPaymentSessionService::class);
        $service->releaseUnboundProviderClaim($unbound, 'paypal');
        $service->releaseUnboundProviderClaim($bound, 'stripe');

        self::assertSame(PlatformPaymentSession::STATUS_PENDING, $unbound->fresh()->status);
        self::assertNull($unbound->fresh()->provider);
        self::assertNotNull($unbound->fresh()->active_key);
        self::assertSame(PlatformPaymentSession::STATUS_PROCESSING, $bound->fresh()->status);
        self::assertSame('stripe', $bound->fresh()->provider);
        self::assertSame('pi_bound_12345678', $bound->fresh()->provider_order_id);
    }

    public function test_maintenance_recovers_stale_unbound_claims_after_a_worker_interruption(): void
    {
        $site = Site::query()->create([
            'uuid'          => '45454545-4545-4545-8545-454545454545',
            'code'          => 'payment-stale-claim',
            'name'          => 'Payment Stale Claim',
            'database_name' => 'payment_stale_claim',
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'en',
            'timezone'      => 'UTC',
            'meta'          => [],
        ]);
        $retryable = $this->session($site, [
            'uuid'       => '46464646-4646-4646-8646-464646464646',
            'token_hash' => str_repeat('a', 63) . '1',
            'active_key' => str_repeat('b', 63) . '1',
            'provider'   => 'paypal',
            'status'     => PlatformPaymentSession::STATUS_PROCESSING,
            'updated_at' => now()->subMinutes(6),
        ]);
        $expired = $this->session($site, [
            'uuid'       => '47474747-4747-4747-8747-474747474747',
            'token_hash' => str_repeat('a', 63) . '2',
            'active_key' => str_repeat('b', 63) . '2',
            'provider'   => 'stripe',
            'status'     => PlatformPaymentSession::STATUS_PROCESSING,
            'expires_at' => now()->subMinute(),
            'updated_at' => now()->subMinutes(6),
        ]);

        self::assertSame(0, Artisan::call('saas:platform-pay:reconcile', ['--limit' => 10]), Artisan::output());

        self::assertSame(PlatformPaymentSession::STATUS_PENDING, $retryable->fresh()->status);
        self::assertNull($retryable->fresh()->provider);
        self::assertNotNull($retryable->fresh()->active_key);
        self::assertSame(PlatformPaymentSession::STATUS_EXPIRED, $expired->fresh()->status);
        self::assertNull($expired->fresh()->provider);
        self::assertNull($expired->fresh()->active_key);
    }

    public function test_platform_payment_urls_always_use_the_configured_primary_control_domain(): void
    {
        $site = Site::query()->create([
            'uuid'          => '48484848-4848-4848-8848-484848484848',
            'code'          => 'payment-control-domain',
            'name'          => 'Payment Control Domain',
            'database_name' => 'payment_control_domain',
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'en',
            'timezone'      => 'UTC',
            'meta'          => ['control_site' => true],
        ]);
        Domain::query()->create([
            'site_id'          => $site->id,
            'host'             => 'old-pay.example.test',
            'scheme'           => 'https',
            'is_primary'       => false,
            'routing_behavior' => Domain::ROUTING_REDIRECT,
            'status'           => Domain::STATUS_ACTIVE,
            'origin'           => Domain::ORIGIN_PLATFORM,
        ]);
        Domain::query()->create([
            'site_id'          => $site->id,
            'host'             => 'pay.example.test',
            'scheme'           => 'https',
            'is_primary'       => true,
            'routing_behavior' => Domain::ROUTING_ALIAS,
            'status'           => Domain::STATUS_ACTIVE,
            'origin'           => Domain::ORIGIN_PLATFORM,
        ]);
        config(['saas.control_domains' => ['old-pay.example.test', 'pay.example.test']]);

        $settings = app(PlatformPaymentSettings::class);
        self::assertSame(
            'https://pay.example.test/_longos/pay/webhooks/stripe',
            $settings->mainUrl('_longos/pay/webhooks/stripe')
        );

        config(['saas.control_domains' => ['old-pay.example.test']]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SAAS_CONTROL_DOMAINS');
        $settings->mainUrl('_longos/pay/webhooks/stripe');
    }

    public function test_provider_account_rotation_is_blocked_during_the_reconciliation_window(): void
    {
        $site = Site::query()->create([
            'uuid'          => '77777777-7777-4777-8777-777777777777',
            'code'          => 'payment-rotation-guard',
            'name'          => 'Payment Rotation Guard',
            'database_name' => 'payment_rotation_guard',
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'en',
            'timezone'      => 'UTC',
            'meta'          => [],
        ]);
        $session = $this->session($site, [
            'uuid'              => '88888888-8888-4888-8888-888888888888',
            'token_hash'        => str_repeat('1', 64),
            'active_key'        => str_repeat('2', 64),
            'provider'          => 'paypal',
            'provider_order_id' => 'PAYPALORDER123',
            'status'            => PlatformPaymentSession::STATUS_PROCESSING,
        ]);
        $current = [
            'enabled'      => true,
            'share_paypal' => true,
            'share_stripe' => false,
            'paypal'       => [
                'api_mode' => 'rest', 'mode' => 'live', 'client_id' => 'paypal-client-id',
                'secret'   => 'current-secret', 'merchant_id' => '',
            ],
            'stripe' => [
                'test_mode' => true, 'publishable_key' => '', 'secret_key' => '', 'webhook_secret' => '',
            ],
        ];
        $proposed                      = $current;
        $proposed['paypal']['secret']  = 'replacement-secret';
        $settings                      = app(PlatformPaymentSettings::class);

        self::assertNotSame([], $settings->credentialChangeIssuesForSnapshots($current, $proposed));
        self::assertSame([], $settings->credentialChangeIssuesForSnapshots($current, $current));

        $session->forceFill([
            'status'       => PlatformPaymentSession::STATUS_COMPLETED,
            'active_key'   => null,
            'completed_at' => now(),
        ])->save();
        self::assertSame([], $settings->credentialChangeIssuesForSnapshots($current, $proposed));
    }

    public function test_platform_credentials_bypass_stale_worker_configuration(): void
    {
        $stored = PlatformPaymentCredentials::normalizeForStorage([
            'paypal_client_id' => 'database-client-id',
            'paypal_secret'    => 'database-private-secret',
        ]);
        SettingRepo::update('plugin', 'longos_platform_pay', $stored);

        // Simulate a long-lived worker that booted before the latest save.
        config([
            'bk.plugin.longos_platform_pay.paypal_client_id' => 'stale-client-id',
            'bk.plugin.longos_platform_pay.paypal_secret'    => 'stale-private-secret',
        ]);

        $fresh = PlatformPaymentCredentials::all();
        self::assertSame('database-client-id', $fresh['paypal_client_id']);
        self::assertSame('database-private-secret', $fresh['paypal_secret']);
        self::assertSame(
            'database-private-secret',
            PlatformPaymentCredentials::preserveBlankSecrets(['paypal_secret' => ''])['paypal_secret']
        );
    }

    public function test_provider_claim_rejects_a_site_that_is_no_longer_active(): void
    {
        $site = Site::query()->create([
            'uuid'          => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'code'          => 'payment-suspended',
            'name'          => 'Payment Suspended',
            'database_name' => 'payment_suspended',
            'status'        => Site::STATUS_SUSPENDED,
            'locale'        => 'en',
            'timezone'      => 'UTC',
            'meta'          => [],
        ]);
        $session = $this->session($site, [
            'uuid'       => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'token_hash' => str_repeat('7', 64),
            'active_key' => str_repeat('8', 64),
            'status'     => PlatformPaymentSession::STATUS_PENDING,
        ]);
        $settings = $this->createMock(PlatformPaymentSettings::class);
        $settings->method('availableProviders')->willReturn(['stripe']);
        $service = new PlatformPaymentSessionService(
            $this->createMock(TenantManager::class),
            $settings,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('子站当前不可发起支付');
        $service->claimProvider($session, 'stripe');
    }

    public function test_a_bound_provider_result_can_finish_after_the_browser_link_expires(): void
    {
        $site = Site::query()->create([
            'uuid'          => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'code'          => 'payment-late-return',
            'name'          => 'Payment Late Return',
            'database_name' => 'payment_late_return',
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'en',
            'timezone'      => 'UTC',
            'meta'          => [],
        ]);
        $uuid    = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
        $token   = hash_hmac('sha256', 'longos-platform-payment|' . $uuid, (string) config('app.key'));
        $session = $this->session($site, [
            'uuid'              => $uuid,
            'token_hash'        => hash('sha256', $token),
            'active_key'        => str_repeat('9', 64),
            'provider'          => 'stripe',
            'provider_order_id' => 'pi_late_return_12345678',
            'status'            => PlatformPaymentSession::STATUS_PROCESSING,
            'expires_at'        => now()->subMinute(),
        ]);
        $service = new PlatformPaymentSessionService(
            $this->createMock(TenantManager::class),
            $this->createMock(PlatformPaymentSettings::class),
        );

        $resolved = $service->resolveProviderResult($token);
        self::assertSame($session->id, $resolved->id);
        self::assertSame(PlatformPaymentSession::STATUS_EXPIRED, $resolved->status);
        self::assertNotNull($resolved->active_key);
        self::assertTrue($resolved->isFinanciallyRecoverable());

        try {
            $service->resolve($token);
            self::fail('Expected normal page resolution to reject an expired link.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('已过期', $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $overrides */
    private function session(Site $site, array $overrides): PlatformPaymentSession
    {
        $session = new PlatformPaymentSession;
        $session->forceFill(array_merge([
            'site_id'      => $site->id,
            'order_id'     => abs(crc32((string) $overrides['uuid'])),
            'order_number' => (string) abs(crc32((string) $overrides['token_hash'])),
            'amount'       => '19.99000000',
            'currency'     => 'USD',
            'locale'       => 'en',
            'provider'     => null,
            'expires_at'   => now()->addHour(),
        ], $overrides));
        $session->save();

        return $session;
    }
}
