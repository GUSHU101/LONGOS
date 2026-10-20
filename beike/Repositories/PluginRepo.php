<?php

/**
 * PluginRepo.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-07-01 14:23:41
 * @modified   2022-07-01 14:23:41
 */

namespace Beike\Repositories;

use App\Saas\Support\SensitiveValueRedactor;
use App\Services\BundledPluginSynchronizer;
use App\Services\PaymentPluginReadiness;
use Beike\Models\Plugin;
use Beike\Plugin\Plugin as BPlugin;
use Beike\Shop\Services\TotalServices\ShippingService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PluginRepo
{
    public static $installedPlugins;

    public static function getTypes(): array
    {
        $types = [];
        foreach (BPlugin::TYPES as $item) {
            $types[] = [
                'value' => $item,
                'label' => trans("admin/plugin.{$item}"),
            ];
        }

        return $types;
    }

    /**
     * 安装插件到系统: 插入数据
     * @param  BPlugin    $bPlugin
     * @throws \Exception
     */
    public static function installPlugin(BPlugin $bPlugin)
    {
        self::publishPluginFiles($bPlugin);
        self::publishThemeFiles($bPlugin);
        self::publishLangFiles($bPlugin);
        self::migrateDatabase($bPlugin);
        if ($bPlugin->type != 'theme') {
            self::runSeeder($bPlugin);
        }
        $type   = $bPlugin->type;
        $code   = $bPlugin->code;
        $plugin = Plugin::query()
            ->where('type', $type)
            ->where('code', $code)
            ->first();
        if (empty($plugin)) {
            Plugin::query()->create([
                'type' => $type,
                'code' => $code,
            ]);
        }
        self::forgetPluginRuntimeState();
    }

    /**
     * 发布静态资源到 public
     * @param BPlugin $bPlugin
     */
    public static function publishStaticFiles(BPlugin $bPlugin)
    {
        $code       = $bPlugin->code;
        $path       = $bPlugin->getPath();
        $staticPath = $path . '/Static';
        if (is_dir($staticPath)) {
            File::copyDirectory($staticPath, public_path('plugin/' . $code));
        }
    }

    /**
     * 发布插件文件
     *
     * @param $bPlugin
     */
    public static function publishPluginFiles($bPlugin): void
    {
        if ($bPlugin->getType() == 'theme') {
            return;
        }

        $path = $bPlugin->getPath();
        if (is_dir($path . '/Static/public')) {
            $destination = public_path('plugin/' . $bPlugin->code);

            if (! is_dir($destination)) {
                File::makeDirectory($destination, 0755, true);
            }

            File::copyDirectory($path . '/Static/public', $destination);
        }
    }

    /**
     * Publish theme files, include public and blade files.
     *
     * @param $bPlugin
     */
    public static function publishThemeFiles($bPlugin): void
    {
        if ($bPlugin->getType() != 'theme') {
            return;
        }

        $publicPath = $bPlugin->getPath() . '/Static/public';
        if (is_dir($publicPath)) {
            File::copyDirectory($publicPath, public_path('/'));
        }

        $themePath = $bPlugin->getPath() . '/Themes';
        if (is_dir($themePath)) {
            File::copyDirectory($themePath, base_path('themes'));
        }
    }

    /**
     * Publish lang files
     *
     * @param $bPlugin
     * @return void
     */
    public static function publishLangFiles($bPlugin): void
    {
        if ($bPlugin->getType() != 'language') {
            return;
        }

        $langPath = $bPlugin->getPath() . '/Lang';
        if (is_dir($langPath)) {
            File::copyDirectory($langPath, base_path('lang'));
        }
    }

    /**
     * Run plugin seeder
     *
     * @param             $bPlugin
     * @throws \Exception
     */
    public static function runSeeder($bPlugin)
    {
        // 在执行 seeder 之前，尝试加载插件的命名空间注册文件（如果存在）
        // 优先加载 AutoloadNamespace.php，它只负责命名空间注册，无其他业务逻辑依赖
        // 这样避免了加载 Bootstrap.php 可能导致的依赖问题
        $autoloadFile = $bPlugin->getPath() . '/AutoloadNamespace.php';
        if (file_exists($autoloadFile)) {
            require_once $autoloadFile;
        }

        $seederPath = $bPlugin->getPath() . '/Seeders/';
        if (is_dir($seederPath)) {

            $seederFiles = glob($seederPath . '*');
            foreach ($seederFiles as $seederFile) {
                $seederName = basename($seederFile, '.php');
                $className  = "\\Plugin\\{$bPlugin->getDirname()}\\Seeders\\{$seederName}";
                if (class_exists($className)) {
                    $seeder = new $className;
                    $seeder->run();
                }
            }
        }
    }

    /**
     * 数据库迁移
     */
    public static function migrateDatabase(BPlugin $bPlugin)
    {
        $migrationPath = "{$bPlugin->getPath()}/Migrations";
        if (is_dir($migrationPath)) {
            $files = glob($migrationPath . '/*.php');
            asort($files);

            foreach ($files as $file) {
                // Laravel's migrator accepts project-relative paths. Normalize
                // separators as well so plugin installation works from the
                // Windows admin panel just as it does on Linux servers.
                $file     = ltrim(str_replace('\\', '/', str_replace(base_path(), '', $file)), '/');
                $exitCode = Artisan::call('migrate', [
                    '--force' => true,
                    '--step'  => 1,
                    '--path'  => $file,
                ]);

                if ($exitCode !== 0) {
                    throw new RuntimeException(sprintf(
                        'Plugin migration failed: %s. %s',
                        basename($file),
                        trim(Artisan::output())
                    ));
                }
            }
        }
    }

    /**
     * 从系统卸载插件: 删除数据
     * @param BPlugin $bPlugin
     */
    public static function uninstallPlugin(BPlugin $bPlugin)
    {
        if (BundledPluginSynchronizer::isBundled((string) $bPlugin->code)) {
            throw new RuntimeException('该插件是 LONGOS 发布包的组成部分，可在设置中停用，但不能卸载。');
        }

        self::rollbackDatabase($bPlugin);
        $type = $bPlugin->type;
        $code = $bPlugin->code;
        Plugin::query()
            ->where('type', $type)
            ->where('code', $code)
            ->delete();
        self::forgetPluginRuntimeState();
    }

    /**
     * 从 public 删除静态资源
     * @param BPlugin $bPlugin
     */
    public static function removeStaticFiles(BPlugin $bPlugin)
    {
        $code       = $bPlugin->code;
        $path       = $bPlugin->getPath();
        $staticPath = $path . '/static';
        if (is_dir($staticPath)) {
            File::deleteDirectory(public_path('plugin/' . $code));
        }
    }

    /**
     * 数据库回滚
     */
    public static function rollbackDatabase(BPlugin $bPlugin): void
    {
        $migrationPath = "{$bPlugin->getPath()}/Migrations";
        if (is_dir($migrationPath)) {
            $files = glob($migrationPath . '/*.php');
            arsort($files);

            foreach ($files as $file) {
                try {
                    // 获取迁移文件名（不含扩展名）
                    $migrationName = basename($file, '.php');

                    // 检查迁移记录是否存在
                    $migrationRecord = DB::table('migrations')->where('migration', $migrationName)->first();

                    if (! $migrationRecord) {
                        Log::info("Plugin migration not found in database: {$migrationName}");

                        continue;
                    }

                    // 直接包含迁移文件
                    $migration = require $file;

                    // 如果返回的是 1，说明是命名类迁移（旧版），需要根据文件名手动实例化类
                    if ($migration === 1) {
                        $class = Str::studly(implode('_', array_slice(explode('_', $migrationName), 4)));
                        if (class_exists($class)) {
                            $migration = new $class;
                        }
                    }

                    if (! is_object($migration) || ! method_exists($migration, 'down')) {
                        throw new RuntimeException("Plugin migration is invalid or has no down method: {$migrationName}");
                    }

                    // 执行down方法
                    $migration->down();

                    // 从migrations表中删除记录
                    DB::table('migrations')->where('migration', $migrationName)->delete();

                    Log::info("Plugin migration rolled back successfully: {$migrationName}");

                } catch (Throwable $e) {
                    // Deleting the plugin row after a failed rollback creates
                    // a deceptive half-uninstalled state. Stop immediately;
                    // the package remains registered and can be repaired or
                    // retried without losing its administration entry.
                    $safeMessage = SensitiveValueRedactor::redact($e->getMessage());
                    Log::error('Plugin migration rollback failed.', [
                        'plugin'    => (string) $bPlugin->code,
                        'migration' => basename($file),
                        'exception' => $e::class,
                        'message'   => $safeMessage,
                    ]);

                    throw new RuntimeException(
                        '插件卸载已停止：数据库回滚失败（' . basename($file) . '）。',
                        0,
                        $e,
                    );
                }
            }
        }
    }

    /**
     * 判断插件是否安装
     *
     * @param $code
     * @return bool
     */
    public static function installed($code): bool
    {
        $plugins = self::getPluginsByCode();

        return $plugins->has($code);
    }

    /**
     * 判断插件是否安装
     *
     * @param $code
     * @return bool
     */
    public static function enabled($code): bool
    {
        $code = Str::camel($code);

        return SettingRepo::getPluginStatus($code);
    }

    /**
     * 获取所有已安装插件列表
     *
     * @return Plugin[]|Collection
     */
    public static function allPlugins()
    {
        if (self::$installedPlugins !== null) {
            return self::$installedPlugins;
        }

        return self::$installedPlugins = Plugin::all();
    }

    /**
     * 获取所有已安装插件
     * @return Plugin[]|Collection
     */
    public static function getPluginsByCode(): Collection|array
    {
        $allPlugins = self::allPlugins();

        return $allPlugins->keyBy('code');
    }

    /**
     * 获取所有配送方式
     */
    public static function getShippingMethods(): Collection
    {
        $allPlugins = self::allPlugins();

        return $allPlugins->where('type', 'shipping')->filter(function ($item) {
            try {
                $plugin = plugin($item->code);
                if ($plugin) {
                    $item->plugin = $plugin;
                }

                return $plugin && $plugin->getEnabled();
            } catch (Throwable $e) {
                Log::warning('Shipping plugin skipped during discovery.', [
                    'plugin'    => (string) $item->code,
                    'exception' => $e::class,
                    'message'   => SensitiveValueRedactor::redact($e->getMessage()),
                ]);

                return false;
            }
        });
    }

    /**
     * 获取所有支付方式
     */
    public static function getPaymentMethods(): SupportCollection
    {
        $allPlugins = self::allPlugins();

        $result = $allPlugins->where('type', 'payment')->filter(function ($item) {
            try {
                $plugin = plugin($item->code);
                if ($plugin) {
                    $item->plugin = $plugin;
                }

                return $plugin
                    && $plugin->getEnabled()
                    && PaymentPluginReadiness::isReady((string) $item->code);
            } catch (Throwable $e) {
                Log::warning('Payment plugin skipped during discovery.', [
                    'plugin'    => (string) $item->code,
                    'exception' => $e::class,
                    'message'   => SensitiveValueRedactor::redact($e->getMessage()),
                ]);

                return false;
            }
        });

        $result = hook_filter('repo.plugin.payment_methods', $result);

        $result = $result instanceof SupportCollection ? $result : collect($result);

        return $result->filter(fn ($item) => is_object($item) && isset($item->code))->values();
    }

    public static function getTranslators()
    {
        $allPlugins = self::allPlugins();

        return $allPlugins->where('type', 'translator')->filter(function ($item) {
            $plugin = plugin($item->code);
            if ($plugin) {
                $item->plugin = $plugin;
            }

            return $plugin && $plugin->getEnabled();
        });
    }

    /**
     * Get all enabled themes
     */
    public static function getEnabledThemes(): Collection
    {
        $allPlugins = self::allPlugins();

        return $allPlugins->where('type', 'theme')->filter(function ($item) {
            $plugin = plugin($item->code);
            if ($plugin) {
                $item->plugin = $plugin;
            }

            return $plugin && $plugin->getEnabled();
        });
    }

    /**
     * 检测对应配送方式是否可用
     *
     * @param $code
     * @return bool
     */
    public static function shippingEnabled($code): bool
    {
        $code            = ShippingService::parseShippingPluginCode($code);
        $shippingMethods = self::getShippingMethods();

        return $shippingMethods->where('code', $code)->count() > 0;
    }

    /**
     * 检测对应支付方式是否可用
     *
     * @param $code
     * @return bool
     */
    public static function paymentEnabled($code): bool
    {
        $paymentMethods = self::getPaymentMethods();

        return $paymentMethods->where('code', $code)->count() > 0;
    }

    public static function resetRuntimeCache(): void
    {
        self::$installedPlugins = null;
    }

    private static function forgetPluginRuntimeState(): void
    {
        self::resetRuntimeCache();

        if (app()->bound('plugin')) {
            app('plugin')->forgetRuntimeCache();
        }
    }
}
