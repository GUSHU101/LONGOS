<?php

namespace Tests\Feature;

use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class SaasUpgradeTest extends TestCase
{
    private string $tenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $temporary = tempnam(sys_get_temp_dir(), 'longos-tenant-');
        self::assertNotFalse($temporary);
        $this->tenantDatabase = $temporary;

        $control = [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ];
        $tenant             = $control;
        $tenant['database'] = null;
        config([
            'database.default'                               => 'saas',
            'database.connections.saas'                      => $control,
            'database.connections.tenant'                    => $tenant,
            'multitenancy.landlord_database_connection_name' => 'saas',
            'multitenancy.tenant_database_connection_name'   => 'tenant',
            'saas.enabled'                                   => true,
        ]);
        DB::purge('saas');
        DB::purge('tenant');
        DB::setDefaultConnection('saas');

        self::assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]));
    }

    protected function tearDown(): void
    {
        DB::purge('inspection');
        DB::purge('tenant');
        gc_collect_cycles();
        if (isset($this->tenantDatabase) && is_file($this->tenantDatabase)) {
            unlink($this->tenantDatabase);
        }
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_upgrade_migrates_and_health_checks_every_routable_tenant(): void
    {
        $site = Site::query()->create([
            'uuid'           => (string) Str::uuid(),
            'code'           => 'main',
            'name'           => 'LONGOS Main',
            'database_name'  => $this->tenantDatabase,
            'status'         => Site::STATUS_ACTIVE,
            'locale'         => 'zh_cn',
            'timezone'       => 'Asia/Shanghai',
            'provisioned_at' => now(),
        ]);
        Domain::query()->create([
            'site_id'          => $site->id,
            'host'             => 'main.shops.example.com',
            'scheme'           => 'https',
            'is_primary'       => true,
            'routing_behavior' => Domain::ROUTING_REDIRECT,
            'status'           => Domain::STATUS_ACTIVE,
            'origin'           => Domain::ORIGIN_PLATFORM,
        ]);

        // An active tenant represents an already usable storefront before an
        // upgrade: it has seeded commerce/design/plugin data and an ID=1 root
        // administrator. The upgrade must preserve and validate that state.
        $site->execute(function (): void {
            self::assertSame(0, Artisan::call('migrate', [
                '--database'       => 'tenant',
                '--force'          => true,
                '--no-interaction' => true,
            ]), Artisan::output());
            self::assertSame(0, Artisan::call('db:seed', [
                '--database'       => 'tenant',
                '--force'          => true,
                '--no-interaction' => true,
            ]), Artisan::output());
            DB::table('admin_users')->insert([
                'id'         => 1,
                'name'       => 'Tenant Admin',
                'email'      => 'admin@main.example.com',
                'password'   => bcrypt('test-password-not-for-production'),
                'active'     => true,
                'locale'     => 'zh_cn',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Simulate a tenant created before the bundled review plugin was
            // introduced. The upgrade must register, enable and migrate it
            // without overwriting any existing merchant plugin preferences.
            DB::table('plugins')->where('code', 'longos_product_reviews')->delete();
            DB::table('settings')->where('type', 'plugin')->where('space', 'longos_product_reviews')->delete();
            Schema::dropIfExists('longos_product_reviews');
        });

        $exitCode = Artisan::call('saas:upgrade', [
            '--no-maintenance' => true,
            '--no-domain-sync' => true,
            '--no-optimize'    => true,
        ]);
        $failure = $site->events()
            ->where('event', 'deployment.upgrade')
            ->where('status', 'failed')
            ->latest('id')
            ->value('message');
        self::assertSame(0, $exitCode, (string) ($failure ?: Artisan::output()));

        $inspection             = config('database.connections.tenant');
        $inspection['database'] = $this->tenantDatabase;
        config(['database.connections.inspection' => $inspection]);
        DB::purge('inspection');
        self::assertTrue(Schema::connection('inspection')->hasTable('products'));
        self::assertTrue(Schema::connection('inspection')->hasTable('orders'));
        self::assertTrue(Schema::connection('inspection')->hasTable('longos_product_reviews'));
        self::assertTrue(DB::connection('inspection')->table('plugins')->where('code', 'longos_product_reviews')->exists());
        self::assertSame('1', (string) DB::connection('inspection')->table('settings')->where('type', 'plugin')->where('space', 'longos_product_reviews')->where('name', 'status')->value('value'));
        self::assertSame('saas', DB::getDefaultConnection());
        self::assertNull(Site::current());
        self::assertSame('healthy', $site->refresh()->health_status);
        self::assertTrue($site->events()->where('event', 'deployment.upgrade')->where('status', 'success')->exists());
    }
}
