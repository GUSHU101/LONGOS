<?php

namespace Tests\Feature;

use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class SaasDomainPublicationMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_historical_tenant_domain_is_not_left_falsely_active(): void
    {
        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config([
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

        $site = Site::query()->create([
            'uuid'          => (string) Str::uuid(),
            'code'          => 'migration-site',
            'name'          => 'Migration Site',
            'database_name' => 'migration_site',
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'zh_cn',
            'timezone'      => 'Asia/Shanghai',
        ]);
        $platform = $site->domains()->create([
            'host'         => 'migration.platform.example',
            'scheme'       => 'https',
            'is_primary'   => false,
            'status'       => Domain::STATUS_ACTIVE,
            'origin'       => Domain::ORIGIN_PLATFORM,
            'published_at' => null,
        ]);
        $tenant = $site->domains()->create([
            'host'         => 'migration.customer.example',
            'scheme'       => 'https',
            'is_primary'   => true,
            'status'       => Domain::STATUS_ACTIVE,
            'origin'       => Domain::ORIGIN_TENANT,
            'verified_at'  => now()->subHour(),
            'published_at' => null,
        ]);

        $migration = require database_path('migrations/2026_10_08_000000_add_saas_domain_publication_state.php');
        $migration->up();

        self::assertSame(Domain::STATUS_ACTIVE, $platform->refresh()->status);
        self::assertTrue($platform->is_primary);
        self::assertNotNull($platform->published_at);
        self::assertSame(Domain::STATUS_PENDING, $tenant->refresh()->status);
        self::assertFalse($tenant->is_primary);
        self::assertNull($tenant->published_at);
        self::assertNotNull($tenant->publication_error);
        self::assertFalse($tenant->isRoutable());

    }
}
