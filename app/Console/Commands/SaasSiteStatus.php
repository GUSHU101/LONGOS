<?php

namespace App\Console\Commands;

use App\Saas\Models\Site;
use App\Saas\SiteLifecycleService;
use Illuminate\Console\Command;
use Throwable;

class SaasSiteStatus extends Command
{
    protected $signature = 'saas:sites:status
        {site : 站点 ID 或 code}
        {status : active 或 suspended}
        {--force : 跳过上线前数据库检查，或强制暂停控制站}';

    protected $description = '上线或暂停 SaaS 站点并同步域名配置';

    public function handle(SiteLifecycleService $lifecycle): int
    {
        try {
            $identifier = (string) $this->argument('site');
            $site       = Site::query()
                ->with('domains')
                ->where(static fn ($query) => $query->where('code', $identifier)->when(ctype_digit($identifier), static fn ($query) => $query->orWhere('id', (int) $identifier)))
                ->firstOrFail();
            $site = $lifecycle->setStatus($site, (string) $this->argument('status'), (bool) $this->option('force'));
            $this->components->info("[{$site->code}] 状态已更新为 {$site->status}。");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
