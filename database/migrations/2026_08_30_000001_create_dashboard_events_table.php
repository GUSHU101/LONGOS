<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dashboard_events')) {
            return;
        }

        Schema::create('dashboard_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 32);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('session_id', 191)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['event_type', 'created_at']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_events');
    }
};
