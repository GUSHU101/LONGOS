<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lit_translation_entries')) {
            return;
        }

        DB::table('lit_translation_entries')
            ->where('status', 'failed')
            ->whereIn('error_code', [
                'NO_VERIFIED_PROVIDER',
                'NO_SUPPORTED_PROVIDER',
                'PROVIDER_BUSY',
                'NETWORK_CONNECT_FAILED',
                'WORKER_EXCEPTION',
            ])
            ->update([
                'status'          => 'pending',
                'attempts'        => 0,
                'error_code'      => null,
                'locked_at'       => null,
                'next_attempt_at' => null,
                'updated_at'      => now(),
            ]);

        DB::table('lit_translation_entries')
            ->where('status', 'pending')
            ->whereNotNull('locked_at')
            ->where('locked_at', '<', now()->subMinutes(10))
            ->update(['locked_at' => null, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Recovery is intentionally not reversed: no translated content is changed.
    }
};
