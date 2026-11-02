<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        if (! Schema::hasColumn('orders', 'checkout_token_hash')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->char('checkout_token_hash', 64)->nullable()->after('number');
            });
        }

        if (! Schema::hasIndex('orders', 'orders_checkout_token_hash_unique')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unique('checkout_token_hash', 'orders_checkout_token_hash_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'checkout_token_hash')) {
            return;
        }

        if (Schema::hasIndex('orders', 'orders_checkout_token_hash_unique')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropUnique('orders_checkout_token_hash_unique');
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('checkout_token_hash');
        });
    }
};
