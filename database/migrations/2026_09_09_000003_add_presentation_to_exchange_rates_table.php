<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->string('currency_symbol', 32)->default('')->after('rate');
            $table->unsignedTinyInteger('decimal_places')->default(2)->after('currency_symbol');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->dropColumn(['currency_symbol', 'decimal_places']);
        });
    }
};
