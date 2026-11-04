<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Failover must be deterministic. Existing accounts receive a neutral
 * priority and retain their ID ordering; an operator can later set a lower
 * number for the preferred account without exposing credentials.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lgt_provider_accounts') || Schema::hasColumn('lgt_provider_accounts', 'failover_priority')) {
            return;
        }

        Schema::table('lgt_provider_accounts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('failover_priority')->default(100);
        });
    }

    public function down(): void
    {
        // Priority affects only future routing. Preserve it on rollback so a
        // deployment rollback cannot silently reorder provider usage.
    }
};
