<?php

namespace Tests\Feature;

use App\Saas\DomainConfigWriter;
use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use App\Saas\SiteAuditLogger;
use App\Saas\SiteDeletionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SaasSiteDeletionTest extends TestCase
{
    private string $databasePath;

    private string $testRoot;

    private string $originalPublicPath;

    protected function setUp(): void
    {
        parent::setUp();
        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $this->databasePath       = tempnam(sys_get_temp_dir(), 'longos-delete-');
        $this->testRoot           = storage_path('framework/testing/site-delete-' . Str::uuid());
        $this->originalPublicPath = public_path();
        (new Filesystem)->ensureDirectoryExists($this->testRoot . '/public');
        $app->usePublicPath($this->testRoot . '/public');
        $app->instance('path.public', $this->testRoot . '/public');

        $connection = [
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
            'saas.isolate_files'                             => true,
            'multitenancy.tenant_database_connection_name'   => 'tenant',
            'multitenancy.landlord_database_connection_name' => 'saas',
            'session.files'                                  => $this->testRoot . '/sessions',
            'cache.stores.file.path'                         => $this->testRoot . '/cache',
        ]);
        DB::purge('saas');
        DB::purge('tenant');
        DB::setDefaultConnection('saas');
        self::assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]), Artisan::output());
    }

    protected function tearDown(): void
    {
        DB::disconnect('tenant');
        DB::disconnect('saas');
        app()->usePublicPath($this->originalPublicPath);
        app()->instance('path.public', $this->originalPublicPath);
        (new Filesystem)->deleteDirectory($this->testRoot);
        @unlink($this->databasePath);
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_it_unpublishes_and_permanently_removes_an_isolated_prefix_tenant(): void
    {
        $prefix = 'lt_delete_123_';
        $site   = $this->site('delete-me', $prefix);
        Schema::connection('saas')->create($prefix . 'orders', static fn ($table) => $table->id());
        Schema::connection('saas')->create($prefix . 'products', static fn ($table) => $table->id());
        Schema::connection('saas')->create('unrelated_control_data', static fn ($table) => $table->id());

        $catalog = $this->testRoot . '/public/image/catalog/tenants/delete-me';
        $upload  = $this->testRoot . '/public/upload/tenants/delete-me';
        $session = $this->testRoot . '/sessions/tenants/delete-me';
        $cache   = $this->testRoot . '/cache/tenants/' . $site->id;
        foreach ([$catalog, $upload, $session, $cache] as $directory) {
            (new Filesystem)->ensureDirectoryExists($directory);
            file_put_contents($directory . '/probe.txt', 'tenant');
        }

        $writer  = $this->writer();
        $service = new SiteDeletionService($writer, $this->createMock(SiteAuditLogger::class), new Filesystem);
        $result  = $service->delete($site, true);

        self::assertSame(['code' => 'delete-me', 'data_deleted' => true, 'database_mode' => 'prefix'], $result);
        self::assertSame([Site::STATUS_DELETING], $writer->observedStatuses);
        self::assertFalse(Site::query()->whereKey($site->id)->exists());
        self::assertFalse(Domain::query()->where('site_id', $site->id)->exists());
        self::assertFalse(Schema::connection('saas')->hasTable($prefix . 'orders'));
        self::assertFalse(Schema::connection('saas')->hasTable($prefix . 'products'));
        self::assertTrue(Schema::connection('saas')->hasTable('unrelated_control_data'));
        foreach ([$catalog, $upload, $session, $cache] as $directory) {
            self::assertDirectoryDoesNotExist($directory);
        }
    }

    public function test_publication_failure_restores_the_site_before_any_data_is_deleted(): void
    {
        $prefix = 'lt_restore_123_';
        $site   = $this->site('restore-me', $prefix);
        Schema::connection('saas')->create($prefix . 'orders', static fn ($table) => $table->id());
        $service = new SiteDeletionService($this->writer(true), $this->createMock(SiteAuditLogger::class), new Filesystem);

        try {
            $service->delete($site, true);
            self::fail('Expected domain publication to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('simulated publication failure', $exception->getMessage());
        }

        self::assertSame(Site::STATUS_ACTIVE, $site->refresh()->status);
        self::assertTrue(Schema::connection('saas')->hasTable($prefix . 'orders'));
    }

    public function test_control_site_can_never_be_deleted(): void
    {
        $site    = $this->site('control', 'lt_control_123_', ['control_site' => true]);
        $service = new SiteDeletionService($this->writer(), $this->createMock(SiteAuditLogger::class), new Filesystem);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('控制站');
        $service->delete($site, true);
    }

    public function test_a_live_provisioning_lease_blocks_deletion(): void
    {
        $site = $this->site('building', 'lt_building_123_');
        $site->forceFill([
            'status'                    => Site::STATUS_PROVISIONING,
            'provisioning_token'        => (string) Str::uuid(),
            'provisioning_heartbeat_at' => now(),
        ])->save();
        $service = new SiteDeletionService($this->writer(), $this->createMock(SiteAuditLogger::class), new Filesystem);

        try {
            $service->delete($site, true);
            self::fail('Expected a live provisioning lease to block deletion.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('建站任务', $exception->getMessage());
        }

        self::assertTrue($site->refresh()->status === Site::STATUS_PROVISIONING);
        self::assertNotNull($site->provisioning_token);
    }

    public function test_overlapping_shared_database_prefixes_are_never_deleted(): void
    {
        $site = $this->site('prefix-parent', 'lt_overlap_');
        $this->site('prefix-child', 'lt_overlap_child_');
        Schema::connection('saas')->create('lt_overlap_orders', static fn ($table) => $table->id());
        Schema::connection('saas')->create('lt_overlap_child_orders', static fn ($table) => $table->id());
        $service = new SiteDeletionService($this->writer(), $this->createMock(SiteAuditLogger::class), new Filesystem);

        try {
            $service->delete($site, true);
            self::fail('Expected overlapping prefixes to block deletion.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('包含关系', $exception->getMessage());
        }

        self::assertSame(Site::STATUS_DELETING, $site->refresh()->status);
        self::assertTrue(Schema::connection('saas')->hasTable('lt_overlap_orders'));
        self::assertTrue(Schema::connection('saas')->hasTable('lt_overlap_child_orders'));
    }

    private function site(string $code, string $prefix, array $extraMeta = []): Site
    {
        $site = Site::query()->create([
            'uuid'          => (string) Str::uuid(),
            'code'          => $code,
            'name'          => ucfirst($code),
            'database_name' => 'longos_' . str_replace('-', '_', $code),
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'zh_cn',
            'timezone'      => 'Asia/Shanghai',
            'meta'          => array_merge([
                'database_mode'   => 'prefix',
                'database_prefix' => $prefix,
                'isolate_files'   => true,
            ], $extraMeta),
        ]);
        $site->domains()->create([
            'host'       => $code . '.example.com',
            'scheme'     => 'https',
            'is_primary' => true,
            'status'     => Domain::STATUS_ACTIVE,
            'origin'     => Domain::ORIGIN_PLATFORM,
        ]);

        return $site;
    }

    private function writer(bool $fail = false): DomainConfigWriter
    {
        return new class(new Filesystem, $fail) extends DomainConfigWriter
        {
            public array $observedStatuses = [];

            public function __construct(Filesystem $files, private readonly bool $fail)
            {
                parent::__construct($files);
            }

            public function synchronized(callable $callback): mixed
            {
                return $callback();
            }

            public function sync(bool $reload = true): array
            {
                $this->observedStatuses[] = (string) Site::query()->value('status');
                if ($this->fail) {
                    throw new RuntimeException('simulated publication failure');
                }

                return ['config_path' => 'test', 'manifest_path' => 'test.json', 'hosts' => 0, 'reloaded' => false];
            }
        };
    }
}
