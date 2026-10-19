<?php

namespace App\Console\Commands;

use App\Saas\DomainVerificationService;
use App\Saas\Models\Site;
use App\Saas\SiteLifecycleService;
use Illuminate\Console\Command;
use Throwable;

class SaasDomainAdd extends Command
{
    protected $signature = 'saas:domains:add {site : 站点 ID 或 code} {domain} {--primary : 同时设为主域名}';

    protected $description = '为 SaaS 站点绑定平台子域名，或为自有域名生成验证记录';

    public function handle(SiteLifecycleService $lifecycle, DomainVerificationService $verification): int
    {
        try {
            $site  = $this->findSite((string) $this->argument('site'));
            $value = (string) $this->argument('domain');
            if ($verification->isPlatformManagedHost($value)) {
                $domain = $lifecycle->addDomain($site, $value, (bool) $this->option('primary'));
                $this->components->info("已绑定平台子域名 {$domain->scheme}://{$domain->host}。");

                return self::SUCCESS;
            }

            $domain       = $verification->request($site, $value);
            $instructions = $verification->instructions($domain);
            $this->components->info("已为 {$domain->host} 创建待验证记录，当前尚未接收流量。");
            $this->table(['类型', '主机记录', '记录值', '过期时间'], [[
                $instructions['type'],
                $instructions['name'],
                $instructions['value'],
                $instructions['expires_at'],
            ]]);
            if ((bool) $this->option('primary')) {
                $this->components->warn('自有域名必须验证成功后才能设为主域名。');
            }
            $this->components->info('完成 TXT 和流量 DNS 解析后，运行 php artisan saas:domains:verify-pending。');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function findSite(string $identifier): Site
    {
        return Site::query()
            ->where(static fn ($query) => $query->where('code', $identifier)->when(ctype_digit($identifier), static fn ($query) => $query->orWhere('id', (int) $identifier)))
            ->firstOrFail();
    }
}
