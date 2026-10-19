<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_histories')
            || ! Schema::hasColumns('order_histories', ['status', 'created_at', 'order_id'])) {
            return;
        }

        $indexName = 'idx_order_histories_paid_dashboard';
        $indexes   = collect(Schema::getIndexes('order_histories'))->pluck('name');
        if ($indexes->contains($indexName)) {
            return;
        }

        Schema::table('order_histories', function (Blueprint $table) use ($indexName): void {
            $table->index(['status', 'created_at', 'order_id'], $indexName);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('order_histories')) {
            return;
        }

        $indexName = 'idx_order_histories_paid_dashboard';
        $indexes   = collect(Schema::getIndexes('order_histories'))->pluck('name');
        if (! $indexes->contains($indexName)) {
            return;
        }

        Schema::table('order_histories', function (Blueprint $table) use ($indexName): void {
            $table->dropIndex($indexName);
        });
    }
};
