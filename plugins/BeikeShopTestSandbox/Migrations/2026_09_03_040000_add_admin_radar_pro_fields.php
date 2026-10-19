<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beikeshop_test_checkout_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('beikeshop_test_checkout_logs', 'effective_class_code')) {
                $table->string('effective_class_code', 8)->nullable()->index()->after('card_type');
            }
            if (! Schema::hasColumn('beikeshop_test_checkout_logs', 'effective_funding_type')) {
                $table->string('effective_funding_type', 24)->nullable()->index()->after('effective_class_code');
            }
            if (! Schema::hasColumn('beikeshop_test_checkout_logs', 'classification_authority')) {
                $table->string('classification_authority', 32)->nullable()->index()->after('effective_funding_type');
            }
        });

        // Backfill existing V2.x sandbox rows so V3.0 filters work immediately.
        DB::table('beikeshop_test_checkout_logs')
            ->whereNull('effective_class_code')
            ->where('card_type', 'like', 'C卡%')
            ->update([
                'effective_class_code'     => 'C',
                'effective_funding_type'   => 'CREDIT',
                'classification_authority' => 'LOCAL_TEST_PROFILE',
            ]);

        DB::table('beikeshop_test_checkout_logs')
            ->whereNull('effective_class_code')
            ->where('card_type', 'like', 'D卡%')
            ->update([
                'effective_class_code'     => 'D',
                'effective_funding_type'   => 'DEBIT',
                'classification_authority' => 'LOCAL_TEST_PROFILE',
            ]);

        DB::table('beikeshop_test_checkout_logs')
            ->whereNull('effective_class_code')
            ->where('card_type', 'like', 'P卡%')
            ->update([
                'effective_class_code'     => 'P',
                'effective_funding_type'   => 'PREPAID',
                'classification_authority' => 'LOCAL_TEST_PROFILE',
            ]);

        DB::table('beikeshop_test_checkout_logs')
            ->whereNull('effective_class_code')
            ->where('card_type', 'like', 'G卡%')
            ->update([
                'effective_class_code'     => 'G',
                'effective_funding_type'   => 'GIFT',
                'classification_authority' => 'LOCAL_TEST_PROFILE',
            ]);

        if (! Schema::hasTable('beikeshop_test_review_events')) {
            Schema::create('beikeshop_test_review_events', function (Blueprint $table) {
                $table->id();
                $table->uuid('event_id')->unique();
                $table->string('order_id', 32)->index();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->string('action', 32)->index();
                $table->string('from_status', 32)->nullable();
                $table->string('to_status', 32)->nullable()->index();
                $table->string('reason', 250)->nullable();
                $table->timestamp('created_at')->index();
            });
        }

        if (! Schema::hasTable('beikeshop_test_admin_events')) {
            Schema::create('beikeshop_test_admin_events', function (Blueprint $table) {
                $table->id();
                $table->uuid('event_id')->unique();
                $table->string('order_id', 32)->nullable()->index();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->string('event', 60)->index();
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('beikeshop_test_admin_events');
        Schema::dropIfExists('beikeshop_test_review_events');

        Schema::table('beikeshop_test_checkout_logs', function (Blueprint $table) {
            foreach ([
                'classification_authority',
                'effective_funding_type',
                'effective_class_code',
            ] as $column) {
                if (Schema::hasColumn('beikeshop_test_checkout_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
