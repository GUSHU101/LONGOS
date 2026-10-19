<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->string('currency_code', 3)->primary()->comment('ISO 4217 currency code');
            $table->string('base_currency', 3)->default('USD')->comment('Rate base currency');
            $table->decimal('rate', 30, 12)->comment('Units of currency per one base currency');
            $table->timestamp('provider_updated_at')->nullable()->comment('Provider source timestamp');
            $table->timestamp('fetched_at')->comment('Local successful refresh timestamp');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
