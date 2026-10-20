<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('beikeshop_test_checkout_logs')) {
            return;
        }

        Schema::table('beikeshop_test_checkout_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('beikeshop_test_checkout_logs', 'device_fingerprint_hash')) {
                $table->char('device_fingerprint_hash', 64)->nullable()->index('idx_bstcl_device_fingerprint');
            }
            if (! Schema::hasColumn('beikeshop_test_checkout_logs', 'risk_score')) {
                $table->unsignedTinyInteger('risk_score')->default(0)->index();
            }
            if (! Schema::hasColumn('beikeshop_test_checkout_logs', 'risk_flags')) {
                $table->json('risk_flags')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('beikeshop_test_checkout_logs')) {
            return;
        }

        Schema::table('beikeshop_test_checkout_logs', function (Blueprint $table) {
            $columns = [];
            foreach (['device_fingerprint_hash', 'risk_score', 'risk_flags'] as $column) {
                if (Schema::hasColumn('beikeshop_test_checkout_logs', $column)) {
                    $columns[] = $column;
                }
            }
            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
