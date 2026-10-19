<?php

namespace Tests\Feature;

use App\Saas\DomainConfigWriter;
use App\Saas\Models\Site;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SaasInstallSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $connection = [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ];
        config([
            'database.default'          => 'saas',
            'database.connections.saas' => $connection,
            'saas.tenant_connection'    => 'saas',
            'saas.enabled'              => false,
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

    public function test_install_fails_closed_when_domain_publication_fails(): void
    {
        $writer = new class(new Filesystem) extends DomainConfigWriter
        {
            public int $attempts = 0;

            public function sync(bool $reload = true): array
            {
                $this->attempts++;

                throw new RuntimeException('simulated reload failure');
            }
        };
        app()->instance(DomainConfigWriter::class, $writer);

        self::assertSame(1, Artisan::call('saas:install', [
            '--domain' => 'https://main.shops.example.com',
            '--code'   => 'main',
            '--name'   => 'LONGOS Main',
        ]));

        $site = Site::query()->sole();
        self::assertSame(Site::STATUS_FAILED, $site->status);
        self::assertSame(2, $writer->attempts);
        self::assertStringContainsString('simulated reload failure', Artisan::output());
    }

    public function test_install_refuses_to_run_after_saas_is_enabled(): void
    {
        config(['saas.enabled' => true]);

        self::assertSame(1, Artisan::call('saas:install', [
            '--domain' => 'https://main.shops.example.com',
        ]));

        self::assertSame(0, Site::query()->count());
        self::assertStringContainsString('SAAS_ENABLED=false', Artisan::output());
    }

    public function test_an_exact_retry_reuses_the_already_registered_control_site(): void
    {
        $writer = new class(new Filesystem) extends DomainConfigWriter
        {
            public int $attempts = 0;

            public function sync(bool $reload = true): array
            {
                $this->attempts++;

                return [
                    'config_path'   => 'test.conf',
                    'manifest_path' => 'test.json',
                    'hosts'         => 1,
                    'reloaded'      => false,
                ];
            }
        };
        app()->instance(DomainConfigWriter::class, $writer);
        $arguments = [
            '--domain' => 'https://main.shops.example.com',
            '--code'   => 'main',
            '--name'   => 'LONGOS Main',
        ];

        self::assertSame(0, Artisan::call('saas:install', $arguments));
        self::assertSame(0, Artisan::call('saas:install', $arguments));

        self::assertSame(1, Site::query()->count());
        self::assertSame(Site::STATUS_ACTIVE, Site::query()->sole()->status);
        self::assertSame(2, $writer->attempts);
        self::assertStringContainsString('已确认现有 SaaS 主站', Artisan::output());
    }
}
