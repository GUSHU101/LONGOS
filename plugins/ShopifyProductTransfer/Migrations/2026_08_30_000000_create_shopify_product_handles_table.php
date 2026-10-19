<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shopify_product_handles')) {
            $missingTimestamps = ! Schema::hasColumn('shopify_product_handles', 'created_at')
                || ! Schema::hasColumn('shopify_product_handles', 'updated_at');
            if ($missingTimestamps) {
                Schema::table('shopify_product_handles', function (Blueprint $table): void {
                    if (! Schema::hasColumn('shopify_product_handles', 'created_at')) {
                        $table->timestamp('created_at')->nullable();
                    }
                    if (! Schema::hasColumn('shopify_product_handles', 'updated_at')) {
                        $table->timestamp('updated_at')->nullable();
                    }
                });
            }

            return;
        }

        Schema::create('shopify_product_handles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->unique();
            $table->string('handle')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_product_handles');
    }
};
