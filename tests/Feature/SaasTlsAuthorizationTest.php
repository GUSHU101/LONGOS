<?php

namespace Tests\Feature;

use App\Http\Controllers\SaasTlsAuthorizationController;
use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class SaasTlsAuthorizationTest extends TestCase
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
            'saas.enabled'              => true,
            'saas.tls.driver'           => 'caddy',
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

    public function test_only_https_domains_on_active_sites_are_authorized(): void
    {
        $site = Site::query()->create([
            'uuid'          => (string) Str::uuid(),
            'code'          => 'tenant-a',
            'name'          => 'Tenant A',
            'database_name' => 'tenant_a',
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'zh_cn',
            'timezone'      => 'Asia/Shanghai',
        ]);
        $active = $site->domains()->create([
            'host'       => 'shop.example.com',
            'scheme'     => 'https',
            'is_primary' => true,
            'status'     => Domain::STATUS_ACTIVE,
            'origin'     => Domain::ORIGIN_PLATFORM,
        ]);
        $retired = $site->domains()->create([
            'host'       => 'old.example.com',
            'scheme'     => 'https',
            'is_primary' => false,
            'status'     => Domain::STATUS_RETIRED,
            'origin'     => Domain::ORIGIN_PLATFORM,
        ]);
        $activating = $site->domains()->create([
            'host'              => 'new.customer.example',
            'scheme'            => 'https',
            'is_primary'        => false,
            'status'            => Domain::STATUS_ACTIVATING,
            'origin'            => Domain::ORIGIN_TENANT,
            'verified_at'       => now(),
            'publication_error' => null,
        ]);
        $unverified = $site->domains()->create([
            'host'       => 'unverified.customer.example',
            'scheme'     => 'https',
            'is_primary' => false,
            'status'     => Domain::STATUS_ACTIVATING,
            'origin'     => Domain::ORIGIN_TENANT,
        ]);

        $controller = app(SaasTlsAuthorizationController::class);
        self::assertSame(204, $controller(Request::create('/_longos/tls/authorize?domain=' . $active->host))->getStatusCode());
        self::assertSame(204, $controller(Request::create('/_longos/tls/authorize?domain=' . $activating->host))->getStatusCode());
        self::assertSame(404, $controller(Request::create('/_longos/tls/authorize?domain=' . $unverified->host))->getStatusCode());
        self::assertSame(404, $controller(Request::create('/_longos/tls/authorize?domain=' . $retired->host))->getStatusCode());
        self::assertSame(404, $controller(Request::create('/_longos/tls/authorize?domain=unknown.example.com'))->getStatusCode());

        $site->forceFill(['status' => Site::STATUS_SUSPENDED])->save();
        self::assertSame(404, $controller(Request::create('/_longos/tls/authorize?domain=' . $active->host))->getStatusCode());
    }

    public function test_tls_ask_has_source_and_per_domain_rate_limits(): void
    {
        $limiter = RateLimiter::limiter('saas-tls-ask');
        self::assertNotNull($limiter);

        $limits = $limiter(Request::create(
            '/_longos/tls/authorize?domain=shop.example.com',
            'GET',
            server: ['REMOTE_ADDR' => '172.20.0.10'],
        ));

        self::assertCount(2, $limits);
        self::assertSame([600, 30], array_map(static fn ($limit): int => $limit->maxAttempts, $limits));
    }

    public function test_external_control_domain_is_never_reissued_by_caddy(): void
    {
        config([
            'saas.control_domains'             => ['admin.example.com'],
            'saas.tls.external_control_domain' => true,
        ]);
        $site = Site::query()->create([
            'uuid'          => (string) Str::uuid(),
            'code'          => 'control',
            'name'          => 'Control',
            'database_name' => 'control',
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'zh_cn',
            'timezone'      => 'Asia/Shanghai',
        ]);
        $site->domains()->create([
            'host'       => 'admin.example.com',
            'scheme'     => 'https',
            'is_primary' => true,
            'status'     => Domain::STATUS_ACTIVE,
            'origin'     => Domain::ORIGIN_PLATFORM,
        ]);
        $site->domains()->create([
            'host'       => 'tenant.example.com',
            'scheme'     => 'https',
            'is_primary' => false,
            'status'     => Domain::STATUS_ACTIVE,
            'origin'     => Domain::ORIGIN_PLATFORM,
        ]);

        $controller = app(SaasTlsAuthorizationController::class);
        self::assertSame(404, $controller(Request::create('/_longos/tls/authorize?domain=admin.example.com'))->getStatusCode());
        self::assertSame(204, $controller(Request::create('/_longos/tls/authorize?domain=tenant.example.com'))->getStatusCode());
    }
}
