<?php

namespace Tests\Feature;

use App\Http\Controllers\SaasDomainProbeController;
use App\Saas\DomainProbeSignature;
use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class SaasDomainProbeTest extends TestCase
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
            'app.key'                   => 'base64:' . base64_encode(random_bytes(32)),
            'database.default'          => 'saas',
            'database.connections.saas' => $connection,
            'saas.enabled'              => true,
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

    public function test_probe_only_confirms_the_exact_activating_domain_and_signature(): void
    {
        $site = Site::query()->create([
            'uuid'          => (string) Str::uuid(),
            'code'          => 'probe-site',
            'name'          => 'Probe Site',
            'database_name' => 'probe_site',
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'zh_cn',
            'timezone'      => 'Asia/Shanghai',
        ]);
        $domain = $site->domains()->create([
            'host'       => 'shop.customer.example',
            'scheme'     => 'https',
            'is_primary' => false,
            'status'     => Domain::STATUS_ACTIVATING,
            'origin'     => Domain::ORIGIN_TENANT,
        ]);
        $controller = app(SaasDomainProbeController::class);
        $signature  = DomainProbeSignature::make($domain);

        $valid = Request::create(
            "/_longos/domain/probe?id={$domain->id}&signature={$signature}",
            'GET',
            server: ['HTTP_HOST' => $domain->host],
        );
        $response = $controller($valid);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('ready', $response->headers->get('X-Longos-Domain-Probe'));

        $wrongHost = Request::create(
            "/_longos/domain/probe?id={$domain->id}&signature={$signature}",
            'GET',
            server: ['HTTP_HOST' => 'other.example'],
        );
        self::assertSame(404, $controller($wrongHost)->getStatusCode());

        $wrongSignature = Request::create(
            "/_longos/domain/probe?id={$domain->id}&signature=" . str_repeat('0', 64),
            'GET',
            server: ['HTTP_HOST' => $domain->host],
        );
        self::assertSame(404, $controller($wrongSignature)->getStatusCode());

        $site->forceFill(['status' => Site::STATUS_SUSPENDED])->save();
        self::assertSame(404, $controller($valid)->getStatusCode());
    }
}
