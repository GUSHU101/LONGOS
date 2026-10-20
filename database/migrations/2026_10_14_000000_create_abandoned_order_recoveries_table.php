<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('abandoned_order_recoveries')) {
            return;
        }

        Schema::create('abandoned_order_recoveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('admin_user_id')->nullable()->index();
            $table->unsignedBigInteger('discount_id')->nullable()->index();
            $table->unsignedBigInteger('converted_order_id')->nullable()->index('idx_recoveries_converted_order');
            $table->string('recipient_email', 191);
            $table->string('delivery_id', 128)->unique();
            $table->char('access_token_hash', 64)->unique('uq_recoveries_access_token_hash');
            $table->string('status', 24)->default('sending');
            $table->string('sender_category', 32)->default('orders');
            $table->string('subject', 255);
            $table->text('body');
            $table->string('discount_code', 64)->nullable();
            $table->unsignedSmallInteger('transport_attempts')->default(1);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamp('expires_at');
            $table->text('failure_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status', 'sent_at'], 'idx_recoveries_order_delivery');
            $table->index(['recipient_email', 'status'], 'idx_recoveries_recipient_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abandoned_order_recoveries');
    }
};
