<?php

use App\Saas\Support\DomainName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_RECLASSIFICATION_ERROR = '该域名由旧版本直接发布，升级后必须重新完成所有权、流量 DNS 和 HTTPS 验证。';

    public function up(): void
    {
        if (DB::getDefaultConnection() === 'tenant'
            || ! Schema::hasTable('saas_sites')
            || ! Schema::hasTable('saas_domains')) {
            return;
        }

        try {
            $baseDomain = DomainName::normalize((string) config('saas.base_domain'));
        } catch (Throwable) {
            return;
        }

        $controlHosts = [];
        foreach ((array) config('saas.control_domains', []) as $controlDomain) {
            try {
                $controlHosts[] = DomainName::normalize((string) $controlDomain);
            } catch (Throwable) {
                continue;
            }
        }

        DB::transaction(function () use ($baseDomain, $controlHosts): void {
            $sites = DB::table('saas_sites')->orderBy('id')->get(['id', 'meta']);
            foreach ($sites as $site) {
                $meta = json_decode((string) ($site->meta ?? ''), true);
                if (is_array($meta) && (bool) ($meta['control_site'] ?? false)) {
                    continue;
                }

                $affected = DB::table('saas_domains')
                    ->where('site_id', $site->id)
                    ->where('origin', 'tenant')
                    ->where('status', 'pending')
                    ->where('verification_error', self::LEGACY_RECLASSIFICATION_ERROR)
                    ->orderBy('id')
                    ->get()
                    ->filter(fn ($domain): bool => $this->isPlatformChild(
                        (string) $domain->host,
                        $baseDomain,
                        $controlHosts,
                    ));

                if ($affected->isEmpty()) {
                    continue;
                }

                $now = now();
                DB::table('saas_domains')->whereIn('id', $affected->pluck('id')->all())->update([
                    'status'                    => 'active',
                    'origin'                    => 'platform',
                    'verification_method'       => null,
                    'verification_token'        => null,
                    'verification_expires_at'   => null,
                    'verification_attempts'     => 0,
                    'verified_at'               => null,
                    'last_checked_at'           => null,
                    'verification_error'        => null,
                    'published_at'              => $now,
                    'publication_error'         => null,
                    'updated_at'                => $now,
                ]);

                $hasPrimary = DB::table('saas_domains')
                    ->where('site_id', $site->id)
                    ->where('status', 'active')
                    ->where('is_primary', true)
                    ->exists();
                if (! $hasPrimary) {
                    DB::table('saas_domains')->where('id', $affected->first()->id)->update([
                        'is_primary' => true,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // This only repairs platform-managed domains that a previous migration
        // misclassified. Reverting would deliberately recreate that defect.
    }

    /** @param array<int, string> $controlHosts */
    private function isPlatformChild(string $host, string $baseDomain, array $controlHosts): bool
    {
        $host = strtolower(trim($host, '.'));
        if ($host === $baseDomain || ! str_ends_with($host, '.' . $baseDomain)) {
            return false;
        }

        return ! in_array($host, $controlHosts, true);
    }
};
