<?php

namespace Tests\Feature;

use App\Saas\DomainConfigWriter;
use App\Saas\IdempotencyService;
use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use App\Saas\SiteAuditLogger;
use App\Saas\SiteDefinitionFactory;
use App\Saas\SiteHealthChecker;
use App\Saas\SiteProvisioner;
use App\Saas\Support\IdempotencyResult;
use App\Saas\Support\TenantDeferred;
use App\Saas\TenantFinder\DomainTenantFinder;
use App\Saas\TenantManager;
use Beike\Admin\Http\Controllers\DesignController;
use Beike\Admin\Http\Controllers\PluginController;
use Beike\Admin\Http\Controllers\ProductController;
use Beike\Admin\Http\Controllers\ThemeController;
use Beike\Admin\Repositories\Report\OrderReportRepo;
use Beike\Admin\Services\DashboardService;
use Beike\Admin\Services\DesignEditorCatalog;
use Beike\Models\AdminUser;
use Beike\Models\Product;
use Beike\Models\ProductSku;
use Beike\Repositories\ProductRepo;
use Beike\Repositories\SettingRepo;
use Beike\Shop\Http\Controllers\CheckoutController;
use Beike\Shop\Providers\PluginServiceProvider;
use Beike\Shop\Services\CartService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class SaasProvisioningEndToEndTest extends TestCase
{
    private string $databasePath;

    private string $runtimePath;

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

        $this->databasePath = tempnam(sys_get_temp_dir(), 'longos-e2e-');
        $this->runtimePath  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'longos-e2e-' . bin2hex(random_bytes(6));
        $connection         = [
            'driver'                  => 'sqlite',
            'database'                => $this->databasePath,
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ];

        config([
            'app.key'                                            => config('app.key') ?: 'base64:' . base64_encode(str_repeat('L', 32)),
            'database.default'                                   => 'saas',
            'database.connections.saas'                          => $connection,
            'database.connections.tenant'                        => $connection,
            'database.connections.tenant_admin'                  => $connection,
            'multitenancy.tenant_database_connection_name'       => 'tenant',
            'multitenancy.landlord_database_connection_name'     => 'saas',
            'saas.database_mode'                                 => 'prefix',
            'saas.enabled'                                       => true,
            'saas.shared_database'                               => $this->databasePath,
            'saas.base_domain'                                   => 'shops.example.test',
            'saas.default_scheme'                                => 'https',
            'saas.isolate_files'                                 => true,
            'saas.idempotency.ttl_hours'                         => 24,
            'saas.idempotency.processing_timeout_minutes'        => 120,
            'saas.domain_config.path'                            => $this->runtimePath . DIRECTORY_SEPARATOR . 'domains.conf',
            'saas.domain_config.manifest_path'                   => $this->runtimePath . DIRECTORY_SEPARATOR . 'domains.json',
            'cache.default'                                      => 'array',
        ]);

        DB::purge('saas');
        DB::purge('tenant');
        DB::purge('tenant_admin');
        DB::setDefaultConnection('saas');

        self::assertSame(0, Artisan::call('migrate:fresh', [
            '--database'       => 'saas',
            '--force'          => true,
            '--no-interaction' => true,
        ]), Artisan::output());
        self::assertSame(0, Artisan::call('db:seed', [
            '--database'       => 'saas',
            '--force'          => true,
            '--no-interaction' => true,
        ]), Artisan::output());
    }

    protected function tearDown(): void
    {
        Site::forgetCurrent();
        DB::disconnect('tenant_admin');
        DB::disconnect('tenant');
        DB::disconnect('saas');
        app(Filesystem::class)->deleteDirectory($this->runtimePath);
        app(Filesystem::class)->deleteDirectory(storage_path('framework/sessions/tenants/merchant-one'));
        app(Filesystem::class)->deleteDirectory(storage_path('framework/sessions/tenants/merchant-two'));
        if ($this->createdInstalledMarker) {
            @unlink(storage_path('installed'));
        }
        @unlink($this->databasePath);
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_control_request_provisions_and_replays_a_complete_tenant_store(): void
    {
        $control = Site::query()->create([
            'uuid'            => '2792c8a4-b891-431e-a20c-8daafd15b812',
            'code'            => 'main',
            'name'            => 'LONGOS Control',
            'database_name'   => $this->databasePath,
            'status'          => Site::STATUS_ACTIVE,
            'locale'          => 'zh_cn',
            'timezone'        => 'Asia/Shanghai',
            'provisioned_at'  => now(),
            'meta'            => [
                'control_site'  => true,
                'database_mode' => 'control',
                'isolate_files' => false,
            ],
        ]);
        $control->domains()->create([
            'host'             => 'control.example.test',
            'scheme'           => 'https',
            'is_primary'       => true,
            'routing_behavior' => Domain::ROUTING_REDIRECT,
            'status'           => Domain::STATUS_ACTIVE,
        ]);
        $control->load('domains')->makeCurrent();
        $controlConnection = DB::getDefaultConnection();

        $writer = new class(new Filesystem) extends DomainConfigWriter
        {
            public int $syncCount = 0;

            public function synchronized(callable $callback): mixed
            {
                return $callback();
            }

            public function sync(bool $reload = true): array
            {
                $this->syncCount++;

                return [
                    'config_path'   => 'test://domains.conf',
                    'manifest_path' => 'test://domains.json',
                    'hosts'         => 2,
                    'reloaded'      => true,
                ];
            }
        };
        $tenants     = new TenantManager(app('db'));
        $provisioner = new SiteProvisioner($tenants, $writer, new SiteAuditLogger);
        $definition  = (new SiteDefinitionFactory)->normalize([
            'code'           => 'merchant-one',
            'name'           => '商户一号店',
            'admin_email'    => 'owner@merchant-one.test',
            'admin_password' => 'A-strong-password-2026',
        ]);
        $payload = ['sites' => [[
            'code'           => 'merchant-one',
            'name'           => '商户一号店',
            'admin_email'    => 'owner@merchant-one.test',
            'admin_password' => 'A-strong-password-2026',
        ]]];
        $runs      = 0;
        $operation = function (callable $heartbeat) use (&$runs, $definition, $provisioner): IdempotencyResult {
            $runs++;

            return new IdempotencyResult([
                'message' => '批量建站已执行完成。',
                'data'    => $provisioner->provisionMany([$definition], true, true, $heartbeat),
            ], 201);
        };

        $idempotency = new IdempotencyService;
        $key         = '19969bb4-d664-46be-a244-7e528448579c';
        $created     = $idempotency->execute('control.sites.batch', $key, $payload, $operation);
        $replayed    = $idempotency->execute('control.sites.batch', $key, $payload, $operation);

        self::assertSame(1, $runs);
        self::assertSame(1, $writer->syncCount);
        self::assertFalse($created->replayed);
        self::assertTrue($replayed->replayed);
        self::assertSame($created->body, $replayed->body);
        self::assertTrue($created->body['data'][0]['ok']);
        self::assertSame(Site::STATUS_ACTIVE, $created->body['data'][0]['status']);
        self::assertSame('https://merchant-one.shops.example.test', $created->body['data'][0]['url']);
        self::assertSame($control->getKey(), Site::current()?->getKey());

        $tenant = Site::query()->where('code', 'merchant-one')->with('domains')->firstOrFail();
        self::assertTrue($tenant->usesSharedDatabase());
        self::assertSame(Site::STATUS_ACTIVE, $tenant->status);
        self::assertNull($tenant->provisioning_token);
        self::assertNotEmpty($tenant->provisioned_at);
        self::assertSame('merchant-one.shops.example.test', $tenant->domains->firstWhere('is_primary', true)?->host);

        $tenants->run($tenant, function () use ($control): void {
            self::assertTrue(Schema::hasTable('admin_users'));
            self::assertTrue(Schema::hasTable('plugins'));
            self::assertTrue(Schema::hasTable('products'));
            self::assertTrue(Schema::hasTable('orders'));
            self::assertFalse(Schema::hasTable('saas_sites'));
            self::assertGreaterThan(0, DB::table('products')->count());
            self::assertGreaterThan(0, DB::table('plugins')->count());

            $administrator = DB::table('admin_users')->where('email', 'owner@merchant-one.test')->first();
            self::assertNotNull($administrator);
            self::assertTrue((bool) $administrator->active);
            self::assertTrue(Hash::check('A-strong-password-2026', $administrator->password));
            self::assertSame('商户一号店', DB::table('settings')
                ->where('type', 'system')
                ->where('space', 'base')
                ->where('name', 'store_name')
                ->value('value'));
            self::assertNotSame($control->getKey(), Site::current()?->getKey());

            // Data written after a successful provision must survive an
            // operator retry of the same site. This also reproduces MySQL's
            // product-review foreign-key relationship that rejects TRUNCATE.
            DB::table('brands')->insert([
                'id'         => 999999,
                'name'       => 'Tenant Brand',
                'first'      => 'T',
                'logo'       => '',
                'sort_order' => 999,
                'active'     => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('longos_product_reviews')->insert([
                'product_id'        => 1,
                'author_name'       => 'Tenant Customer',
                'author_country'    => 'US',
                'rating'            => 5,
                'title'             => 'Persist across retry',
                'body'              => 'This merchant review must never be removed by provisioning.',
                'images'            => null,
                'verified_purchase' => true,
                'published'         => true,
                'sort_order'        => 1,
                'reviewed_at'       => now(),
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        });

        self::assertSame($control->getKey(), Site::current()?->getKey());
        self::assertSame($controlConnection, DB::getDefaultConnection());
        $health = (new SiteHealthChecker($tenants, new SiteAuditLogger))->check($tenant->load('domains'), false);
        self::assertSame('healthy', $health['status']);
        self::assertTrue($health['database']['root_admin_ready']);
        self::assertSame([], $health['database']['missing_settings']);
        self::assertSame($this->databasePath, config('database.connections.tenant.database'));
        self::assertSame('', config('database.connections.tenant.prefix'));

        // Simulate a recoverable failed attempt whose operator retries with
        // a new administrator email. The returned credentials must still
        // belong to the ID=1 root administrator, never a second limited user.
        Site::query()->whereKey($tenant->getKey())->update([
            'status'                    => Site::STATUS_FAILED,
            'provisioning_token'        => null,
            'provisioning_heartbeat_at' => null,
        ]);
        $retryDefinition = (new SiteDefinitionFactory)->normalize([
            'code'           => 'merchant-one',
            'name'           => '商户一号店',
            'admin_email'    => 'new-owner@merchant-one.test',
            'admin_password' => 'Another-strong-password-2026',
        ]);
        $retry = $provisioner->provisionMany([$retryDefinition], true, true);

        self::assertTrue($retry[0]['ok']);
        self::assertSame(Site::STATUS_ACTIVE, $retry[0]['status']);
        self::assertSame(2, $writer->syncCount);
        $tenant->refresh();
        $tenants->run($tenant, function (): void {
            self::assertSame(1, DB::table('admin_users')->count());
            $root = DB::table('admin_users')->find(1);
            self::assertNotNull($root);
            self::assertSame('new-owner@merchant-one.test', $root->email);
            self::assertTrue(Hash::check('Another-strong-password-2026', $root->password));
            self::assertSame('Tenant Brand', DB::table('brands')->where('id', 999999)->value('name'));
            self::assertTrue(DB::table('longos_product_reviews')->where('title', 'Persist across retry')->exists());
        });

        self::assertSame($control->getKey(), Site::current()?->getKey());
        self::assertSame($controlConnection, DB::getDefaultConnection());

        $secondDefinition = (new SiteDefinitionFactory)->normalize([
            'code'           => 'merchant-two',
            'name'           => '商户二号店',
            'admin_email'    => 'owner@merchant-two.test',
            'admin_password' => 'Second-strong-password-2026',
        ]);
        $secondResult = $provisioner->provisionMany([$secondDefinition], true, true);
        self::assertTrue($secondResult[0]['ok']);
        self::assertSame(Site::STATUS_ACTIVE, $secondResult[0]['status']);
        self::assertSame(3, $writer->syncCount);

        $tenant       = $tenant->fresh(['domains']);
        $second       = Site::query()->where('code', 'merchant-two')->with('domains')->firstOrFail();

        // Exercise the real HTTP kernel using the generated tenant subdomain.
        // This covers host discovery, database switching, storefront/theme
        // rendering, the tenant admin entry point and termination cleanup.
        Site::forgetCurrent();
        $httpKernel = app(HttpKernel::class);
        foreach (['/', '/admin/login'] as $path) {
            $request  = Request::create('https://merchant-two.shops.example.test' . $path, 'GET');
            $response = $httpKernel->handle($request);

            try {
                self::assertSame(200, $response->getStatusCode(), $response->getContent());
                self::assertSame($second->getKey(), Site::current()?->getKey());
            } finally {
                $httpKernel->terminate($request, $response);
            }
            self::assertNull(Site::current());
        }
        $control->fresh(['domains'])->makeCurrent();

        // Register the packaged plugin extension points once, exactly as an
        // Octane or queue worker does. Runtime guards must then follow each
        // tenant instead of retaining the control site's plugin state.
        (new PluginServiceProvider(app()))->boot();
        self::assertTrue(installed());
        self::assertFalse(app('plugin')->getPlugins()->has('latest_products'));
        self::assertGreaterThan(
            0,
            app('eventy')->getFilter()->getListeners()->where('hook', 'admin.design.index.data')->count(),
        );
        $firstRuntime = $tenants->run($tenant, function (): array {
            self::assertTrue(Auth::guard(AdminUser::AUTH_GUARD)->attempt([
                'email'    => 'new-owner@merchant-one.test',
                'password' => 'Another-strong-password-2026',
                'active'   => true,
            ]));
            Auth::guard(AdminUser::AUTH_GUARD)->logout();

            SettingRepo::storeValue('design_setting', [
                'engine'  => 'puck',
                'modules' => [['type' => 'merchant-one-hero']],
            ]);
            $nativeCodes = array_column(DesignEditorCatalog::core(), 'code');
            self::assertContains('latest_products', $nativeCodes);
            self::assertContains('bestseller', $nativeCodes);

            return [
                'cookie'        => config('session.cookie'),
                'session_files' => config('session.files'),
                'catalog_root'  => config('filesystems.disks.catalog.root'),
                'upload_root'   => config('filesystems.disks.upload.root'),
                'design'        => system_setting('base.design_setting'),
            ];
        });

        $secondRuntime = $tenants->run($second, function (): array {
            $adminRequest = Request::create('https://merchant-two.shops.example.test/admin/themes', 'GET');
            $adminSession = app('session')->driver();
            $adminSession->setId('longos-admin-theme-e2e');
            $adminSession->start();
            $adminRequest->setLaravelSession($adminSession);
            app()->instance('request', $adminRequest);

            self::assertTrue(Auth::guard(AdminUser::AUTH_GUARD)->attempt([
                'email'    => 'owner@merchant-two.test',
                'password' => 'Second-strong-password-2026',
                'active'   => true,
            ]));
            view()->share('admin_base_url', admin_route('home.index'));

            // The tenant theme page must render fully and must not trigger the
            // control-plane marketplace version endpoint in the background.
            $themePage = (new ThemeController)->index();
            self::assertInstanceOf(Response::class, $themePage);
            self::assertSame(200, $themePage->getStatusCode());
            self::assertStringContainsString('version_check_url: null', $themePage->getContent());
            self::assertStringContainsString('__THEME__', $themePage->getContent());

            // Enabling/importing the packaged theme must write to this tenant
            // atomically and return a usable JSON response.
            $themeUpdateRequest = Request::create(
                'https://merchant-two.shops.example.test/admin/themes/default',
                'PUT',
                ['import_demo' => true],
            );
            $themeUpdateRequest->setLaravelSession($adminSession);
            app()->instance('request', $themeUpdateRequest);
            $themeUpdate = (new ThemeController)->update($themeUpdateRequest, 'default');
            self::assertSame(200, $themeUpdate->getStatusCode());
            self::assertSame('success', $themeUpdate->getData(true)['status']);
            self::assertSame('default', system_setting('base.theme'));
            self::assertIsArray(system_setting('base.design_setting'));

            // Exercise the three core merchant work areas using the same
            // authenticated tenant runtime: visual design, product listing
            // and local plugin management. Rendering the complete views also
            // catches shared layout/hooks that a controller-only test misses.
            $designRequest = Request::create('https://merchant-two.shops.example.test/admin/design/builder', 'GET');
            $designRequest->setLaravelSession($adminSession);
            app()->instance('request', $designRequest);
            $designHtml = (new DesignController)->index($designRequest)->render();
            self::assertStringContainsString('id="beike-puck-builder"', $designHtml);

            $productRequest = Request::create('https://merchant-two.shops.example.test/admin/products', 'GET');
            $productRequest->setLaravelSession($adminSession);
            app()->instance('request', $productRequest);
            $productHtml = (new ProductController)->index($productRequest)->render();
            self::assertStringContainsString('id="product-app"', $productHtml);
            self::assertStringContainsString('version_check_url: null', $productHtml);

            $pluginRequest = Request::create('https://merchant-two.shops.example.test/admin/plugins', 'GET');
            $pluginRequest->setLaravelSession($adminSession);
            app()->instance('request', $pluginRequest);
            $pluginHtml = (new PluginController)->index()->render();
            self::assertStringContainsString('id="plugins-app"', $pluginHtml);
            self::assertStringContainsString('version_check_url: null', $pluginHtml);

            // Raw aggregate/order expressions must use the tenant-prefixed
            // physical table names too. These calls cover dashboard, report
            // and curated-product queries that normal page rendering defers.
            $dashboard = new DashboardService;
            self::assertIsArray($dashboard->getProductRanking('hot', 'today'));
            $overview = $dashboard->getOverview('today');
            self::assertArrayHasKey('stats', $overview);
            self::assertArrayHasKey('sources', $overview);
            self::assertArrayHasKey('countries', $overview);
            self::assertGreaterThan(0, OrderReportRepo::getProductViews()->count());
            $productIds = Product::query()
                ->where('active', true)
                ->whereHas('masterSku', static fn ($query) => $query->where('active', true)->where('quantity', '>', 0))
                ->orderBy('id')
                ->limit(2)
                ->pluck('id')
                ->all();
            self::assertCount(count($productIds), ProductRepo::getProductsByIds($productIds)->jsonSerialize());
            if (count($productIds) > 1) {
                $recommendationSource = Product::query()->findOrFail($productIds[0]);
                $recommendations      = ProductRepo::getRelatedProducts($recommendationSource, [$productIds[1], $productIds[0]], 8)->jsonSerialize();
                self::assertLessThanOrEqual(8, count($recommendations));
                self::assertNotContains($productIds[0], array_column($recommendations, 'id'));
                self::assertSame($productIds[1], $recommendations[0]['id']);
            }

            Auth::guard(AdminUser::AUTH_GUARD)->logout();
            self::assertNull(DB::table('settings')
                ->where('type', 'system')
                ->where('space', 'base')
                ->where('name', 'design_setting')
                ->where('value', 'like', '%merchant-one-hero%')
                ->first());
            self::assertFalse(DB::table('plugins')->where('code', 'latest_products')->exists());
            self::assertSame('missing', plugin_setting('latest_products.status', 'missing'));
            $nativeCodes = array_column(DesignEditorCatalog::core(), 'code');
            self::assertContains('latest_products', $nativeCodes);
            self::assertContains('bestseller', $nativeCodes);

            // Render the real checkout with a valid guest cart. A successful
            // view cache compilation alone cannot detect runtime assumptions
            // about cart, shipping and payment payloads.
            $checkoutRequest = Request::create('https://merchant-two.shops.example.test/checkout', 'GET');
            $session         = app('session')->driver();
            $session->setId('longos-checkout-e2e');
            $session->start();
            $checkoutRequest->setLaravelSession($session);
            app()->instance('request', $checkoutRequest);

            $sku = ProductSku::query()->where('active', true)->where('quantity', '>', 0)->firstOrFail();
            CartService::add($sku, 1);
            $checkout = (new CheckoutController)->index();
            self::assertInstanceOf(Response::class, $checkout);
            self::assertSame(200, $checkout->getStatusCode());
            $checkoutHtml = $checkout->getContent();
            self::assertStringContainsString('id="submit-checkout"', $checkoutHtml);
            self::assertStringContainsString('checkout-address-app', $checkoutHtml);

            self::assertNotNull(app('router')->getRoutes()->getByName('shop.latest_products'));

            return [
                'cookie'        => config('session.cookie'),
                'session_files' => config('session.files'),
                'catalog_root'  => config('filesystems.disks.catalog.root'),
                'upload_root'   => config('filesystems.disks.upload.root'),
                'store_name'    => system_setting('base.store_name'),
            ];
        });

        self::assertSame('merchant-one-hero', $firstRuntime['design']['modules'][0]['type']);
        self::assertSame('商户二号店', $secondRuntime['store_name']);
        foreach (['cookie', 'session_files', 'catalog_root', 'upload_root'] as $isolatedRuntimeKey) {
            self::assertNotSame($firstRuntime[$isolatedRuntimeKey], $secondRuntime[$isolatedRuntimeKey]);
        }

        // The control storefront shares the checkout template but uses the
        // unprefixed commerce tables. Render it independently so both the main
        // store and generated tenant stores are protected by this regression.
        $controlCheckoutHtml = $tenants->run($control, static function (): string {
            $checkoutRequest = Request::create('https://control.example.test/checkout', 'GET');
            $session         = app('session')->driver();
            $session->setId('longos-control-checkout-e2e');
            $session->start();
            $checkoutRequest->setLaravelSession($session);
            app()->instance('request', $checkoutRequest);

            $sku = ProductSku::query()->where('active', true)->where('quantity', '>', 0)->firstOrFail();
            CartService::add($sku, 1);
            $checkout = (new CheckoutController)->index();
            self::assertInstanceOf(Response::class, $checkout);
            self::assertSame(200, $checkout->getStatusCode());

            return $checkout->getContent();
        });
        self::assertStringContainsString('id="submit-checkout"', $controlCheckoutHtml);
        self::assertStringContainsString('checkout-address-app', $controlCheckoutHtml);

        // Laravel runs application terminating callbacks after the HTTP
        // tenant middleware has forgotten the site. Deferred plugin work must
        // therefore restore its originating tenant and then restore the
        // control context after it has finished.
        $deferred = $tenants->run($second, static fn (): \Closure => TenantDeferred::capture(
            static fn (): array => [
                'site_id'    => Site::current()?->getKey(),
                'store_name' => system_setting('base.store_name'),
            ],
        ));
        self::assertSame($control->getKey(), Site::current()?->getKey());
        $deferredRuntime = $deferred();
        self::assertSame($second->getKey(), $deferredRuntime['site_id']);
        self::assertSame('商户二号店', $deferredRuntime['store_name']);
        self::assertSame($control->getKey(), Site::current()?->getKey());

        $finder  = new DomainTenantFinder;
        $matched = $finder->findForRequest(Request::create('https://merchant-two.shops.example.test/'));
        self::assertSame($second->getKey(), $matched?->getKey());
        self::assertNull($finder->findForRequest(Request::create('https://unknown.shops.example.test/')));
        self::assertSame($control->getKey(), Site::current()?->getKey());
        self::assertSame($controlConnection, DB::getDefaultConnection());
    }
}
