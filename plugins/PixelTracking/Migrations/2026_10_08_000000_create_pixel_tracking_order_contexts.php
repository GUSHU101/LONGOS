<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pixel_tracking_order_contexts')) {
            return;
        }

        Schema::create('pixel_tracking_order_contexts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->boolean('consent')->default(false);
            $table->text('attribution')->nullable();
            $table->text('browser_identifiers')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pixel_tracking_order_contexts');
    }
};
