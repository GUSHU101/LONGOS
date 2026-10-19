<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'product_categories_product_category_unique';

    public function up(): void
    {
        if (! Schema::hasTable('product_categories')
            || ! Schema::hasColumns('product_categories', ['id', 'product_id', 'category_id'])) {
            return;
        }

        // Older imports could create repeated pivot rows. Keep the oldest row
        // for each pair before adding the concurrency-safe unique index.
        do {
            $duplicates = DB::table('product_categories')
                ->select(['product_id', 'category_id'])
                ->selectRaw('MIN(id) AS keep_id')
                ->groupBy('product_id', 'category_id')
                ->havingRaw('COUNT(*) > 1')
                ->limit(250)
                ->get();

            foreach ($duplicates as $duplicate) {
                DB::table('product_categories')
                    ->where('product_id', $duplicate->product_id)
                    ->where('category_id', $duplicate->category_id)
                    ->where('id', '<>', $duplicate->keep_id)
                    ->delete();
            }
        } while ($duplicates->isNotEmpty());

        $indexes = collect(Schema::getIndexes('product_categories'))->pluck('name');
        if (! $indexes->contains(self::INDEX)) {
            Schema::table('product_categories', function (Blueprint $table): void {
                $table->unique(['product_id', 'category_id'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_categories')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('product_categories'))->pluck('name');
        if ($indexes->contains(self::INDEX)) {
            Schema::table('product_categories', function (Blueprint $table): void {
                $table->dropUnique(self::INDEX);
            });
        }
    }
};
