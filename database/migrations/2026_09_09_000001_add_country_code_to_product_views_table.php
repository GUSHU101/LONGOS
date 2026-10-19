<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_views') || Schema::hasColumn('product_views', 'country_code')) {
            return;
        }

        Schema::table('product_views', function (Blueprint $table) {
            $table->string('country_code', 2)->nullable()->after('ip');
            $table->index(['country_code', 'created_at'], 'product_views_country_created_at_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_views') || ! Schema::hasColumn('product_views', 'country_code')) {
            return;
        }

        Schema::table('product_views', function (Blueprint $table) {
            $table->dropIndex('product_views_country_created_at_index');
            $table->dropColumn('country_code');
        });
    }
};
