<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDefaultConnection() === 'tenant' || ! Schema::hasTable('saas_sites')) {
            return;
        }

        $addToken     = ! Schema::hasColumn('saas_sites', 'provisioning_token');
        $addHeartbeat = ! Schema::hasColumn('saas_sites', 'provisioning_heartbeat_at');
        if (! $addToken && ! $addHeartbeat) {
            return;
        }

        Schema::table('saas_sites', function (Blueprint $table) use ($addToken, $addHeartbeat): void {
            if ($addToken) {
                $table->uuid('provisioning_token')->nullable()->after('status');
            }
            if ($addHeartbeat) {
                $table->timestamp('provisioning_heartbeat_at')->nullable()->after('provisioning_token');
            }
        });
    }

    public function down(): void
    {
        if (DB::getDefaultConnection() === 'tenant' || ! Schema::hasTable('saas_sites')) {
            return;
        }

        $columns = array_values(array_filter([
            'provisioning_token',
            'provisioning_heartbeat_at',
        ], static fn (string $column): bool => Schema::hasColumn('saas_sites', $column)));

        if ($columns !== []) {
            Schema::table('saas_sites', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
