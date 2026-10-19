<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shopify_product_variant_skus')) {
            return;
        }

        Schema::create('shopify_product_variant_skus', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('internal_sku')->unique();
            $table->string('source_sku');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_product_variant_skus');
    }
};
