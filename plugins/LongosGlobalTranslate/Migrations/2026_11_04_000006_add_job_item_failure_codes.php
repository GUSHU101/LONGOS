<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Keep the operator-facing failure cause on the tenant-local work item.
 * The encrypted credentials and provider payload never enter this field.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lgt_job_items') && ! Schema::hasColumn('lgt_job_items', 'failure_code')) {
            Schema::table('lgt_job_items', function (Blueprint $table): void {
                $table->string('failure_code', 96)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Plugin rollbacks preserve operational history and do not delete
        // tenant audit data. A backup-gated cleanup is a separate action.
    }
};
