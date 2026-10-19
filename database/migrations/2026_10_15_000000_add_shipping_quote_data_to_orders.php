<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders') || Schema::hasColumn('orders', 'shipping_quote_data')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->json('shipping_quote_data')->nullable()->after('shipping_method_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'shipping_quote_data')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('shipping_quote_data');
        });
    }
};
