<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing(
            'order_histories',
            'idx_order_histories_paid_first_transition',
            ['status', 'order_id', 'created_at']
        );
        $this->addIndexIfMissing(
            'storefront_visits',
            'idx_storefront_visits_first_touch',
            ['visitor_key', 'first_seen_at']
        );
        $this->addIndexIfMissing(
            'storefront_visits',
            'idx_storefront_visits_latest_country',
            ['visitor_key', 'last_seen_at']
        );
    }

    public function down(): void
    {
        $this->dropIndexIfPresent('order_histories', 'idx_order_histories_paid_first_transition');
        $this->dropIndexIfPresent('storefront_visits', 'idx_storefront_visits_first_touch');
        $this->dropIndexIfPresent('storefront_visits', 'idx_storefront_visits_latest_country');
    }

    /** @param string[] $columns */
    private function addIndexIfMissing(string $table, string $name, array $columns): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
            return;
        }

        $indexes = collect(Schema::getIndexes($table))->pluck('name');
        if ($indexes->contains($name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
            $blueprint->index($columns, $name);
        });
    }

    private function dropIndexIfPresent(string $table, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $indexes = collect(Schema::getIndexes($table))->pluck('name');
        if (! $indexes->contains($name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name): void {
            $blueprint->dropIndex($name);
        });
    }
};
