<?php

namespace App\Console\Commands;

use App\Saas\DomainConfigWriter;
use App\Saas\Models\Site;
use App\Saas\SiteAuditLogger;
use App\Saas\SiteHealthChecker;
use App\Saas\Support\DatabaseReadiness;
use App\Saas\Support\SensitiveValueRedactor;
use App\Saas\TenantManager;
use App\Services\BundledPluginSynchronizer;
use Beike\Repositories\PluginRepo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

class SaasUpgrade extends Command
{
    protected $signature = 'saas:upgrade
        {--continue-on-error : 收集所有租户错误，任一失败仍保持维护模式}
        {--no-maintenance : 不自动进入维护模式，仅适用于已实现滚动发布的基础设施}
        {--no-plugins : 跳过已安装插件迁移}
        {--no-domain-sync : 跳过域名配置同步}
        {--no-optimize : 跳过 Laravel 生产缓存重建}
        {--if-needed : 仅在数据库或依赖版本发生变化时执行}';

    protected $description = '以失败关闭方式升级 LONGOS SaaS 控制库、全部租户库、插件和域名配置';

    public function handle(
        TenantManager $tenants,
        DomainConfigWriter $domains,
        SiteHealthChecker $health,
        SiteAuditLogger $audit,
    ): int {
        $enteredMaintenance = false;
        $alreadyDown        = app()->isDownForMaintenance();
        $fingerprint        = $this->deploymentFingerprint();
        $marker             = storage_path('framework/longos-saas-upgrade.fingerprint');

        try {
            if (! config('saas.enabled')) {
                throw new RuntimeException('saas:upgrade 仅可在 SAAS_ENABLED=true 的已注册多站环境执行。');
            }
            if ($this->option('if-needed') && is_file($marker)
                                           && hash_equals($fingerprint, trim((string) file_get_contents($marker)))) {
                $this->components->info('LONGOS SaaS 数据库已是当前版本。');

                return self::SUCCESS;
            }
            if ($this->option('if-needed')) {
                // Only clear persistent production caches after the release
                // fingerprint proves that work is pending. Clearing before
                // the comparison would leave every ordinary container restart
                // running without optimized config/routes.
                $this->runArtisan('optimize:clear', [], '无法清理上一版本生产缓存');
            }
            if (! $this->option('no-maintenance') && ! $alreadyDown) {
                $this->runArtisan('down', ['--retry' => 60], '无法进入维护模式');
                $enteredMaintenance = true;
                $this->components->info('已进入维护模式。');
            }

            $domains->synchronized(function () use ($tenants, $domains, $health, $audit): void {
                $this->migrateControlPlane();
                $this->assertNoProvisioningInProgress();

                $query = Site::query()
                    ->with('domains')
                    ->whereIn('status', [Site::STATUS_ACTIVE, Site::STATUS_SUSPENDED])
                    ->orderBy('id');

                $sites = $query->get();
                if ($sites->isEmpty()) {
                    throw new RuntimeException('没有可升级的 active/suspended 站点。');
                }

                $failed = [];
                foreach ($sites as $site) {
                    try {
                        $tenants->run($site, function (): void {
                            $this->runArtisan('migrate', [
                                '--database'       => 'tenant',
                                '--force'          => true,
                                '--no-interaction' => true,
                            ], '租户核心迁移失败');
                            DatabaseReadiness::assertMigrated('tenant');

                            if (! $this->option('no-plugins')) {
                                $this->migrateInstalledPlugins();
                            }
                        });

                        $result = $health->check($site->refresh()->load('domains'), false);
                        if (! ($result['database']['ok'] ?? false)) {
                            throw new RuntimeException('迁移后数据库健康检查失败。');
                        }
                        $audit->record($site, 'deployment.upgrade', 'success');
                        $this->components->info("[{$site->code}] 核心、插件和健康检查完成。");
                    } catch (Throwable $exception) {
                        $message  = SensitiveValueRedactor::redact($exception->getMessage());
                        $failed[] = "{$site->code}: {$message}";
                        $audit->record($site, 'deployment.upgrade', 'failed', $message);
                        $this->components->error("[{$site->code}] {$message}");
                        if (! $this->option('continue-on-error')) {
                            break;
                        }
                    }
                }

                if ($failed !== []) {
                    throw new RuntimeException('租户升级失败：' . implode(' | ', $failed));
                }

                if (! $this->option('no-domain-sync')) {
                    $result = $domains->sync();
                    $this->components->info("已同步 {$result['hosts']} 个上线域名。");
                }
            });

            if (! $this->option('no-optimize')) {
                $this->runArtisan('optimize:clear', [], '无法清理旧缓存');
                $this->runArtisan('optimize', [], '无法重建生产缓存');
            }
            $this->runArtisan('queue:restart', [], '无法通知队列 Worker 重载');

            if ($enteredMaintenance) {
                $this->runArtisan('up', [], '升级成功但无法退出维护模式');
                $enteredMaintenance = false;
            }

            if ($this->option('if-needed')) {
                if (! is_dir(dirname($marker)) && ! @mkdir(dirname($marker), 0775, true) && ! is_dir(dirname($marker))) {
                    throw new RuntimeException('无法创建 SaaS 升级状态目录。');
                }
                if (file_put_contents($marker, $fingerprint . PHP_EOL, LOCK_EX) === false) {
                    throw new RuntimeException('无法写入 SaaS 升级状态。');
                }
            }

            $this->components->info('LONGOS SaaS 升级完成。');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error(SensitiveValueRedactor::redact($exception->getMessage()));
            if ($enteredMaintenance) {
                $this->components->warn('升级失败，已保持维护模式。修复后重新执行 saas:upgrade；不要在迁移未完成时强制上线。');
            }

            return self::FAILURE;
        }
    }

