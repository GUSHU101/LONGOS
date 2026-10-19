<?php

namespace Tests\Feature;

use App\Saas\Models\Site;
use App\Saas\Tasks\SwitchDefaultTenantDatabaseTask;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class SaasSharedDatabaseIsolationTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $this->databasePath = tempnam(sys_get_temp_dir(), 'longos-prefix-');
        $connection         = [
            'driver'                  => 'sqlite',
            'database'                => $this->databasePath,
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ];
        config([
            'database.default'                               => 'saas',
            'database.connections.saas'                      => $connection,
            'database.connections.tenant'                    => $connection,
            'saas.shared_database'                           => $this->databasePath,
            'multitenancy.tenant_database_connection_name'   => 'tenant',
            'multitenancy.landlord_database_connection_name' => 'saas',
        ]);
        DB::purge('saas');
        DB::purge('tenant');
        DB::setDefaultConnection('saas');
    }

    protected function tearDown(): void
    {
        DB::disconnect('tenant');
        DB::disconnect('saas');
        @unlink($this->databasePath);
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_complete_migrations_and_data_are_isolated_by_tenant_prefix(): void
    {
        $first  = $this->site('alpha', 'lt_alpha_0001_');
        $second = $this->site('beta', 'lt_beta_00002_');

        $this->migrateAndWriteProbe($first, 'alpha-value');
        $this->migrateAndWriteProbe($second, 'beta-value');

        $task = new SwitchDefaultTenantDatabaseTask;
        $task->makeCurrent($first);

        try {
            self::assertSame('alpha-value', DB::table('tenant_isolation_probe')->value('value'));
            self::assertTrue(Schema::hasTable('settings'));
            self::assertFalse(Schema::hasTable('saas_sites'));
            self::assertGreaterThan(0, DB::table('products')->count());
        } finally {
            $task->forgetCurrent();
        }

        $task->makeCurrent($second);

        try {
            self::assertSame('beta-value', DB::table('tenant_isolation_probe')->value('value'));
            self::assertTrue(Schema::hasTable('settings'));
            self::assertGreaterThan(0, DB::table('products')->count());
        } finally {
            $task->forgetCurrent();
        }

        self::assertSame('saas', DB::getDefaultConnection());
        self::assertSame('', config('database.connections.tenant.prefix'));
    }

    private function migrateAndWriteProbe(Site $site, string $value): void
    {
        $task = new SwitchDefaultTenantDatabaseTask;
        $task->makeCurrent($site);

        try {
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

            Schema::create('tenant_isolation_probe', function ($table): void {
                $table->id();
                $table->string('value');
            });
            DB::table('tenant_isolation_probe')->insert(['value' => $value]);
        } finally {
            $task->forgetCurrent();
        }
    }

    private function site(string $code, string $prefix): Site
    {
        return new Site([
            'code'          => $code,
            'database_name' => "longos_{$code}",
            'meta'          => [
                'database_mode'   => 'prefix',
                'database_prefix' => $prefix,
            ],
        ]);
    }
}
