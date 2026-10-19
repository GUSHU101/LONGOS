<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDefaultConnection() === 'tenant' || ! Schema::hasTable('saas_domains')) {
            return;
        }

        $publishedAt      = ! Schema::hasColumn('saas_domains', 'published_at');
        $lastProbeAt      = ! Schema::hasColumn('saas_domains', 'last_probe_at');
        $publicationError = ! Schema::hasColumn('saas_domains', 'publication_error');

        Schema::table('saas_domains', function (Blueprint $table) use ($publishedAt, $lastProbeAt, $publicationError): void {
            if ($publishedAt) {
                $table->timestamp('published_at')->nullable()->after('verified_at')->index();
            }
            if ($lastProbeAt) {
                $table->timestamp('last_probe_at')->nullable()->after('published_at');
            }
            if ($publicationError) {
                $table->text('publication_error')->nullable()->after('last_probe_at');
            }
        });

        // Platform domains are provisioned by LONGOS itself and already form
        // the recovery route for each tenant. Historical tenant-owned domains
        // intentionally remain unconfirmed so the UI no longer claims that a
        // database row proves real DNS, virtual-host and TLS readiness.
        DB::table('saas_domains')
            ->where('origin', 'platform')
            ->where('status', 'active')
            ->whereNull('published_at')
            ->update(['published_at' => DB::raw('COALESCE(verified_at, updated_at, created_at)')]);

        $affectedPrimarySites = DB::table('saas_domains')
            ->where('origin', 'tenant')
            ->where('status', 'active')
            ->where('is_primary', true)
            ->whereNull('published_at')
            ->pluck('site_id')
            ->all();

        DB::table('saas_domains')
            ->where('origin', 'tenant')
            ->where('status', 'active')
            ->whereNull('published_at')
            ->update([
                'status'             => 'pending',
                'publication_error'  => '历史记录尚未经过 Web 入口和 HTTPS 回源验证，请重新发布检测。',
                'verification_error' => '所有权已验证，但尚需确认 DNS、Web 入口和 HTTPS 已真正就绪。',
                'updated_at'         => now(),
            ]);

        // A falsely active custom domain may already have replaced the
        // platform recovery domain as primary. Promote an active platform
        // domain before taking that unconfirmed custom domain offline so the
        // tenant keeps a usable admin/storefront route and domain publication
        // invariants remain satisfiable. Sites without a platform fallback
        // keep their pending primary flag so republishing can recover them.
        foreach ($affectedPrimarySites as $siteId) {
            $fallbackId = DB::table('saas_domains')
                ->where('site_id', $siteId)
                ->where('origin', 'platform')
                ->where('status', 'active')
                ->orderBy('id')
                ->value('id');
            if ($fallbackId === null) {
                continue;
            }

            DB::table('saas_domains')->where('site_id', $siteId)->update([
                'is_primary' => false,
                'updated_at' => now(),
            ]);
            DB::table('saas_domains')->where('id', $fallbackId)->update([
                'is_primary'       => true,
                'routing_behavior' => 'redirect',
                'updated_at'       => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (DB::getDefaultConnection() === 'tenant' || ! Schema::hasTable('saas_domains')) {
            return;
        }

        $columns = array_values(array_filter(
            ['published_at', 'last_probe_at', 'publication_error'],
            static fn (string $column): bool => Schema::hasColumn('saas_domains', $column),
        ));
        if ($columns !== []) {
            if (in_array('published_at', $columns, true)) {
                Schema::table('saas_domains', static fn (Blueprint $table) => $table->dropIndex(['published_at']));
            }
            Schema::table('saas_domains', static fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
