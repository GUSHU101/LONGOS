<?php

namespace App\Console\Commands;

use App\Mail\CloudflareMailDnsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncCloudflareMailDns extends Command
{
    protected $signature = 'mail:sync-cloudflare-dns
        {--if-configured : Exit successfully when automatic DNS or its token is not configured}
        {--json : Emit a machine-readable result}';

    protected $description = 'Safely publish LONGOS mail DNS and merge duplicate SPF policies through Cloudflare';

    public function handle(CloudflareMailDnsService $dns): int
    {
        if (! $dns->configured()) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'status'  => 'skipped',
                    'message' => 'Cloudflare mail DNS automation is not configured.',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } elseif (! $this->option('if-configured')) {
                $this->warn('Cloudflare 邮件 DNS 自动化未启用或未配置 API Token。');
            }

            return $this->option('if-configured') ? self::SUCCESS : self::FAILURE;
        }

        $lock = Cache::lock('longos:cloudflare-mail-dns-sync', 240);
        if (! $lock->get()) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'status'  => 'locked',
                    'message' => 'Another mail DNS synchronization is already running.',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            // A running synchronized attempt owns the same idempotent work.
            // Treat this invocation as successful so rolling deployments do
            // not block storefront startup or create duplicate DNS records.
            return self::SUCCESS;
        }

        try {
            $result = $dns->sync();
        } catch (Throwable $exception) {
            report($exception);
            if ($this->option('json')) {
                $this->line(json_encode([
                    'status'  => 'failed',
                    'message' => $exception->getMessage(),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        if ($this->option('json')) {
            $this->line(json_encode(['status' => 'ok'] + $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info(sprintf(
                'Cloudflare 邮件 DNS 同步完成：新建 %d，更新 %d，清理 %d，保留 %d。',
                count($result['created']),
                count($result['updated']),
                count($result['removed']),
                count($result['preserved']),
            ));
            foreach ($result['warnings'] as $warning) {
                $this->warn($warning);
            }
        }

        return self::SUCCESS;
    }
}
