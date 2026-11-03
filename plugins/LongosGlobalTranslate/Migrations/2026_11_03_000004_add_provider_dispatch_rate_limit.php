<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Existing tenants may already have recorded the first schema-repair
 * migrations.  Keep this as a separate, idempotent migration rather than
 * relying on an edited historical file, so Coolify's normal upgrade runner
 * can add the durable per-account dispatch limiter without any manual step.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lgt_provider_accounts') || Schema::hasColumn('lgt_provider_accounts', 'last_dispatch_at_us')) {
            return;
        }

        Schema::table('lgt_provider_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('last_dispatch_at_us')->default(0);
        });
    }

    public function down(): void
    {
        // The timestamp is accounting/safety state. Preserve it on rollback.
    }
};
