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
            || ! Schema::hasTable('saas_platform_payment_sessions')
            || Schema::hasColumn('saas_platform_payment_sessions', 'active_key')) {
            return;
        }

        Schema::table('saas_platform_payment_sessions', function (Blueprint $table): void {
            $table->char('active_key', 64)->nullable()->after('token_hash')->unique('spp_active_uq');
        });
    }

    public function down(): void
    {
        if (DB::getDefaultConnection() === 'tenant'
            || ! Schema::hasTable('saas_platform_payment_sessions')
            || ! Schema::hasColumn('saas_platform_payment_sessions', 'active_key')) {
            return;
        }

        Schema::table('saas_platform_payment_sessions', function (Blueprint $table): void {
            $table->dropUnique('spp_active_uq');
            $table->dropColumn('active_key');
        });
    }
};
