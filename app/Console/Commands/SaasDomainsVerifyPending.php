<?php

namespace App\Console\Commands;

use App\Saas\DomainVerificationService;
use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use Illuminate\Console\Command;
use Throwable;

class SaasDomainsVerifyPending extends Command
{
    protected $signature = 'saas:domains:verify-pending
        {--limit= : 本次最多检查的域名数，默认读取 SAAS_DOMAIN_VERIFICATION_BATCH}';

    protected $description = '检查租户自有域名的所有权、流量 DNS、Web 入口和 HTTPS 并自动激活';

    public function handle(DomainVerificationService $verification): int
    {
        if (! config('saas.enabled') || ! config('saas.custom_domains.enabled', true)) {
            $this->components->warn('SaaS 自定义域名功能未启用。');

            return self::SUCCESS;
        }

        $limit       = (int) ($this->option('limit') ?: config('saas.custom_domains.verification_batch', 100));
        $limit       = min(max($limit, 1), 1000);
        $retryBefore = now()->subMinutes(max(1, (int) config('saas.custom_domains.verification_retry_minutes', 15)));

        Domain::query()
            ->where('origin', Domain::ORIGIN_TENANT)
            ->where('status', Domain::STATUS_PENDING)
            ->whereNotNull('verification_expires_at')
            ->where('verification_expires_at', '<=', now())
            ->update([
                'status'             => Domain::STATUS_FAILED,
                'verification_error' => '域名验证记录已过期，请重新生成。',
            ]);

        $domains = Domain::query()
            ->with('site')
            ->where('origin', Domain::ORIGIN_TENANT)
            ->where('status', Domain::STATUS_PENDING)
            ->where(static function ($query): void {
                $query->where(static function ($query): void {
                    $query->whereNotNull('verification_token')
                        ->where(static fn ($query) => $query->whereNull('verification_expires_at')->orWhere('verification_expires_at', '>', now()));
                })->orWhere(static function ($query): void {
                    // Publication migration and completed ownership checks can
                    // retry routing/TLS without forcing the tenant to publish
                    // a second TXT challenge.
                    $query->whereNull('verification_token')
                        ->whereNotNull('verified_at')
                        ->whereNull('published_at');
                });
            })
            ->where(static fn ($query) => $query->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', $retryBefore))
            ->whereHas('site', static fn ($query) => $query->where('status', Site::STATUS_ACTIVE))
            ->orderByRaw('CASE WHEN last_checked_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('last_checked_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $activated = 0;
        $pending   = 0;
        foreach ($domains as $domain) {
            try {
                $verification->verify($domain->site, $domain);
                $activated++;
            } catch (Throwable) {
                $pending++;
            }
        }

        $this->components->info("已检查 {$domains->count()} 个域名，激活 {$activated} 个，仍待验证 {$pending} 个。");

        return self::SUCCESS;
    }
}
