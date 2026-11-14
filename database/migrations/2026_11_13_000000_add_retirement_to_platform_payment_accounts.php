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
            || ! Schema::hasTable('saas_platform_payment_accounts')
            || Schema::hasColumn('saas_platform_payment_accounts', 'deleted_at')) {
            return;
        }

        Schema::table('saas_platform_payment_accounts', function (Blueprint $table): void {
            $table->softDeletes()->index('sppa_deleted_at_idx');
        });
    }

    public function down(): void
    {
        if (DB::getDefaultConnection() === 'tenant'
            || ! Schema::hasTable('saas_platform_payment_accounts')
            || ! Schema::hasColumn('saas_platform_payment_accounts', 'deleted_at')) {
            return;
        }

        Schema::table('saas_platform_payment_accounts', function (Blueprint $table): void {
            $table->dropIndex('sppa_deleted_at_idx');
            $table->dropSoftDeletes();
        });
    }
};
