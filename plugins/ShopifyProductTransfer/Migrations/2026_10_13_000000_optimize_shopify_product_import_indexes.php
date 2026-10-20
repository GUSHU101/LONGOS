<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array<int, string>> */
    private const INDEXES = [
        'idx_sp_import_admin_created' => ['admin_user_id', 'created_at'],
        'idx_sp_import_status_updated' => ['status', 'updated_at'],
        'idx_sp_import_status_finished' => ['status', 'finished_at'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('shopify_product_imports')) {
            return;
        }

        foreach (self::INDEXES as $name => $columns) {
            if (Schema::hasIndex('shopify_product_imports', $columns)) {
                continue;
            }

            Schema::table('shopify_product_imports', static function (Blueprint $table) use ($columns, $name): void {
                $table->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('shopify_product_imports')) {
            return;
        }

        foreach (array_reverse(self::INDEXES, true) as $name => $columns) {
            if (! Schema::hasIndex('shopify_product_imports', $columns)) {
                continue;
            }

            Schema::table('shopify_product_imports', static function (Blueprint $table) use ($name): void {
                $table->dropIndex($name);
            });
        }
    }
};
