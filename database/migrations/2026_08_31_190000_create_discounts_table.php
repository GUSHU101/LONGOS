<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('mode', 20)->default('automatic');
            $table->string('code', 64)->nullable()->unique();
            $table->string('type', 32);
            $table->string('value_type', 16)->default('percentage');
            $table->decimal('value', 12, 4)->default(0);
            $table->decimal('minimum_amount', 12, 4)->default(0);
            $table->unsignedInteger('buy_quantity')->default(1);
            $table->unsignedInteger('get_quantity')->default(1);
            $table->json('product_ids')->nullable();
            $table->json('gift_product_ids')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'mode', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
