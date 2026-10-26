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

        $addCreatedIndex = ! Schema::hasIndex('saas_platform_payment_sessions', 'spp_created_status_provider_idx');
        $addSiteIndex    = ! Schema::hasIndex('saas_platform_payment_sessions', 'spp_site_created_status_idx');
        if (! $addCreatedIndex && ! $addSiteIndex) {
            return;
        }

        Schema::table('saas_platform_payment_sessions', function (Blueprint $table) use ($addCreatedIndex, $addSiteIndex): void {
            if ($addCreatedIndex) {
                $table->index(['created_at', 'status', 'provider'], 'spp_created_status_provider_idx');
            }
            if ($addSiteIndex) {
                $table->index(['site_id', 'created_at', 'status'], 'spp_site_created_status_idx');
            }
        });
    }

    public function down(): void
    {
        if (DB::getDefaultConnection() === 'tenant'
            || ! Schema::hasTable('saas_platform_payment_sessions')) {
            return;
        }

        $dropCreatedIndex = Schema::hasIndex('saas_platform_payment_sessions', 'spp_created_status_provider_idx');
        $dropSiteIndex    = Schema::hasIndex('saas_platform_payment_sessions', 'spp_site_created_status_idx');
        if (! $dropCreatedIndex && ! $dropSiteIndex) {
            return;
        }

        Schema::table('saas_platform_payment_sessions', function (Blueprint $table) use ($dropCreatedIndex, $dropSiteIndex): void {
            if ($dropCreatedIndex) {
                $table->dropIndex('spp_created_status_provider_idx');
            }
            if ($dropSiteIndex) {
                $table->dropIndex('spp_site_created_status_idx');
            }
        });
    }
};
