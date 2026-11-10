<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lgt_locale_maintenance')) {
            Schema::create('lgt_locale_maintenance', function (Blueprint $table): void {
                $table->id();
                $table->string('locale', 24)->unique('lgt_maintenance_locale');
                $table->timestamps();
            });
        }
        if (! Schema::hasColumn('lgt_jobs', 'archived_at')) {
            Schema::table('lgt_jobs', function (Blueprint $table): void {
                $table->timestamp('archived_at')->nullable()->index('lgt_job_archived_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('lgt_jobs', 'archived_at')) {
            Schema::table('lgt_jobs', function (Blueprint $table): void {
                $table->dropColumn('archived_at');
            });
        }
        Schema::dropIfExists('lgt_locale_maintenance');
    }
};
