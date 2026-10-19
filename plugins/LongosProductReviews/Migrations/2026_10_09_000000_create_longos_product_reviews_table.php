<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('longos_product_reviews')) {
            return;
        }

        Schema::create('longos_product_reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('author_name', 120);
            $table->string('author_country', 80)->nullable();
            $table->unsignedTinyInteger('rating')->index();
            $table->string('title', 160);
            $table->text('body');
            $table->json('images')->nullable();
            $table->boolean('verified_purchase')->default(true)->index();
            $table->boolean('published')->default(true)->index();
            $table->integer('sort_order')->default(0)->index();
            $table->timestamp('reviewed_at')->index();
            $table->timestamps();

            $table->index(['product_id', 'published', 'sort_order', 'reviewed_at'], 'longos_reviews_storefront_index');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('longos_product_reviews');
    }
};
