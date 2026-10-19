<?php

namespace Tests\Feature;

use App\Saas\DomainConfigWriter;
use App\Saas\Models\Site;
use App\Saas\SiteAuditLogger;
use App\Saas\SiteProvisioner;
use App\Saas\TenantManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class SaasProvisioningLeaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config([
            'database.connections.saas' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
            'saas.provisioning.lease_timeout_minutes' => 120,
        ]);
        DB::purge('saas');

        Schema::connection('saas')->create('saas_sites', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('database_name')->unique();
            $table->string('status');
            $table->string('locale')->default('zh_cn');
            $table->string('timezone')->default('Asia/Shanghai');
            $table->json('meta')->nullable();
            $table->uuid('provisioning_token')->nullable();
            $table->timestamp('provisioning_heartbeat_at')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('saas')->create('saas_site_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable();
            $table->string('event');
            $table->string('status');
            $table->string('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at');
        });
        Schema::connection('saas')->create('saas_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id');
            $table->string('host')->unique();
            $table->string('scheme')->default('https');
            $table->boolean('is_primary')->default(false);
            $table->string('routing_behavior')->default('redirect');
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_a_fresh_site_lease_blocks_a_second_provisioner(): void
    {
        $site = Site::query()->create([
            'uuid'                      => '660e8400-e29b-41d4-a716-446655440000',
            'code'                      => 'store-a',
            'name'                      => 'Store A',
            'database_name'             => 'longos_store_a',
            'status'                    => Site::STATUS_PROVISIONING,
            'locale'                    => 'zh_cn',
            'timezone'                  => 'Asia/Shanghai',
            'provisioning_token'        => '9f77dfe2-1b34-4c04-9c8a-a8f9a9893e71',
            'provisioning_heartbeat_at' => now(),
        ]);

        $provisioner = new SiteProvisioner(
            $this->createMock(TenantManager::class),
            $this->createMock(DomainConfigWriter::class),
            new SiteAuditLogger,
        );
        $result = $provisioner->provision([
            'code'          => 'store-a',
            'database_name' => 'longos_store_a',
        ]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('正在另一个建站任务中处理', $result['error']);
        self::assertSame(Site::STATUS_PROVISIONING, $site->refresh()->status);
        self::assertSame('9f77dfe2-1b34-4c04-9c8a-a8f9a9893e71', $site->provisioning_token);
    }
}
