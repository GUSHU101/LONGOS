<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('lgt_provider_accounts', 'archived_at')) {
            Schema::table('lgt_provider_accounts', function (Blueprint $table): void {
                $table->timestamp('archived_at')->nullable()->index('lgt_provider_archived_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('lgt_provider_accounts', 'archived_at')) {
            Schema::table('lgt_provider_accounts', function (Blueprint $table): void {
                $table->dropIndex('lgt_provider_archived_at');
                $table->dropColumn('archived_at');
            });
        }
    }
};