    private function deploymentFingerprint(): string
    {
        $files = array_merge(
            glob(database_path('migrations/*.php')) ?: [],
            glob(database_path('seeders/*.php')) ?: [],
            glob(base_path('plugins/*/Migrations/*.php')) ?: [],
            glob(config_path('*.php')) ?: [],
            glob(base_path('routes/*.php')) ?: [],
            glob(app_path('Saas/*.php')) ?: [],
            glob(app_path('Services/*.php')) ?: [],
            glob(app_path('Providers/*.php')) ?: [],
            glob(app_path('Console/Commands/*.php')) ?: [],
            glob(base_path('beike/*/Routes/*.php')) ?: [],
            glob(base_path('plugins/*/Routes/*.php')) ?: [],
            glob(base_path('plugins/*/Bootstrap.php')) ?: [],
            glob(base_path('plugins/*/config.json')) ?: [],
            [
                base_path('bootstrap/app.php'),
                base_path('composer.lock'),
            ]
        );
        $files = array_values(array_unique(array_filter($files, 'is_file')));
        sort($files, SORT_STRING);

        $hash = hash_init('sha256');
        foreach ($files as $file) {
            hash_update($hash, str_replace(base_path(), '', $file));
            hash_update_file($hash, $file);
        }

        return hash_final($hash);
    }

    private function migrateControlPlane(): void
    {
        $files = glob(database_path('migrations/*saas*.php')) ?: [];
        sort($files, SORT_STRING);
        if ($files === []) {
            throw new RuntimeException('未找到 SaaS 控制面迁移文件。');
        }

        foreach ($files as $file) {
            $relative = ltrim(str_replace('\\', '/', str_replace(base_path(), '', $file)), '/');
            $this->runArtisan('migrate', [
                '--database'       => 'saas',
                '--path'           => $relative,
                '--force'          => true,
                '--no-interaction' => true,
            ], '控制面迁移失败：' . basename($file));
        }
        $this->components->info('控制面迁移完成。');
    }

    private function assertNoProvisioningInProgress(): void
    {
        $codes = Site::query()
            ->whereIn('status', [Site::STATUS_PROVISIONING, Site::STATUS_READY, Site::STATUS_ACTIVATING])
            ->pluck('code')
            ->all();
        if ($codes !== []) {
            throw new RuntimeException('存在尚未结束的建站任务，请先完成或恢复：' . implode(', ', $codes));
        }
    }

    private function migrateInstalledPlugins(): void
    {
        $bundled = app(BundledPluginSynchronizer::class);
        $bundled->synchronize();

        $manager = app('plugin');
        foreach ($manager->getPlugins() as $plugin) {
            if ($plugin->getInstalled()) {
                PluginRepo::migrateDatabase($plugin);
            }
        }

        $bundled->assertReady();
    }

    /** @param array<string, mixed> $parameters */
    private function runArtisan(string $command, array $parameters, string $failure): void
    {
        $exitCode = Artisan::call($command, $parameters, $this->output);
        if ($exitCode !== self::SUCCESS) {
            $output = trim(Artisan::output());

            throw new RuntimeException($failure . ($output !== '' ? '：' . $output : ''));
        }
    }
}
