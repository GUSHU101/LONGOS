<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDefaultConnection() === 'tenant'
            || ! Schema::hasTable('saas_platform_payment_sessions')) {
            return;
        }

        $addProviderIndex = ! Schema::hasIndex('saas_platform_payment_sessions', 'spp_provider_status_created_idx');
        $addSiteIndex     = ! Schema::hasIndex('saas_platform_payment_sessions', 'spp_site_status_created_idx');
        if (! $addProviderIndex && ! $addSiteIndex) {
            return;
        }

        Schema::table('saas_platform_payment_sessions', function (Blueprint $table) use ($addProviderIndex, $addSiteIndex): void {
            if ($addProviderIndex) {
                $table->index(['provider', 'status', 'created_at'], 'spp_provider_status_created_idx');
            }
            if ($addSiteIndex) {
                $table->index(['site_id', 'status', 'created_at'], 'spp_site_status_created_idx');
            }
        });
    }

    public function down(): void
    {
        if (DB::getDefaultConnection() === 'tenant'
            || ! Schema::hasTable('saas_platform_payment_sessions')) {
            return;
        }

        $dropProviderIndex = Schema::hasIndex('saas_platform_payment_sessions', 'spp_provider_status_created_idx');
        $dropSiteIndex     = Schema::hasIndex('saas_platform_payment_sessions', 'spp_site_status_created_idx');
        if (! $dropProviderIndex && ! $dropSiteIndex) {
            return;
        }

        Schema::table('saas_platform_payment_sessions', function (Blueprint $table) use ($dropProviderIndex, $dropSiteIndex): void {
            if ($dropProviderIndex) {
                $table->dropIndex('spp_provider_status_created_idx');
            }
            if ($dropSiteIndex) {
                $table->dropIndex('spp_site_status_created_idx');
            }
        });
    }
};
