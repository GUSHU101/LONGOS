<?php

namespace Tests\Feature;

use App\Saas\Models\Site;
use Beike\Plugin\Plugin as PluginPackage;
use Beike\Repositories\PluginRepo;
use Beike\Repositories\SettingRepo;
use Beike\Services\CloudflareIpRangeService;
use Beike\Shop\Providers\PluginServiceProvider;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Plugin\LongosInstantTranslate\Controllers\ShopLocaleController;
use Plugin\LongosInstantTranslate\Services\InstantTranslateSettings;
use Plugin\LongosInstantTranslate\Services\LocaleResolver;
use Plugin\LongosInstantTranslate\Services\VerifiedVisitorCountry;

class InstantTranslatePluginLifecycleTest extends TestCase
{
    private string $databasePath;

    private string $publicPath;

    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $this->databasePath = tempnam(sys_get_temp_dir(), 'longos-instant-translate-db-');
        $this->publicPath   = sys_get_temp_dir() . '/longos-instant-translate-public-' . bin2hex(random_bytes(8));
        File::makeDirectory($this->publicPath, 0755, true);
        $app->usePublicPath($this->publicPath);

        config([
            'database.default'                            => 'instant_translate_test',
            'database.connections.instant_translate_test' => [
                'driver'                  => 'sqlite',
                'database'                => $this->databasePath,
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
            'bk' => [],
        ]);
        DB::purge('instant_translate_test');
        DB::setDefaultConnection('instant_translate_test');

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
        if (app()->bound('plugin')) {
            app('plugin')->forgetRuntimeCache();
        }
        DB::disconnect('instant_translate_test');
        @unlink($this->databasePath);
        File::deleteDirectory($this->publicPath);
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_install_enable_disable_and_preserve_bundled_library(): void
    {
        $path     = base_path('plugins/LongosInstantTranslate');
        $manifest = json_decode((string) file_get_contents($path . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
        $plugin   = (new PluginPackage($path, $manifest))
            ->setType('feature')
            ->setDirname('LongosInstantTranslate');

        PluginRepo::installPlugin($plugin);

        self::assertTrue(PluginRepo::installed('longos_instant_translate'));
        self::assertFileExists($this->publicPath . '/plugin/longos_instant_translate/translate-widget.js');
        self::assertFileExists($this->publicPath . '/plugin/longos_instant_translate/translate-widget.css');
        self::assertFileExists($this->publicPath . '/plugin/longos_instant_translate/vendor/translate.min.js');

        SettingRepo::update('plugin', 'longos_instant_translate', [
            'status'            => '1',
            'runtime_enabled'   => '1',
            'enabled_languages' => ['english', 'japanese'],
        ]);
        self::assertTrue(SettingRepo::getPluginStatus('longos_instant_translate'));
        self::assertSame(['english', 'japanese'], plugin_setting('longos_instant_translate.enabled_languages'));

        SettingRepo::update('plugin', 'longos_instant_translate', ['status' => '0']);
        self::assertFalse(SettingRepo::getPluginStatus('longos_instant_translate'));
        self::assertTrue(Schema::hasTable('lit_translation_entries'));
        self::assertTrue(Schema::hasTable('lit_provider_accounts'));

        self::assertTrue(Schema::hasTable('lit_translation_entries'));
        $this->expectException(\RuntimeException::class);
        PluginRepo::uninstallPlugin($plugin);
    }

    public function test_routes_are_guarded_by_the_active_tenant_plugin_status(): void
    {
        $provider = new PluginServiceProvider(app());
        $register = Closure::bind(function (): void {
            $this->pluginBasePath = base_path('plugins');
            $this->registerRoutes('LongosInstantTranslate', 'longos_instant_translate');
        }, $provider, PluginServiceProvider::class);
        $register();

        $routes  = collect(Route::getRoutes()->getRoutes());
        $shop    = $routes->first(static fn ($route): bool => $route->getName() === 'shop.longos_instant_translate.context');
        $admin   = $routes->first(static fn ($route): bool => $route->getName() === admin_name() . '.longos_instant_translate.index');
        $library = $routes->first(static fn ($route): bool => $route->getName() === 'shop.longos_instant_translate.library');

        self::assertNotNull($shop);
        self::assertSame('_longos/instant-translate/context', $shop->uri());
        self::assertContains('plugin.enabled:longos_instant_translate', $shop->gatherMiddleware());
        self::assertNotNull($library);
        self::assertSame('_longos/instant-translate/library', $library->uri());
        self::assertNotNull($admin);
        self::assertContains('plugin.enabled:longos_instant_translate', $admin->gatherMiddleware());
        self::assertContains('can:plugins_index', $admin->gatherMiddleware());
    }

    public function test_context_response_is_private_and_uses_verified_country(): void
    {
        SettingRepo::update('plugin', 'longos_instant_translate', [
            'runtime_enabled'   => '1',
            'auto_country'      => '1',
            'browser_fallback'  => '1',
            'enabled_languages' => ['english', 'japanese', 'french'],
        ]);
        $tenantKey = (string) config('multitenancy.current_tenant_container_key', 'saas.current_site');
        $site      = new Site;
        $site->setAttribute('id', 42);
        app()->instance($tenantKey, $site);

        $request = Request::create('/_longos/instant-translate/context', 'GET', [], [], [], [
            'REMOTE_ADDR'          => '127.0.0.1',
            'HTTP_CF_IPCOUNTRY'    => 'JP',
            'HTTP_ACCEPT_LANGUAGE' => 'fr-CA,fr;q=0.9',
        ]);
        $request->attributes->set(CloudflareIpRangeService::REQUEST_ATTRIBUTE, true);
        $controller = new ShopLocaleController;
        $response   = $controller(
            $request,
            new InstantTranslateSettings,
            new VerifiedVisitorCountry(new CloudflareIpRangeService),
            new LocaleResolver,
        );

        self::assertSame('japanese', $response->getData(true)['language']);
        self::assertSame('JP', $response->getData(true)['country']);
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));

        app()->forgetInstance($tenantKey);
    }
}
