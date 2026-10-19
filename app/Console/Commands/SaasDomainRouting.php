<?php

namespace App\Console\Commands;

use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use App\Saas\SiteLifecycleService;
use App\Saas\Support\DomainName;
use Illuminate\Console\Command;
use Throwable;

class SaasDomainRouting extends Command
{
    protected $signature = 'saas:domains:routing
        {site : 站点 ID 或 code}
        {domain : 需设置的非主域名}
        {mode : redirect 或 alias}';

    protected $description = '设置 SaaS 非主域名跳转到主域名或作为别名直接服务';

    public function handle(SiteLifecycleService $lifecycle): int
    {
        try {
            $identifier = (string) $this->argument('site');
            $site       = Site::query()
                ->where(static fn ($query) => $query->where('code', $identifier)->when(
                    ctype_digit($identifier),
                    static fn ($query) => $query->orWhere('id', (int) $identifier),
                ))
                ->firstOrFail();
            $host    = DomainName::normalize((string) $this->argument('domain'));
            $domain  = Domain::query()->where('site_id', $site->id)->where('host', $host)->firstOrFail();
            $updated = $lifecycle->setDomainRouting($site, $domain, strtolower((string) $this->argument('mode')));
            $this->components->info("{$updated->host} 已设为 {$updated->routing_type} 域名。");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
