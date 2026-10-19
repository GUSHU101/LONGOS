<?php

namespace App\Console\Commands;

use App\Saas\Models\Site;
use Illuminate\Console\Command;

class SaasSitesList extends Command
{
    protected $signature = 'saas:sites:list {--status= : 按状态筛选}';

    protected $description = '列出 SaaS 站点、数据库和已绑定域名';

    public function handle(): int
    {
        $query = Site::query()->with('domains')->orderBy('id');
        if ($status = trim((string) $this->option('status'))) {
            $query->where('status', $status);
        }

        $sites = $query->get();
        $this->table(
            ['ID', 'Code', '名称', '状态', '健康', '数据库', '域名'],
            $sites->map(static fn (Site $site): array => [
                $site->id,
                $site->code,
                $site->name,
                $site->status,
                $site->health_status ?? '-',
                $site->database_name,
                $site->domains->map(static fn ($domain): string => ($domain->is_primary ? '* ' : '') . $domain->host)->implode(PHP_EOL),
            ])->all()
        );

        return self::SUCCESS;
    }
}
