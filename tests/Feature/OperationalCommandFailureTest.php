<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

class OperationalCommandFailureTest extends TestCase
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
            'database.default'           => 'mysql',
            'database.connections.mysql' => $connection,
            'database.connections.saas'  => $connection,
        ]);
        DB::purge('mysql');
        DB::purge('saas');
    }

    protected function tearDown(): void
    {
        DB::disconnect('mysql');
        DB::disconnect('saas');
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_saas_health_reports_an_unavailable_control_database_without_a_stack_trace(): void
    {
        self::assertSame(1, Artisan::call('saas:health', ['--no-dns' => true]));

        $output = Artisan::output();
        self::assertStringContainsString('无法读取 SaaS 控制库', $output);
        self::assertStringNotContainsString('vendor\\laravel\\framework', $output);
    }

    public function test_location_audit_reports_an_unavailable_database_without_a_stack_trace(): void
    {
        self::assertSame(1, Artisan::call('longos:locations:audit'));

        $output = Artisan::output();
        self::assertStringContainsString('Unable to audit the location catalog', $output);
        self::assertStringNotContainsString('vendor\\laravel\\framework', $output);
    }
}
