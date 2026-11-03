<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Repair only columns introduced after the initial preview release. */
return new class extends Migration
{
    public function up(): void
    {
        // The preview build recorded the first migration before these tables
        // existed. Re-run its idempotent creation routine so an upgrade is
        // repairable without migrate:fresh or operator data deletion.
        $foundation = require __DIR__ . '/2026_11_02_000000_create_translation_tables.php';
        $foundation->up();

        if (Schema::hasTable('lgt_entries')) {
            Schema::table('lgt_entries', function (Blueprint $t): void {
                foreach ([
                    'source_format'   => fn () => $t->string('source_format', 16)->default('plain'),
                    'source_revision' => fn () => $t->string('source_revision', 64)->nullable(),
                    'is_public'       => fn () => $t->boolean('is_public')->default(true),
                    'is_tombstoned'   => fn () => $t->boolean('is_tombstoned')->default(false),
                    'metadata'        => fn () => $t->json('metadata')->nullable(),
                    'last_scanned_at' => fn () => $t->timestamp('last_scanned_at')->nullable(),
                ] as $column => $definition) {
                    if (! Schema::hasColumn('lgt_entries', $column)) {
                        $definition();
                    }
                }
            });
        }
        if (Schema::hasTable('lgt_translations')) {
            Schema::table('lgt_translations', function (Blueprint $t): void {
                foreach ([
                    'provider'            => fn () => $t->string('provider', 24)->nullable(),
                    'provider_account_id' => fn () => $t->unsignedBigInteger('provider_account_id')->nullable(),
                    'quality_code'        => fn () => $t->string('quality_code', 64)->nullable(),
                    'locked_by'           => fn () => $t->unsignedBigInteger('locked_by')->nullable(),
                    'locked_at'           => fn () => $t->timestamp('locked_at')->nullable(),
                    'reviewed_by'         => fn () => $t->unsignedBigInteger('reviewed_by')->nullable(),
                    'reviewed_at'         => fn () => $t->timestamp('reviewed_at')->nullable(),
                ] as $column => $definition) {
                    if (! Schema::hasColumn('lgt_translations', $column)) {
                        $definition();
                    }
                }
            });
        }
    }

    public function down(): void {}
};
