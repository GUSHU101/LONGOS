<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, array<int, string>> */
    private const INDEXES = [
        ['admin_user_id', 'created_at'],
        ['status', 'updated_at'],
        ['status', 'finished_at'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('shopify_product_imports')) {
            return;
        }

        foreach (self::INDEXES as $columns) {
            if (Schema::hasIndex('shopify_product_imports', $columns)) {
                continue;
            }

            Schema::table('shopify_product_imports', static function (Blueprint $table) use ($columns): void {
                $table->index($columns);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('shopify_product_imports')) {
            return;
        }

        foreach (array_reverse(self::INDEXES) as $columns) {
            if (! Schema::hasIndex('shopify_product_imports', $columns)) {
                continue;
            }

            Schema::table('shopify_product_imports', static function (Blueprint $table) use ($columns): void {
                $table->dropIndex($columns);
            });
        }
    }
};
