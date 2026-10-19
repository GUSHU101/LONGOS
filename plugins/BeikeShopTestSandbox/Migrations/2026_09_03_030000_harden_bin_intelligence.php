<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beikeshop_test_checkout_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('beikeshop_test_checkout_logs', 'bin_luhn_valid')) {
                $table->boolean('bin_luhn_valid')->nullable()->after('bin_lookup_at');
            }
            if (! Schema::hasColumn('beikeshop_test_checkout_logs', 'bin_length_valid')) {
                $table->boolean('bin_length_valid')->nullable()->after('bin_luhn_valid');
            }
            if (! Schema::hasColumn('beikeshop_test_checkout_logs', 'bin_card_number_masked')) {
                $table->string('bin_card_number_masked', 32)->nullable()->after('bin_length_valid');
            }
            if (! Schema::hasColumn('beikeshop_test_checkout_logs', 'bin_response_hash')) {
                $table->char('bin_response_hash', 64)->nullable()->index()->after('bin_card_number_masked');
            }
            if (! Schema::hasColumn('beikeshop_test_checkout_logs', 'bin_response_ms')) {
                $table->unsignedInteger('bin_response_ms')->nullable()->after('bin_response_hash');
            }
        });

        Schema::create('beikeshop_test_bin_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('order_id', 32)->nullable()->index();
            $table->string('event', 60)->index();
            $table->string('provider', 60)->nullable();
            $table->string('endpoint', 80)->nullable();
            $table->string('bin_prefix', 16)->nullable()->index();
            $table->string('source', 32)->nullable()->index();
            $table->string('status', 40)->nullable()->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->string('request_id', 100)->nullable()->index();
            $table->boolean('provider_cached')->nullable();
            $table->char('response_hash', 64)->nullable()->index();
            $table->string('error_code', 100)->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beikeshop_test_bin_events');

        Schema::table('beikeshop_test_checkout_logs', function (Blueprint $table) {
            foreach ([
                'bin_luhn_valid',
                'bin_length_valid',
                'bin_card_number_masked',
                'bin_response_hash',
                'bin_response_ms',
            ] as $column) {
                if (Schema::hasColumn('beikeshop_test_checkout_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
