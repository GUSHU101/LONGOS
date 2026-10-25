<?php

namespace Tests\Feature;

use App\Saas\Models\PlatformPaymentSession;
use App\Saas\Models\Site;
use App\Saas\TenantManager;
use App\Services\PlatformPayment\PlatformPaymentSessionService;
use App\Services\PlatformPayment\PlatformPaymentSettings;
use Beike\Repositories\SettingRepo;
use Illuminate\Contracts\Console\Kernel;
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
