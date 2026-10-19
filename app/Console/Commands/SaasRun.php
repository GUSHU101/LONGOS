<?php

namespace App\Console\Commands;

use App\Saas\Models\Site;
use App\Saas\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class SaasRun extends Command
{
    protected $signature = 'saas:run
        {tenant-command : 需要在每个站点中执行的 Artisan 命令名}
        {--site=* : 仅执行指定站点 code}
        {--option=* : 传递 key=value 参数}
        {--if-setting= : 仅在指定站点配置非空时执行，例 base.rate_api_key}
        {--continue-on-error : 单站失败后继续}';

    protected $description = '依次在所有已启用 SaaS 站点的数据库中执行命令';

    public function handle(TenantManager $tenants): int
    {
        $command = (string) $this->argument('tenant-command');
        if ($command === 'saas:run') {
            $this->components->error('saas:run 不能调用自身。');

            return self::FAILURE;
        }

        $parameters = [];
        foreach ((array) $this->option('option') as $option) {
            [$key, $value]                                               = array_pad(explode('=', (string) $option, 2), 2, true);
            $parameters[str_starts_with($key, '--') ? $key : "--{$key}"] = $value;
        }

        $query = Site::query()->with('domains')->where('status', Site::STATUS_ACTIVE)->orderBy('id');
        if ($codes = array_filter((array) $this->option('site'))) {
            $query->whereIn('code', $codes);
        }

        $failed = 0;
        foreach ($query->cursor() as $site) {
            try {
                $exit = $tenants->run($site, function () use ($command, $parameters): int {
                    $setting = trim((string) $this->option('if-setting'));
                    if ($setting !== '' && trim((string) system_setting($setting, '')) === '') {
                        return self::SUCCESS;
                    }

                    return Artisan::call($command, $parameters, $this->output);
                });
                if ($exit !== 0) {
                    throw new \RuntimeException("命令返回码 {$exit}");
                }
                $this->components->info("[{$site->code}] 执行完成");
            } catch (Throwable $exception) {
                $failed++;
                $this->components->error("[{$site->code}] {$exception->getMessage()}");
                if (! $this->option('continue-on-error')) {
                    break;
                }
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
