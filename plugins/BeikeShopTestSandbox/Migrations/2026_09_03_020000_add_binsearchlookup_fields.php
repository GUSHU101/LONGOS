<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beikeshop_test_checkout_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('beikeshop_test_checkout_logs', 'bin_prefix')) {
                $table->string('bin_prefix', 8)->nullable()->index()->after('pan_last4');
            }
            if (! Schema::hasColumn('beikeshop_test_checkout_logs', 'bin_lookup_status')) {
                $table->string('bin_lookup_status', 32)->nullable()->index()->after('bin_prefix');
            }
            if (! Schema::hasColumn('beikeshop_test_checkout_logs', 'bin_lookup_source')) {
                $table->string('bin_lookup_source', 20)->nullable()->after('bin_lookup_status');
            }
            if (! Schema::hasColumn('beikeshop_test_checkout_logs', 'bin_lookup_data')) {
                $table->longText('bin_lookup_data')->nullable()->after('bin_lookup_source');
            }
            if (! Schema::hasColumn('beikeshop_test_checkout_logs', 'bin_lookup_at')) {
                $table->timestamp('bin_lookup_at')->nullable()->after('bin_lookup_data');
            }
        });
    }

    public function down(): void
    {
        Schema::table('beikeshop_test_checkout_logs', function (Blueprint $table) {
            foreach ([
                'bin_prefix',
                'bin_lookup_status',
                'bin_lookup_source',
                'bin_lookup_data',
                'bin_lookup_at',
            ] as $column) {
                if (Schema::hasColumn('beikeshop_test_checkout_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
