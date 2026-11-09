<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lgt_job_items', function (Blueprint $table): void {
            $table->index(['entry_id', 'target_locale', 'source_hash', 'status'], 'lgt_job_pair_state');
        });
        Schema::table('lgt_publication_items', function (Blueprint $table): void {
            $table->index('translation_id', 'lgt_published_translation');
        });
    }

    public function down(): void
    {
        Schema::table('lgt_job_items', function (Blueprint $table): void {
            $table->dropIndex('lgt_job_pair_state');
        });
        Schema::table('lgt_publication_items', function (Blueprint $table): void {
            $table->dropIndex('lgt_published_translation');
        });
    }
};
