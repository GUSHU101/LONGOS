<?php

namespace Tests\Feature;

use App\Saas\Models\Site;
use App\Services\PlatformPayment\PlatformPaymentSettings;
use Beike\Admin\Http\Controllers\PluginController;
use Beike\Models\Setting;
use Beike\Plugin\Plugin;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

class PlatformPaymentTenantStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config([
            'saas.enabled'              => true,
            'database.default'          => 'saas',
            'database.connections.saas' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
            'cache.default' => 'array',
        ]);
        DB::purge('saas');
        DB::setDefaultConnection('saas');
        self::assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]));

        $tenant = (new Site)->forceFill([
            'id'     => 42,
            'status' => Site::STATUS_ACTIVE,
            'meta'   => ['control_site' => false],
        ]);
        app()->instance((string) config('multitenancy.current_tenant_container_key'), $tenant);

        $plugin = (new Plugin(base_path('plugins/LongosPlatformPay'), [
            'code' => 'longos_platform_pay',
            'type' => 'payment',
        ]))->setType('payment')->setDirname('LongosPlatformPay')->setEnabled(false)->setInstalled(true);
        app()->instance('plugin', new class($plugin)
        {
            public function __construct(private readonly Plugin $plugin) {}

            public function getPluginOrFail(string $code): Plugin
            {
                if ($code !== 'longos_platform_pay') {
                    throw new \RuntimeException('unexpected plugin');
                }

                return $this->plugin;
            }

            public function forgetRuntimeCache(): void {}
        });

        Setting::query()->create([
            'type'  => 'plugin',
            'space' => 'longos_platform_pay',
            'name'  => 'status',
            'value' => '0',
            'json'  => false,
        ]);

        $settings = $this->createMock(PlatformPaymentSettings::class);
        $settings->method('runtimeIssues')->willReturn([]);
        app()->instance(PlatformPaymentSettings::class, $settings);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_child_store_can_enable_and_disable_only_its_local_platform_payment_binding(): void
    {
        $controller = new PluginController;

        $enable = Request::create(
            'https://tenant.example.test/admin/plugins/longos_platform_pay/status',
            'PUT',
            ['status' => 1],
        );
        app()->instance('request', $enable);
        $enabledResponse = $controller->updateStatus($enable, 'longos_platform_pay');

        self::assertSame(200, $enabledResponse->getStatusCode());
        self::assertSame('1', $this->storedStatus());

        $disable = Request::create(
            'https://tenant.example.test/admin/plugins/longos_platform_pay/status',
            'PUT',
            ['status' => 0],
        );
        app()->instance('request', $disable);
        $disabledResponse = $controller->updateStatus($disable, 'longos_platform_pay');

        self::assertSame(200, $disabledResponse->getStatusCode());
        self::assertSame('0', $this->storedStatus());
    }

    private function storedStatus(): string
    {
        return (string) Setting::query()
            ->where('type', 'plugin')
            ->where('space', 'longos_platform_pay')
            ->where('name', 'status')
            ->value('value');
    }
}
