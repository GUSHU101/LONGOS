<?php

namespace App\Console\Commands;

use App\Saas\DomainConfigWriter;
use Illuminate\Console\Command;
use Throwable;

class SaasDomainsSync extends Command
{
    protected $signature = 'saas:domains:sync';

    protected $description = '重建 Coolify Caddy 域名授权快照和站点映射清单';

    public function handle(DomainConfigWriter $writer): int
    {
        try {
            $result = $writer->sync();
            $this->components->info("已同步 {$result['hosts']} 个域名。");
            $this->line('配置：' . $result['config_path']);
            $this->line('清单：' . $result['manifest_path']);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
