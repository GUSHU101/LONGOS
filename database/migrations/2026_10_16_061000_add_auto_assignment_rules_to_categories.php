<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('categories')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table): void {
            if (! Schema::hasColumn('categories', 'auto_assign_enabled')) {
                $table->boolean('auto_assign_enabled')->default(false)->after('active');
            }
            if (! Schema::hasColumn('categories', 'auto_assign_keywords')) {
                $table->text('auto_assign_keywords')->nullable()->after('auto_assign_enabled');
            }
            if (! Schema::hasColumn('categories', 'auto_assign_brand_ids')) {
                $table->json('auto_assign_brand_ids')->nullable()->after('auto_assign_keywords');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('categories')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table): void {
            foreach (['auto_assign_brand_ids', 'auto_assign_keywords', 'auto_assign_enabled'] as $column) {
                if (Schema::hasColumn('categories', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
