<?php

/**
 * PluginServiceProvider.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-07-20 14:42:10
 * @modified   2022-07-20 14:42:10
 */

namespace Beike\Shop\Providers;

use App\Saas\Support\SensitiveValueRedactor;
use Beike\Models\AdminUser;
use Beike\Plugin\Manager;
use Beike\Plugin\RuntimeGuard;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class PluginServiceProvider extends ServiceProvider
{
    private string $pluginBasePath = '';

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('plugin', function () {
            return new Manager;
        });
    }

    /**
     * Bootstrap Plugin Service Provider
     */
    public function boot()
    {
        if (! installed()) {
            return;
        }

        // 如果是 artisan migrate 命令行操作，则跳过
        if (app()->runningInConsole()) {
            $argv            = $_SERVER['argv'] ?? [];
            $migrateCommands = ['migrate', 'migrate:fresh', 'migrate:refresh', 'migrate:rollback'];
            if (isset($argv[1]) && in_array($argv[1], $migrateCommands)) {
                return;
            }
        }

        $manager              = app('plugin');
        $this->pluginBasePath = base_path('plugins');

        $allPlugins = $manager->getPlugins();
        foreach ($allPlugins as $plugin) {
            $pluginCode  = $plugin->getDirname();
            $runtimeCode = (string) $plugin->code;
            $this->attempt($manager, $pluginCode, '迁移注册', fn () => $this->loadMigrations($pluginCode));
            $this->attempt($manager, $pluginCode, '视图注册', fn () => $this->loadViews($pluginCode));
            $this->attempt($manager, $pluginCode, '语言注册', fn () => $this->loadTranslations($pluginCode));
            // Route caches are built once for the whole application, not once
            // per tenant. Register every packaged plugin route and let the
            // runtime middleware enforce the current tenant's plugin status.
            $this->attempt($manager, $pluginCode, '路由注册', fn () => $this->registerRoutes($pluginCode, $runtimeCode));
            // Register packaged extension points once. RuntimeGuard checks the
            // active tenant at execution time, so a long-lived process cannot
            // leak the first tenant's plugin state into later tenants.
            $this->attempt($manager, $pluginCode, '扩展点注册', fn () => $this->bootPlugin($plugin));
            $this->attempt($manager, $pluginCode, '中间件注册', fn () => $this->registerMiddleware($pluginCode, $runtimeCode));
            $this->attempt($manager, $pluginCode, '命令注册', fn () => $this->registerCommands($plugin));
        }
    }

    private function attempt(Manager $manager, string $pluginCode, string $stage, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            // Isolate a damaged optional package. The problem remains visible
            // in plugin diagnostics and logs, while unrelated admin and shop
            // routes continue to boot normally.
            $manager->recordPackageIssue(
                $pluginCode,
                $stage,
                SensitiveValueRedactor::redact($exception->getMessage()),
            );
        }
    }

    /**
     * 调用插件 Bootstrap::boot()
     *
     * @param $plugin
     */
    private function bootPlugin($plugin)
    {
        $filePath   = $plugin->getBootFile();
        $pluginCode = $plugin->getDirname();
        if (file_exists($filePath)) {
            $className = "Plugin\\{$pluginCode}\\Bootstrap";
            if (method_exists($className, 'boot')) {
                RuntimeGuard::whileRegistering(
                    (string) $plugin->code,
                    static fn () => (new $className)->boot(),
                );
            }
        }
    }

    /**
     * 加载插件数据库迁移脚本
     *
     * @param $pluginCode
     */
    private function loadMigrations($pluginCode)
    {
        $migrationPath = "{$this->pluginBasePath}/{$pluginCode}/Migrations";
        if (is_dir($migrationPath)) {
            $this->loadMigrationsFrom("{$migrationPath}");
        }
    }

    /**
     * Load and register for admin and shop
     *
     * @param $pluginCode
     */
    private function registerRoutes($pluginCode, string $runtimeCode)
    {
        $this->registerAdminRoutes($pluginCode, $runtimeCode);
        $this->registerShopRoutes($pluginCode, $runtimeCode);
        $pluginBasePath = $this->pluginBasePath;
        $routePath      = "{$pluginBasePath}/{$pluginCode}/Routes/";

        if (is_dir($routePath)) {
            // 获取$adminRoutePath目录下除了admin.php和shop.php之外的所有扩展名为".php"的文件
            $excludeFiles = ['admin.php', 'shop.php'];
            $files        = glob($routePath . '*.php');
            foreach ($files as $file) {
                $fileName = basename($file);
                if (in_array($fileName, $excludeFiles)) {
                    continue;
                }
                Route::middleware("plugin.enabled:{$runtimeCode}")
                    ->group(function () use ($file) {
                        $this->loadRoutesFrom($file);
                    });
            }
        }
    }

    /**
     * Register admin routes
     *
     * @param $pluginCode
     */
    private function registerAdminRoutes($pluginCode, string $runtimeCode)
    {
        $pluginBasePath = $this->pluginBasePath;
        $adminRoutePath = "{$pluginBasePath}/{$pluginCode}/Routes/admin.php";
        if (file_exists($adminRoutePath)) {
            $adminName = admin_name();
            Route::prefix($adminName)
                ->name("{$adminName}.")
                ->middleware([
                    'admin',
                    'admin_auth:' . AdminUser::AUTH_GUARD,
                    "plugin.enabled:{$runtimeCode}",
                ])
                ->group(function () use ($adminRoutePath) {
                    $this->loadRoutesFrom($adminRoutePath);
                });
        }
    }

    /**
     * Register shop routes
     *
     * @param $pluginCode
     */
    private function registerShopRoutes($pluginCode, string $runtimeCode)
    {
        $pluginBasePath = $this->pluginBasePath;
        $shopRoutePath  = "{$pluginBasePath}/{$pluginCode}/Routes/shop.php";
        if (file_exists($shopRoutePath)) {
            Route::name('shop.')
                ->middleware(['shop', "plugin.enabled:{$runtimeCode}"])
                ->group(function () use ($shopRoutePath) {
                    $this->loadRoutesFrom($shopRoutePath);
                });
        }
    }

    /**
     * 加载多语言
     */
    private function loadTranslations($pluginCode)
    {
        $pluginBasePath = $this->pluginBasePath;
        $this->loadTranslationsFrom("{$pluginBasePath}/{$pluginCode}/Lang", $pluginCode);
    }

    /**
     * 加载模板目录
     *
     * @param $pluginCode
     */
    private function loadViews($pluginCode)
    {
        $pluginViewPath = "{$this->pluginBasePath}/{$pluginCode}/Views";

        if (is_dir($pluginViewPath)) {
            $this->loadViewsFrom($pluginViewPath, $pluginCode);
        }
    }

    /**
     * 注册插件定义的中间件
     */
    private function registerMiddleware($pluginCode, string $runtimeCode)
    {
        $pluginBasePath = $this->pluginBasePath;
        $middlewarePath = "{$pluginBasePath}/{$pluginCode}/Middleware";

        $router           = $this->app['router'];
        $shopMiddlewares  = $this->loadMiddlewares("$middlewarePath/Shop");
        $adminMiddlewares = $this->loadMiddlewares("$middlewarePath/Admin");

        if ($shopMiddlewares) {
            foreach ($shopMiddlewares as $shopMiddleware) {
                $router->pushMiddlewareToGroup('shop', "plugin.runtime:{$runtimeCode},{$shopMiddleware}");
            }
        }

        if ($adminMiddlewares) {
            foreach ($adminMiddlewares as $adminMiddleware) {
                $router->pushMiddlewareToGroup('admin', "plugin.runtime:{$runtimeCode},{$adminMiddleware}");
            }
        }
    }

    /**
     * 获取插件中间件
     * @param $path
     * @return array
     */
    private function loadMiddlewares($path): array
    {
        if (! file_exists($path)) {
            return [];
        }

        $middlewares = [];
        $files       = glob("$path/*");
        foreach ($files as $file) {
            $baseName      = basename($file, '.php');
            $namespacePath = 'Plugin' . dirname(str_replace($this->pluginBasePath, '', $file)) . '/';
            $className     = str_replace('/', '\\', $namespacePath . $baseName);

            if (class_exists($className)) {
                $middlewares[] = $className;
            }
        }

        return $middlewares;
    }

    private function registerCommands(mixed $plugin): void
    {
        if (is_dir($plugin->getPath() . '/Console/')) {
            $builders   = glob($plugin->getPath() . '/Console/' . '*');
            $pluginCode = $plugin->getDirname();
            foreach ($builders as $builder) {
                $builderName   = basename($builder, '.php');
                $componentName = Str::studly($builderName);
                $classBaseName = "Plugin\\{$pluginCode}\\Console\\{$componentName}";

                if (! class_exists($classBaseName)) {
                    Log::warning('Packaged plugin command class does not exist.', [
                        'plugin' => (string) $plugin->code,
                        'class'  => $classBaseName,
                    ]);

                    continue;
                }

                $this->commands($classBaseName);
            }
        }
    }
}
