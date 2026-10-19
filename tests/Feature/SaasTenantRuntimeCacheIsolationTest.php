<?php

namespace Tests\Feature;

use App\Saas\Models\Site;
use App\Saas\Tasks\ConfigureTenantRuntimeTask;
use App\Saas\Tasks\SwitchDefaultTenantDatabaseTask;
use Beike\Repositories\CurrencyRepo;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class SaasTenantRuntimeCacheIsolationTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $this->databasePath = tempnam(sys_get_temp_dir(), 'longos-runtime-');
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
        CurrencyRepo::resetRuntimeCache();
        DB::disconnect('tenant');
        DB::disconnect('saas');
        @unlink($this->databasePath);
        @rmdir(storage_path('framework/sessions/tenants/alpha'));
        @rmdir(storage_path('framework/sessions/tenants/beta'));
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_runtime_repository_cache_is_reset_between_tenants(): void
    {
        $first  = $this->site('alpha', 'lt_alpha_0001_');
        $second = $this->site('beta', 'lt_beta_00002_');

        $this->createTenantCurrency($first, 'ALP');
        $this->createTenantCurrency($second, 'BET');

        self::assertSame(['ALP'], $this->currencyCodesFor($first));
        self::assertSame(['BET'], $this->currencyCodesFor($second));
        self::assertSame('saas', DB::getDefaultConnection());
        self::assertSame('', config('database.connections.tenant.prefix'));
        self::assertFalse(app()->bound('saas.current_site'));
    }

    private function createTenantCurrency(Site $site, string $code): void
    {
        $database = new SwitchDefaultTenantDatabaseTask;
        $database->makeCurrent($site);

        try {
            Schema::create('currencies', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('code');
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
            DB::table('currencies')->insert([
                'name'       => $site->code,
                'code'       => $code,
                'active'     => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            $database->forgetCurrent();
        }
    }

    /** @return array<int, string> */
    private function currencyCodesFor(Site $site): array
    {
        $database = new SwitchDefaultTenantDatabaseTask;
        $runtime  = new ConfigureTenantRuntimeTask;
        $database->makeCurrent($site);

        try {
            $runtime->makeCurrent($site);

            return CurrencyRepo::listEnabled()->pluck('code')->values()->all();
        } finally {
            $runtime->forgetCurrent();
            $database->forgetCurrent();
        }
    }

    private function site(string $code, string $prefix): Site
    {
        $site = new Site([
            'uuid'          => "00000000-0000-4000-8000-{$code}0000000",
            'code'          => $code,
            'database_name' => "longos_{$code}",
            'timezone'      => 'UTC',
            'meta'          => [
                'database_mode'   => 'prefix',
                'database_prefix' => $prefix,
                'isolate_files'   => false,
            ],
        ]);
        $site->setRelation('domains', new Collection);

        return $site;
    }
}
