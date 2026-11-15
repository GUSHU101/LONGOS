<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('longos_product_reviews') && ! Schema::hasColumn('longos_product_reviews', 'ai_generated')) {
            Schema::table('longos_product_reviews', function (Blueprint $table): void {
                $table->boolean('ai_generated')->default(false)->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('longos_product_reviews') && Schema::hasColumn('longos_product_reviews', 'ai_generated')) {
            Schema::table('longos_product_reviews', function (Blueprint $table): void {
                $table->dropColumn('ai_generated');
            });
        }
    }
};
