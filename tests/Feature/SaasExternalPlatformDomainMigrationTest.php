<?php

namespace Tests\Feature;

use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class SaasExternalPlatformDomainMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config([
            'database.default'                              => 'saas',
            'database.connections.saas'                     => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
            'saas.base_domain'                              => 'shops.example.com',
            'saas.control_domains'                          => ['admin.example.com'],
            'saas.custom_domains.verification_ttl_hours'    => 72,
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

    public function test_upgrade_demotes_misclassified_customer_domains_and_preserves_a_platform_entry(): void
    {
        $withFallback = $this->site('tenant-with-fallback');
        $customer     = $this->domain($withFallback, 'shop.customer.example', true);
        $platform     = $this->domain($withFallback, 'tenant-with-fallback.shops.example.com', false);

        $withoutFallback  = $this->site('tenant-needs-fallback');
        $onlyCustomer     = $this->domain($withoutFallback, 'only.customer.example', true);

        $control       = $this->site('control', ['control_site' => true]);
        $controlDomain = $this->domain($control, 'admin.example.com', true);

        $migration = require database_path('migrations/2026_10_14_020000_reclassify_external_platform_domains.php');
        $migration->up();

        $customer->refresh();
        self::assertSame(Domain::ORIGIN_TENANT, $customer->origin);
        self::assertSame(Domain::STATUS_PENDING, $customer->status);
        self::assertFalse($customer->is_primary);
        self::assertNotNull($customer->verification_token);
        self::assertNull($customer->published_at);
        self::assertStringContainsString('旧版本直接发布', (string) $customer->verification_error);

        $platform->refresh();
        self::assertSame(Domain::STATUS_ACTIVE, $platform->status);
        self::assertTrue($platform->is_primary);

        $onlyCustomer->refresh();
        self::assertSame(Domain::STATUS_PENDING, $onlyCustomer->status);
        $generated = $withoutFallback->domains()->where('host', 'tenant-needs-fallback.shops.example.com')->firstOrFail();
        self::assertSame(Domain::ORIGIN_PLATFORM, $generated->origin);
        self::assertSame(Domain::STATUS_ACTIVE, $generated->status);
        self::assertTrue($generated->is_primary);
        self::assertNotNull($generated->published_at);

        $controlDomain->refresh();
        self::assertSame(Domain::ORIGIN_PLATFORM, $controlDomain->origin);
        self::assertSame(Domain::STATUS_ACTIVE, $controlDomain->status);
        self::assertTrue($controlDomain->is_primary);
    }

    public function test_platform_children_are_preserved_when_control_host_equals_base_domain(): void
    {
        config([
            'saas.base_domain'     => 'example.com',
            'saas.control_domains' => ['example.com'],
        ]);
        $site   = $this->site('tenant-under-apex');
        $domain = $this->domain($site, 'tenant-under-apex.example.com', true);

        $migration = require database_path('migrations/2026_10_14_020000_reclassify_external_platform_domains.php');
        $migration->up();

        $domain->refresh();
        self::assertSame(Domain::ORIGIN_PLATFORM, $domain->origin);
        self::assertSame(Domain::STATUS_ACTIVE, $domain->status);
        self::assertTrue($domain->is_primary);
    }

    public function test_follow_up_migration_repairs_platform_children_demoted_by_the_old_rule(): void
    {
        config([
            'saas.base_domain'     => 'example.com',
            'saas.control_domains' => ['example.com'],
        ]);
        $site   = $this->site('tenant-repair');
        $domain = $site->domains()->create([
            'host'                    => 'tenant-repair.example.com',
            'scheme'                  => 'https',
            'is_primary'              => false,
            'routing_behavior'        => Domain::ROUTING_REDIRECT,
            'status'                  => Domain::STATUS_PENDING,
            'origin'                  => Domain::ORIGIN_TENANT,
            'verification_method'     => Domain::VERIFICATION_DNS_TXT,
            'verification_token'      => Str::lower(Str::random(48)),
            'verification_expires_at' => now()->addHour(),
            'verification_error'      => '该域名由旧版本直接发布，升级后必须重新完成所有权、流量 DNS 和 HTTPS 验证。',
        ]);

        $migration = require database_path('migrations/2026_10_14_030000_restore_platform_children_of_control_domain.php');
        $migration->up();

        $domain->refresh();
        self::assertSame(Domain::ORIGIN_PLATFORM, $domain->origin);
        self::assertSame(Domain::STATUS_ACTIVE, $domain->status);
        self::assertTrue($domain->is_primary);
        self::assertNull($domain->verification_token);
        self::assertNull($domain->verification_error);
        self::assertNotNull($domain->published_at);
    }

    /** @param array<string, mixed> $meta */
    private function site(string $code, array $meta = []): Site
    {
        return Site::query()->create([
            'uuid'          => (string) Str::uuid(),
            'code'          => $code,
            'name'          => $code,
            'database_name' => str_replace('-', '_', $code),
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'zh_cn',
            'timezone'      => 'Asia/Shanghai',
            'meta'          => $meta,
        ]);
    }

    private function domain(Site $site, string $host, bool $primary): Domain
    {
        return $site->domains()->create([
            'host'             => $host,
            'scheme'           => 'https',
            'is_primary'       => $primary,
            'routing_behavior' => Domain::ROUTING_REDIRECT,
            'status'           => Domain::STATUS_ACTIVE,
            'origin'           => Domain::ORIGIN_PLATFORM,
            'published_at'     => now()->subHour(),
        ]);
    }
}
