<?php

use Beike\Services\StorefrontVisitService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_views')) {
            return;
        }

        if (! Schema::hasColumn('product_views', 'visitor_key')) {
            Schema::table('product_views', function (Blueprint $table): void {
                $table->char('visitor_key', 64)->nullable()->after('session_id')
                    ->comment('One-way hash shared with storefront visit analytics');
                $table->index(['created_at', 'visitor_key'], 'product_views_created_visitor_key_index');
            });
        }

        // Existing product views are the only traffic signal during a rolling
        // upgrade. Backfilling the same one-way key used by storefront visits
        // lets the dashboard merge both ledgers without double counting.
        DB::table('product_views')
            ->whereNull('visitor_key')
            ->whereNotNull('session_id')
            ->where('session_id', '<>', '')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $visitorKey = StorefrontVisitService::visitorKeyForSession((string) $row->session_id);
                    if ($visitorKey !== null) {
                        DB::table('product_views')->where('id', $row->id)->update(['visitor_key' => $visitorKey]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_views') || ! Schema::hasColumn('product_views', 'visitor_key')) {
            return;
        }

        Schema::table('product_views', function (Blueprint $table): void {
            if (Schema::hasIndex('product_views', 'product_views_created_visitor_key_index')) {
                $table->dropIndex('product_views_created_visitor_key_index');
            }
            $table->dropColumn('visitor_key');
        });
    }
};
