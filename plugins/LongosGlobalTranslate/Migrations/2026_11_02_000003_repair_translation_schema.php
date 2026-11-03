<?php

use Illuminate\Database\Migrations\Migration;

/*
 * Repair deployments that recorded an early preview migration before every
 * lgt_* table or the worker claim-token column was present.  It only invokes
 * the existing idempotent creators; it never drops data, resets a provider,
 * or rewrites a merchant's settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            '2026_11_02_000000_create_translation_tables.php',
            '2026_11_02_000001_upgrade_translation_schema.php',
            '2026_11_02_000002_add_worker_claim_tokens.php',
            '2026_11_03_000004_add_provider_dispatch_rate_limit.php',
        ] as $file) {
            $migration = require __DIR__ . '/' . $file;
            $migration->up();
        }
    }

    public function down(): void
    {
        // A code rollback must preserve translation assets and accounting
        // records. Recovery is handled by a matching Coolify deployment.
    }
};
