<?php

namespace Tests\Feature;

use App\Saas\Models\PlatformPaymentSession;
use App\Saas\Models\Site;
use App\Services\PlatformPayment\PlatformPaymentSessionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

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

        self::assertSame(0, Artisan::call('saas:platform-pay:reconcile', [
            '--limit'      => 10,
            '--prune-days' => 365,
        ]), Artisan::output());

        self::assertSame(PlatformPaymentSession::STATUS_EXPIRED, $pending->fresh()->status);
        self::assertNull($pending->fresh()->active_key);
        self::assertNull($completed->fresh());
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
