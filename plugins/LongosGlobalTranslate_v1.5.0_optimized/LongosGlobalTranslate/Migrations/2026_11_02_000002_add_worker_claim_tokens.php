<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A queue worker can be lost after it claims a job item.  A durable run token
 * lets the failed-job hook finish only its own claim, never a concurrent
 * worker's item.  The guard keeps this upgrade safe for both fresh installs
 * and older preview installations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lgt_job_items') || Schema::hasColumn('lgt_job_items', 'claim_token')) {
            return;
        }

        Schema::table('lgt_job_items', function (Blueprint $table): void {
            $table->uuid('claim_token')->nullable()->after('status');
            $table->index(['job_id', 'status', 'claim_token'], 'lgt_job_item_claim');
        });
    }

    public function down(): void {}
};
