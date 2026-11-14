<?php

use App\Saas\Models\Domain;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->schemaReady()) {
            return;
        }

        $callbackHosts = DB::table('saas_platform_payment_accounts')
            ->select('callback_host')
            ->whereNotNull('callback_host');
        if (Schema::hasColumn('saas_platform_payment_accounts', 'deleted_at')) {
            $callbackHosts->whereNull('deleted_at');
        }

        DB::table('saas_domains')
            ->where('is_primary', false)
            ->where('origin', Domain::ORIGIN_PLATFORM)
            ->whereIn('host', $callbackHosts)
            ->update([
                'routing_behavior' => Domain::ROUTING_ALIAS,
                'updated_at'       => now('UTC'),
            ]);
    }

    public function down(): void
    {
        if (! $this->schemaReady()) {
            return;
        }

        $callbackHosts = DB::table('saas_platform_payment_accounts')
            ->select('callback_host')
            ->whereNotNull('callback_host');
        if (Schema::hasColumn('saas_platform_payment_accounts', 'deleted_at')) {
            $callbackHosts->whereNull('deleted_at');
        }

        DB::table('saas_domains')
            ->where('is_primary', false)
            ->where('origin', Domain::ORIGIN_PLATFORM)
            ->whereIn('host', $callbackHosts)
            ->where('routing_behavior', Domain::ROUTING_ALIAS)
            ->update([
                'routing_behavior' => Domain::ROUTING_REDIRECT,
                'updated_at'       => now('UTC'),
            ]);
    }

    private function schemaReady(): bool
    {
        return DB::getDefaultConnection() !== 'tenant'
            && Schema::hasTable('saas_domains')
            && Schema::hasTable('saas_platform_payment_accounts')
            && Schema::hasColumn('saas_domains', 'routing_behavior')
            && Schema::hasColumn('saas_platform_payment_accounts', 'callback_host');
    }
};
