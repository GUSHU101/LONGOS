<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePluginEnabled;
use App\Http\Middleware\RunEnabledPluginMiddleware;
use Beike\Admin\Services\SettingService;
use Beike\Plugin\Asset;
use Beike\Plugin\Manager;
use Beike\Plugin\RuntimeGuard;
use Beike\Repositories\PluginRepo;
use Beike\Repositories\SettingRepo;
use Beike\Shop\Providers\PluginServiceProvider;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SaasPluginRouteIsolationTest extends TestCase
{
    private string $databasePath;

    private bool $createdInstalledMarker = false;

    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        if (! is_file(storage_path('installed'))) {
            file_put_contents(storage_path('installed'), 'test');
            $this->createdInstalledMarker = true;
        }

        $this->databasePath = tempnam(sys_get_temp_dir(), 'longos-plugin-route-');
        config([
            'database.default'                       => 'plugin_route_test',
            'database.connections.plugin_route_test' => [
                'driver'                  => 'sqlite',
                'database'                => $this->databasePath,
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
            'bk' => [],
        ]);
        DB::purge('plugin_route_test');
        DB::setDefaultConnection('plugin_route_test');

        Schema::create('plugins', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('code')->unique();
            $table->timestamps();
        });
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('space');
            $table->string('name');
            $table->text('value')->nullable();
            $table->boolean('json')->default(false);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        PluginRepo::resetRuntimeCache();
        DB::disconnect('plugin_route_test');
        if ($this->createdInstalledMarker) {
            @unlink(storage_path('installed'));
        }
        @unlink($this->databasePath);
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_packaged_routes_are_cacheable_and_guarded_by_current_tenant_status(): void
    {
        $provider = new PluginServiceProvider(app());
        $register = Closure::bind(function (): void {
            $this->pluginBasePath = base_path('plugins');
            $this->registerRoutes('BeikeShopTestSandbox', 'beike_shop_test_sandbox');
        }, $provider, PluginServiceProvider::class);
        $register();

        $shopRoute = collect(Route::getRoutes()->getRoutes())
            ->first(static fn ($route): bool => $route->uri() === 'beikeshop-test/refund-policy');
        self::assertNotNull($shopRoute);
        self::assertSame('shop.beikeshop_test_sandbox.refund_policy', $shopRoute->getName());
        self::assertContains('shop', $shopRoute->gatherMiddleware());
        self::assertContains('plugin.enabled:beike_shop_test_sandbox', $shopRoute->gatherMiddleware());

        $apiRoute = collect(Route::getRoutes()->getRoutes())
            ->first(static fn ($route): bool => $route->uri() === 'api/beikeshop-test/checkout/mode');
        self::assertNotNull($apiRoute);
        self::assertContains('plugin.enabled:beike_shop_test_sandbox', $apiRoute->gatherMiddleware());

        $marketplaceDownload = Route::getRoutes()->getByName(admin_name() . '.marketing.download');
        self::assertNotNull($marketplaceDownload);
        self::assertContains('saas.platform-admin', $marketplaceDownload->gatherMiddleware());
    }

    public function test_shopify_transfer_routes_are_tenant_guarded_and_follow_product_permissions(): void
    {
        $provider = new PluginServiceProvider(app());
        $register = Closure::bind(function (): void {
            $this->pluginBasePath = base_path('plugins');
            $this->registerRoutes('ShopifyProductTransfer', 'shopify_product_transfer');
        }, $provider, PluginServiceProvider::class);
        $register();
        // The application was already bootstrapped before this test manually
        // registers another packaged plugin, so rebuild Laravel's name index.
        Route::getRoutes()->refreshNameLookups();

        $index = Route::getRoutes()->getByName(admin_name() . '.shopify_product_transfer.index');
        self::assertNotNull($index);
        self::assertSame(admin_name() . '/shopify-products', $index->uri());
        self::assertContains('admin', $index->gatherMiddleware());
        self::assertContains('plugin.enabled:shopify_product_transfer', $index->gatherMiddleware());
        self::assertContains('can:products_index', $index->gatherMiddleware());

        $import = Route::getRoutes()->getByName(admin_name() . '.shopify_product_transfer.import');
        self::assertNotNull($import);
        self::assertSame(['POST'], $import->methods());
        self::assertContains('plugin.enabled:shopify_product_transfer', $import->gatherMiddleware());
        self::assertContains('can:products_create', $import->gatherMiddleware());
    }

    public function test_plugin_endpoint_access_never_leaks_between_tenant_states(): void
    {
        DB::table('plugins')->insert([
            'type'       => 'view',
            'code'       => 'beike_shop_test_sandbox',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('settings')->insert([
            'type'       => 'plugin',
            'space'      => 'beike_shop_test_sandbox',
            'name'       => 'status',
            'value'      => '1',
            'json'       => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        config(['bk.plugin.beike_shop_test_sandbox.status' => '1']);

        $middleware = new EnsurePluginEnabled;
        $response   = $middleware->handle(
            Request::create('/beikeshop-test/refund-policy'),
            static fn (): Response => new Response('tenant-a'),
            'beike_shop_test_sandbox',
        );
        self::assertSame('tenant-a', $response->getContent());

        // A tenant with the same route cache but a disabled plugin must get a
        // 404, not another tenant's feature or controller response.
        config(['bk.plugin.beike_shop_test_sandbox.status' => '0']);
        $this->assertNotFound(static fn () => $middleware->handle(
            Request::create('/beikeshop-test/refund-policy'),
            static fn (): Response => new Response('must-not-run'),
            'beike_shop_test_sandbox',
        ));

        // Enabling a setting without installing the plugin is also denied.
        DB::table('plugins')->delete();
        PluginRepo::resetRuntimeCache();
        config(['bk.plugin.beike_shop_test_sandbox.status' => '1']);
        $this->assertNotFound(static fn () => $middleware->handle(
            Request::create('/beikeshop-test/refund-policy'),
            static fn (): Response => new Response('must-not-run'),
            'beike_shop_test_sandbox',
        ));
    }

    public function test_plugin_manager_reads_installation_state_from_the_current_tenant_database(): void
    {
        DB::table('plugins')->insert([
            'type'       => 'view',
            'code'       => 'beike_shop_test_sandbox',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        config([
            'bk.plugin.beike_shop_test_sandbox.status' => '1',
            'bk.plugin.stripe.status'                  => '1',
        ]);
        PluginRepo::resetRuntimeCache();

        $manager = new Manager;
        $plugins = $manager->getPlugins();

        self::assertTrue($plugins->get('beike_shop_test_sandbox')->getInstalled());
        self::assertTrue($plugins->get('beike_shop_test_sandbox')->getEnabled());
        self::assertFalse($plugins->get('stripe')->getInstalled());
        self::assertFalse($plugins->get('stripe')->getEnabled());
        self::assertTrue($manager->getEnabledPlugins()->has('beike_shop_test_sandbox'));
        self::assertFalse($manager->getEnabledPlugins()->has('stripe'));
    }

    public function test_hooks_and_middlewares_follow_the_current_tenant_at_runtime(): void
    {
        $hook = 'tests.plugin.runtime.' . bin2hex(random_bytes(6));
        RuntimeGuard::whileRegistering('beike_shop_test_sandbox', static function () use ($hook): void {
            add_hook_filter($hook, static fn (string $value): string => $value . '-enabled');
        });

        self::assertSame('base', hook_filter($hook, 'base'));
        $middleware = new RunEnabledPluginMiddleware;
        self::assertSame('core', $middleware->handle(
            Request::create('/'),
            static fn (): Response => new Response('core'),
            'beike_shop_test_sandbox',
            RuntimeMarkerMiddleware::class,
        )->getContent());

        DB::table('plugins')->insert([
            'type'       => 'view',
            'code'       => 'beike_shop_test_sandbox',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        config(['bk.plugin.beike_shop_test_sandbox.status' => '1']);
        PluginRepo::resetRuntimeCache();
        app('plugin')->forgetRuntimeCache();

        self::assertSame('base-enabled', hook_filter($hook, 'base'));
        self::assertSame('plugin', $middleware->handle(
            Request::create('/'),
            static fn (): Response => new Response('core'),
            'beike_shop_test_sandbox',
            RuntimeMarkerMiddleware::class,
        )->getContent());

        config(['bk.plugin.beike_shop_test_sandbox.status' => '0']);
        app('plugin')->forgetRuntimeCache();
        self::assertSame('base', hook_filter($hook, 'base'));
    }

    public function test_setting_refresh_is_tenant_local_and_does_not_clear_deployment_caches(): void
    {
        DB::table('settings')->insert([
            'type'       => 'system',
            'space'      => 'base',
            'name'       => 'store_name',
            'value'      => 'Tenant A',
            'json'       => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        config(['bk.system.base.store_name' => 'stale']);

        SettingRepo::clearCache();

        self::assertSame('Tenant A', config('bk.system.base.store_name'));
        self::assertStringNotContainsString(
            "Artisan::call('optimize:clear')",
            (string) file_get_contents(base_path('beike/Repositories/SettingRepo.php')),
        );
    }

    public function test_saas_admin_path_is_platform_wide_and_cannot_be_overridden_by_a_tenant(): void
    {
        DB::table('settings')->insert([
            'type'       => 'system',
            'space'      => 'base',
            'name'       => 'admin_name',
            'value'      => 'admin',
            'json'       => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        config([
            'saas.enabled'              => true,
            'beike.admin_name'          => null,
            'bk.system.base.admin_name' => 'tenant-private-path',
        ]);

        self::assertSame('admin', admin_name());
        SettingService::storeSettings(['admin_name' => 'changed-by-tenant']);
        self::assertSame('admin', DB::table('settings')
            ->where('type', 'system')
            ->where('space', 'base')
            ->where('name', 'admin_name')
            ->value('value'));

        config(['beike.admin_name' => 'platform-console']);
        self::assertSame('platform-console', admin_name());
    }

    public function test_plugin_assets_cannot_escape_their_static_directory(): void
    {
        $asset = Asset::getInstance('beike_shop_test_sandbox');
        $valid = $asset->getContent('3ds-global-7/challenge-bridge.js');

        self::assertSame('application/javascript', $valid['type']);
        self::assertNotSame('', $valid['content']);
        self::assertSame([], $asset->getContent('../../Stripe/Static/css/demo.css'));
        self::assertSame([], $asset->getContent('../Bootstrap.php'));
        self::assertSame([], $asset->getContent('/css/demo.css'));

        $this->expectException(InvalidArgumentException::class);
        Asset::getInstance('../Stripe');
    }

    private function assertNotFound(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected the tenant plugin route to be unavailable.');
        } catch (NotFoundHttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
        }
    }
}

class RuntimeMarkerMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return new Response('plugin');
    }
}
