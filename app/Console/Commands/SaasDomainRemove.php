<?php

namespace App\Console\Commands;

use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use App\Saas\SiteLifecycleService;
use App\Saas\Support\DomainName;
use Illuminate\Console\Command;
use Throwable;

class SaasDomainRemove extends Command
{
    protected $signature = 'saas:domains:remove
        {site : 站点 ID 或 code}
        {domain : 需解绑的域名}
        {--force : 允许删除最后一个平台恢复域名}';

    protected $description = '解绑 SaaS 站点域名并同步 Web 服务配置';

    public function handle(SiteLifecycleService $lifecycle): int
    {
        try {
            $identifier = (string) $this->argument('site');
            $site       = Site::query()
                ->where(static fn ($query) => $query->where('code', $identifier)->when(ctype_digit($identifier), static fn ($query) => $query->orWhere('id', (int) $identifier)))
                ->firstOrFail();
            $host   = DomainName::normalize((string) $this->argument('domain'));
            $domain = Domain::query()->where('site_id', $site->id)->where('host', $host)->firstOrFail();
            $host   = $domain->host;
            $lifecycle->removeDomain($site, $domain, (bool) $this->option('force'));
            $this->components->info("已解绑 {$host}。");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
