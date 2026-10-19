<?php

namespace App\Console\Commands;

use App\Saas\Models\Site;
use App\Saas\SiteHealthChecker;
use App\Saas\Support\SensitiveValueRedactor;
use Illuminate\Console\Command;
use Throwable;

class SaasHealth extends Command
{
    protected $signature = 'saas:health
        {--site=* : 仅检查指定站点 code}
        {--active-only : 仅检查已上线站点}
        {--no-dns : 跳过 DNS 查询}';

    protected $description = '检查各 SaaS 站点数据库、核心表和域名解析';

    public function handle(SiteHealthChecker $health): int
    {
        $query = Site::query()->with('domains')->orderBy('id');
        if ($this->option('active-only')) {
            $query->where('status', Site::STATUS_ACTIVE);
        }
        if ($codes = array_filter((array) $this->option('site'))) {
            $query->whereIn('code', $codes);
        }

        $rows   = [];
        $failed = false;

        try {
            foreach ($query->cursor() as $site) {
                try {
                    $result = $health->check($site, ! (bool) $this->option('no-dns'));
                    $rows[] = [$site->code, $result['status'], $result['database']['latency_ms'] ?? '-', count($result['domains'])];
                    $failed = $failed || $result['status'] !== 'healthy';
                } catch (Throwable $exception) {
                    $rows[] = [$site->code, 'error: ' . SensitiveValueRedactor::redact($exception->getMessage()), '-', '-'];
                    $failed = true;
                }
            }
        } catch (Throwable $exception) {
            $this->components->error(
                '无法读取 SaaS 控制库，请检查数据库连接和控制库迁移状态。'
                . PHP_EOL . SensitiveValueRedactor::redact($exception->getMessage())
            );

            return self::FAILURE;
        }

        $this->table(['Code', '健康状态', 'DB ms', '域名数'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
