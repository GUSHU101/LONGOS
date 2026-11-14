<?php

namespace Tests\Feature;

use App\Saas\DomainConfigWriter;
use App\Saas\Models\Domain;
use App\Saas\Models\PlatformPaymentAccount;
use App\Saas\Models\PlatformPaymentSession;
use App\Saas\Models\Site;
use App\Saas\SiteAuditLogger;
use App\Saas\SiteHealthChecker;
use App\Saas\SiteLifecycleService;
use App\Saas\Support\DomainRoutingPolicy;
use App\Saas\TenantManager;
use App\Services\PlatformPayment\PlatformPaymentAccountService;
use App\Services\PlatformPayment\PlatformPaymentSessionService;
use App\Services\PlatformPayment\PlatformPaymentSettings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Plugin\LongosPlatformPay\Services\DirectPaymentService;

class PlatformPaymentAccountRotationTest extends TestCase
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
            'cache.default' => 'array',
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

    public function test_circuit_breaking_and_session_failover_are_transactionally_safe(): void
    {
        $service = new PlatformPaymentAccountService($this->createMock(SiteLifecycleService::class));
        app()->instance(PlatformPaymentAccountService::class, $service);

        $site   = $this->site();
        $first  = $this->account('Receiver A', 'pay-a.example.test', 'ready');
        $second = $this->account('Receiver B', 'pay-b.example.test', 'ready');
        $this->callbackDomain($site, $first);
        $this->callbackDomain($site, $second);

        self::assertSame($first->id, $service->selectNextActivePaypalAccount()?->id);
        self::assertSame($second->id, $service->selectNextActivePaypalAccount()?->id);

        $service->recordFailure($first);
        $service->recordFailure($first);
        self::assertNull($first->fresh()->circuit_open_until);
        self::assertSame('ready', $first->fresh()->health_status);
        self::assertCount(2, $service->activePaypalAccounts());
        $service->recordFailure($first);

        $circuitUntil = $first->fresh()->circuit_open_until;
        self::assertSame(3, $first->fresh()->consecutive_failures);
        self::assertSame('failed', $first->fresh()->health_status);
        self::assertNotNull($circuitUntil);
        self::assertSame(
            DB::connection('saas')->table('saas_platform_payment_accounts')->where('id', $first->id)->value('circuit_open_until'),
            $circuitUntil->copy()->utc()->format('Y-m-d H:i:s'),
        );
        self::assertTrue(
            $circuitUntil->isFuture(),
            sprintf('Circuit expires at %s while runtime now is %s.', $circuitUntil->toIso8601String(), now()->toIso8601String()),
        );
        self::assertSame([$second->id], $service->activePaypalAccounts()->pluck('id')->all());
        self::assertSame([$second->id], $service->enabledPaypalAccountsForHealth(1)->pluck('id')->all());

        $session = $this->session($site, $first, [
            'uuid'       => '11111111-1111-4111-8111-111111111111',
            'token_hash' => str_repeat('a', 64),
            'active_key' => str_repeat('b', 64),
        ]);
        // The timed circuit backoff may pass before the buyer opens the
        // hosted page. A failed account remains quarantined until the health
        // job verifies it again, so an untouched session must still move to B
        // rather than create its first remote order with A.
        $first->forceFill(['circuit_open_until' => now('UTC')->subSecond()])->save();
        self::assertTrue($service->isUnavailableForNewSessions($first->fresh()));
        $sessions = new PlatformPaymentSessionService(
            $this->createMock(TenantManager::class),
            $this->createMock(PlatformPaymentSettings::class),
        );

        $rotated = $sessions->rotatePaypalAccountAfterPreOrderFailure($session);

        self::assertNotNull($rotated);
        self::assertSame($session->id, $rotated->id);
        self::assertSame($second->id, $rotated->payment_account_id);
        self::assertSame('pay-b.example.test', $rotated->callback_host);
        self::assertSame(PlatformPaymentSession::STATUS_PENDING, $rotated->status);
        self::assertSame(str_repeat('a', 64), $rotated->token_hash);

        $bound = $this->session($site, $first, [
            'uuid'              => '22222222-2222-4222-8222-222222222222',
            'token_hash'        => str_repeat('c', 64),
            'active_key'        => str_repeat('d', 64),
            'provider'          => 'paypal',
            'provider_order_id' => 'PAYPALORDER123456',
            'status'            => PlatformPaymentSession::STATUS_PROCESSING,
        ]);

        self::assertNull($sessions->rotatePaypalAccountAfterPreOrderFailure($bound));
        self::assertSame($first->id, $bound->fresh()->payment_account_id);
        self::assertSame('pay-a.example.test', $bound->fresh()->callback_host);

        $service->markHealth($first->fresh(), 'ready');
        self::assertSame(0, $first->fresh()->consecutive_failures);
        self::assertNull($first->fresh()->circuit_open_until);

        // An OAuth/credential failure is known to happen before a remote
        // provider order exists. It should open the circuit immediately so a
        // retry of this browser session cannot rotate back to account A.
        $service->recordUnavailable($first->fresh());
        self::assertSame(1, $first->fresh()->consecutive_failures);
        self::assertNotNull($first->fresh()->circuit_open_until);
        self::assertTrue($first->fresh()->circuit_open_until->isFuture());
        self::assertSame([$second->id], $service->activePaypalAccounts()->pluck('id')->all());

        $service->markHealth($first->fresh(), 'ready');

        Domain::query()->where('host', $second->callback_host)->update(['status' => Domain::STATUS_RETIRED]);
        self::assertSame([$first->id], $service->activePaypalAccounts()->pluck('id')->all());
        self::assertSame($first->id, $service->selectNextActivePaypalAccount()?->id);

        Domain::query()->where('host', $first->callback_host)->update(['status' => Domain::STATUS_RETIRED]);
        self::assertTrue($service->activePaypalAccounts()->isEmpty());
        self::assertNull($service->selectNextActivePaypalAccount());
    }

    public function test_direct_checkout_attempt_limit_covers_the_entire_active_account_pool(): void
    {
        $service = new PlatformPaymentAccountService($this->createMock(SiteLifecycleService::class));
        app()->instance(PlatformPaymentAccountService::class, $service);
        $site = $this->site();
        foreach (['A', 'B', 'C'] as $suffix) {
            $account = $this->account("Receiver {$suffix}", "pay-{$suffix}.example.test", 'ready');
            $this->callbackDomain($site, $account);
        }

        $direct = new DirectPaymentService(
            $this->createMock(PlatformPaymentSessionService::class),
            $this->createMock(PlatformPaymentSettings::class),
        );
        $method = new \ReflectionMethod($direct, 'paypalPreOrderAttemptLimit');

        self::assertSame(3, $method->invoke($direct));
    }

    public function test_control_subdomain_changes_keep_callback_domains_routeable_and_undeletable(): void
    {
        $temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'longos-payment-domain-' . bin2hex(random_bytes(6));
        $configPath         = $temporaryDirectory . DIRECTORY_SEPARATOR . 'domains.conf';
        $manifestPath       = $temporaryDirectory . DIRECTORY_SEPARATOR . 'domains.json';
        config([
            'saas.base_domain'     => 'example.test',
            'saas.control_domains' => [],
            'saas.domain_config'   => [
                'driver'               => 'caddy',
                'path'                 => $configPath,
                'manifest_path'        => $manifestPath,
                'lock_timeout_seconds' => 1,
            ],
        ]);

        try {
            $site       = $this->site();
            $oldPrimary = Domain::query()->create([
                'site_id'          => $site->id,
                'host'             => 'control.example.test',
                'scheme'           => 'https',
                'is_primary'       => true,
                'routing_behavior' => Domain::ROUTING_REDIRECT,
                'status'           => Domain::STATUS_ACTIVE,
                'origin'           => Domain::ORIGIN_PLATFORM,
            ]);
            $account  = $this->account('Receiver A', 'pay-a.example.test', 'ready');
            $callback = $this->callbackDomain($site, $account);

            $lifecycle = new SiteLifecycleService(
                new DomainConfigWriter(new Filesystem),
                $this->createMock(SiteHealthChecker::class),
                $this->createMock(SiteAuditLogger::class),
            );

            $replacement = $lifecycle->changeSubdomain($site, 'control-next');

            self::assertSame(Domain::STATUS_ACTIVE, $replacement->status);
            self::assertSame(Domain::STATUS_RETIRED, $oldPrimary->fresh()->status);
            self::assertSame(Domain::STATUS_ACTIVE, $callback->fresh()->status);
            self::assertTrue((new PlatformPaymentAccountService($lifecycle))->isCallbackHostRoutable($account->callback_host));

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('支付回传域名');
            $lifecycle->removeDomain($site, $callback->fresh());
        } finally {
            (new Filesystem)->deleteDirectory($temporaryDirectory);
        }
    }

    public function test_callback_domain_is_published_as_a_non_redirecting_storefront_alias(): void
    {
        $temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'longos-payment-alias-' . bin2hex(random_bytes(6));
        config([
            'saas.base_domain'     => 'example.test',
            'saas.control_domains' => [],
            'saas.domain_config'   => [
                'driver'               => 'caddy',
                'path'                 => $temporaryDirectory . DIRECTORY_SEPARATOR . 'domains.conf',
                'manifest_path'        => $temporaryDirectory . DIRECTORY_SEPARATOR . 'domains.json',
                'lock_timeout_seconds' => 1,
            ],
        ]);

        try {
            $site = $this->site(true);
            Domain::query()->create([
                'site_id'          => $site->id,
                'host'             => 'control.example.test',
                'scheme'           => 'https',
                'is_primary'       => true,
                'routing_behavior' => Domain::ROUTING_REDIRECT,
                'status'           => Domain::STATUS_ACTIVE,
                'origin'           => Domain::ORIGIN_PLATFORM,
            ]);
            $account = $this->account('Alias Receiver', 'pay-alias.example.test', 'ready');
            $account->forceFill(['enabled' => false])->save();
            $callback = Domain::query()->create([
                'site_id'          => $site->id,
                'host'             => $account->callback_host,
                'scheme'           => 'https',
                'is_primary'       => false,
                'routing_behavior' => Domain::ROUTING_REDIRECT,
                'status'           => Domain::STATUS_ACTIVE,
                'origin'           => Domain::ORIGIN_PLATFORM,
            ]);

            $lifecycle = new SiteLifecycleService(
                new DomainConfigWriter(new Filesystem),
                $this->createMock(SiteHealthChecker::class),
                $this->createMock(SiteAuditLogger::class),
            );
            $service = new PlatformPaymentAccountService($lifecycle);
            self::assertFalse($service->isCallbackHostRoutable($account->callback_host));
            $updated = $service->update($account, [
                'label'           => $account->label,
                'api_mode'        => $account->api_mode,
                'client_id'       => $account->client_id,
                'secret'          => '',
                'merchant_id'     => '',
                'enabled'         => true,
                'rotation_weight' => 1,
            ]);

            self::assertTrue($updated->enabled);
            self::assertTrue($service->isCallbackHostRoutable($account->callback_host));
            self::assertFalse($callback->fresh()->is_primary);
            self::assertSame(Domain::ROUTING_ALIAS, $callback->fresh()->routing_behavior);
            self::assertFalse(DomainRoutingPolicy::shouldRedirect(
                'GET',
                '/',
                false,
                (string) $callback->fresh()->routing_behavior,
                [],
            ));
            self::assertSame(
                Domain::ROUTING_ALIAS,
                collect(json_decode((string) file_get_contents($temporaryDirectory . DIRECTORY_SEPARATOR . 'domains.json'), true, flags: JSON_THROW_ON_ERROR)['domains'])
                    ->firstWhere('host', $account->callback_host)['type'],
            );
        } finally {
            (new Filesystem)->deleteDirectory($temporaryDirectory);
        }
    }

    public function test_upgrade_converts_only_managed_callback_domains_to_storefront_aliases(): void
    {
        $site    = $this->site(true);
        $account = $this->account('Legacy Receiver', 'pay-legacy.example.test', 'ready');
        $account->forceFill(['enabled' => false])->save();
        $callback = Domain::query()->create([
            'site_id'          => $site->id,
            'host'             => $account->callback_host,
            'scheme'           => 'https',
            'is_primary'       => false,
            'routing_behavior' => Domain::ROUTING_REDIRECT,
            'status'           => Domain::STATUS_ACTIVE,
            'origin'           => Domain::ORIGIN_PLATFORM,
        ]);
        $ordinary = Domain::query()->create([
            'site_id'          => $site->id,
            'host'             => 'ordinary.example.test',
            'scheme'           => 'https',
            'is_primary'       => false,
            'routing_behavior' => Domain::ROUTING_REDIRECT,
            'status'           => Domain::STATUS_ACTIVE,
            'origin'           => Domain::ORIGIN_PLATFORM,
        ]);
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_11_13_010000_make_platform_payment_callback_domains_aliases.php';

        $migration->up();

        self::assertSame(Domain::ROUTING_ALIAS, $callback->fresh()->routing_behavior);
        self::assertSame(Domain::ROUTING_REDIRECT, $ordinary->fresh()->routing_behavior);

        $migration->down();

        self::assertSame(Domain::ROUTING_REDIRECT, $callback->fresh()->routing_behavior);
    }

    public function test_health_probe_fails_closed_when_a_callback_alias_is_not_routeable(): void
    {
        $service = new PlatformPaymentAccountService($this->createMock(SiteLifecycleService::class));
        app()->instance(PlatformPaymentAccountService::class, $service);

        $account = $this->account('Receiver A', 'pay-a.example.test', 'ready');

        self::assertSame(1, Artisan::call('saas:platform-pay:accounts-health', ['--limit' => 1]));
        self::assertSame('failed', $account->fresh()->health_status);
        self::assertSame(0, $account->fresh()->consecutive_failures);
        self::assertNull($account->fresh()->circuit_open_until);
    }

    public function test_audit_merchant_id_can_be_corrected_without_touching_a_bound_payment(): void
    {
        $service = new PlatformPaymentAccountService($this->createMock(SiteLifecycleService::class));
        $site    = $this->site();
        $account = $this->account('Receiver A', 'pay-a.example.test', 'ready');
        $this->session($site, $account, [
            'provider'          => 'paypal',
            'provider_order_id' => 'PAYPALORDER123456',
            'status'            => PlatformPaymentSession::STATUS_PROCESSING,
        ]);

        $updated = $service->update($account, [
            'label'           => 'Receiver A',
            'api_mode'        => 'sandbox',
            'client_id'       => (string) $account->client_id,
            'secret'          => '',
            'merchant_id'     => '2ABCDEFGHJKLM',
            // Avoid a domain publication in this focused service test. The
            // existing provider-bound session stays tied to its account.
            'enabled'         => false,
            'rotation_weight' => 1,
        ]);

        self::assertSame('2ABCDEFGHJKLM', $updated->merchant_id);
        self::assertSame(PlatformPaymentSession::STATUS_PROCESSING, PlatformPaymentSession::query()->sole()->status);
        self::assertSame('PAYPALORDER123456', PlatformPaymentSession::query()->sole()->provider_order_id);
    }

    public function test_paused_unused_account_is_retired_and_its_callback_domain_is_unpublished(): void
    {
        $temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'longos-payment-delete-' . bin2hex(random_bytes(6));
        config([
            'saas.base_domain'     => 'example.test',
            'saas.control_domains' => [],
            'saas.domain_config'   => [
                'driver'               => 'caddy',
                'path'                 => $temporaryDirectory . DIRECTORY_SEPARATOR . 'domains.conf',
                'manifest_path'        => $temporaryDirectory . DIRECTORY_SEPARATOR . 'domains.json',
                'lock_timeout_seconds' => 1,
            ],
        ]);

        try {
            $site = $this->site(true);
            Domain::query()->create([
                'site_id'          => $site->id,
                'host'             => 'control.example.test',
                'scheme'           => 'https',
                'is_primary'       => true,
                'routing_behavior' => Domain::ROUTING_REDIRECT,
                'status'           => Domain::STATUS_ACTIVE,
                'origin'           => Domain::ORIGIN_PLATFORM,
            ]);
            $account = $this->account('Unused Receiver', 'pay-unused.example.test', 'ready');
            $account->forceFill(['enabled' => false])->save();
            $this->callbackDomain($site, $account);
            $originalSecret = (string) $account->secret_ciphertext;

            $lifecycle = new SiteLifecycleService(
                new DomainConfigWriter(new Filesystem),
                $this->createMock(SiteHealthChecker::class),
                $this->createMock(SiteAuditLogger::class),
            );
            $service = new PlatformPaymentAccountService($lifecycle);

            $service->delete($account);

            self::assertNull(PlatformPaymentAccount::query()->find($account->id));
            $tombstone = PlatformPaymentAccount::withTrashed()->findOrFail($account->id);
            self::assertTrue($tombstone->trashed());
            self::assertFalse($tombstone->enabled);
            self::assertStringStartsWith('retired-', (string) $tombstone->client_id);
            self::assertNotSame($originalSecret, (string) $tombstone->secret_ciphertext);
            self::assertNull($tombstone->merchant_id);
            self::assertNull(Domain::query()->where('host', 'pay-unused.example.test')->first());
            self::assertTrue($service->hasManagedPaypalAccounts(), 'A tombstone must prevent legacy credential fallback.');
            self::assertStringNotContainsString(
                'pay-unused.example.test',
                (string) file_get_contents($temporaryDirectory . DIRECTORY_SEPARATOR . 'domains.json'),
            );
        } finally {
            (new Filesystem)->deleteDirectory($temporaryDirectory);
        }
    }

    public function test_account_deletion_requires_pause_and_zero_retained_sessions(): void
    {
        $lifecycle = $this->createMock(SiteLifecycleService::class);
        $lifecycle->expects(self::never())->method('removeDomain');
        $service = new PlatformPaymentAccountService($lifecycle);
        $site    = $this->site(true);
        $enabled = $this->account('Enabled Receiver', 'pay-enabled.example.test', 'ready');

        try {
            $service->delete($enabled);
            self::fail('An enabled account must not be deletable.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('先暂停', $exception->getMessage());
        }

        $enabled->forceFill(['enabled' => false])->save();
        $this->session($site, $enabled, [
            'status'     => PlatformPaymentSession::STATUS_COMPLETED,
            'active_key' => null,
        ]);

        try {
            $service->delete($enabled->fresh());
            self::fail('An account with retained payment history must not be deletable.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('支付记录', $exception->getMessage());
        }

        self::assertNotNull(PlatformPaymentAccount::query()->find($enabled->id));
    }

    public function test_account_retirement_rolls_back_when_callback_domain_cleanup_fails(): void
    {
        $site    = $this->site(true);
        $account = $this->account('Rollback Receiver', 'pay-rollback.example.test', 'ready');
        $account->forceFill(['enabled' => false])->save();
        $this->callbackDomain($site, $account);
        $original = $account->getAttributes();

        $lifecycle = $this->createMock(SiteLifecycleService::class);
        $lifecycle->expects(self::once())->method('removeDomain')->willThrowException(new \RuntimeException('publication failed'));
        $service = new PlatformPaymentAccountService($lifecycle);

        try {
            $service->delete($account);
            self::fail('A callback publication failure must abort retirement.');
        } catch (\RuntimeException $exception) {
            self::assertSame('publication failed', $exception->getMessage());
        }

        $restored = PlatformPaymentAccount::query()->findOrFail($account->id);
        self::assertSame($original['label'], $restored->label);
        self::assertSame($original['client_id'], $restored->client_id);
        self::assertSame($original['secret_ciphertext'], $restored->secret_ciphertext);
        self::assertNull($restored->deleted_at);
    }

    public function test_account_retirement_refuses_to_remove_a_callback_domain_owned_by_another_site(): void
    {
        $this->site(true);
        $otherSite = Site::query()->create([
            'uuid'          => '44444444-4444-4444-8444-444444444444',
            'code'          => 'domain-owner',
            'name'          => 'Domain Owner',
            'database_name' => 'domain_owner',
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'en',
            'timezone'      => 'UTC',
            'meta'          => [],
        ]);
        $account = $this->account('Foreign Domain Receiver', 'pay-foreign.example.test', 'ready');
        $account->forceFill(['enabled' => false])->save();
        $this->callbackDomain($otherSite, $account);

        $lifecycle = $this->createMock(SiteLifecycleService::class);
        $lifecycle->expects(self::never())->method('removeDomain');
        $service = new PlatformPaymentAccountService($lifecycle);

        try {
            $service->delete($account);
            self::fail('A callback domain owned by another site must not be removed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('其他站点占用', $exception->getMessage());
        }

        self::assertNotNull(PlatformPaymentAccount::query()->find($account->id));
        self::assertNotNull(Domain::query()->where('host', $account->callback_host)->first());
    }

    private function account(string $label, string $host, string $health): PlatformPaymentAccount
    {
        return PlatformPaymentAccount::query()->create([
            'uuid'              => (string) \Illuminate\Support\Str::uuid(),
            'provider'          => PlatformPaymentAccount::PROVIDER_PAYPAL,
            'label'             => $label,
            'api_mode'          => 'sandbox',
            'client_id'         => 'client-id-' . strtolower(str_replace(' ', '-', $label)),
            'secret_ciphertext' => 'not-used-by-this-test',
            'callback_host'     => $host,
            'enabled'           => true,
            'rotation_weight'   => 1,
            'health_status'     => $health,
        ]);
    }

    private function site(bool $control = false): Site
    {
        return Site::query()->create([
            'uuid'          => '33333333-3333-4333-8333-333333333333',
            'code'          => 'rotation-child',
            'name'          => 'Rotation Child',
            'database_name' => 'rotation_child',
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'en',
            'timezone'      => 'UTC',
            'meta'          => $control ? ['control_site' => true] : [],
        ]);
    }

    private function callbackDomain(Site $site, PlatformPaymentAccount $account): Domain
    {
        return Domain::query()->create([
            'site_id'          => $site->id,
            'host'             => $account->callback_host,
            'scheme'           => 'https',
            'is_primary'       => false,
            'routing_behavior' => Domain::ROUTING_ALIAS,
            'status'           => Domain::STATUS_ACTIVE,
            'origin'           => Domain::ORIGIN_PLATFORM,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function session(Site $site, PlatformPaymentAccount $account, array $overrides): PlatformPaymentSession
    {
        return PlatformPaymentSession::query()->create(array_replace([
            'uuid'               => (string) \Illuminate\Support\Str::uuid(),
            'token_hash'         => hash('sha256', (string) \Illuminate\Support\Str::uuid()),
            'active_key'         => hash('sha256', (string) \Illuminate\Support\Str::uuid()),
            'site_id'            => $site->id,
            'order_id'           => random_int(10_000, 99_999),
            'order_number'       => 'ROTATION-' . \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(8)),
            'amount'             => '39.99000000',
            'currency'           => 'USD',
            'locale'             => 'en',
            'payment_account_id' => $account->id,
            'callback_host'      => $account->callback_host,
            'status'             => PlatformPaymentSession::STATUS_PENDING,
            'expires_at'         => now('UTC')->addHour(),
        ], $overrides));
    }
}
