<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('longos_live_chat_conversations')) {
            Schema::create('longos_live_chat_conversations', function (Blueprint $table) {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->string('visitor_token', 64)->unique();
                $table->unsignedBigInteger('customer_id')->nullable()->index();
                $table->string('visitor_name')->nullable();
                $table->string('visitor_email')->nullable();
                $table->string('status', 16)->default('open')->index();
                $table->timestamp('last_message_at')->nullable()->index();
                $table->timestamp('admin_last_read_at')->nullable();
                $table->timestamp('visitor_last_read_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('longos_live_chat_messages')) {
            Schema::create('longos_live_chat_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('conversation_id')->index();
                $table->string('sender_type', 16)->index();
                $table->unsignedBigInteger('sender_id')->nullable();
                $table->text('body');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->foreign('conversation_id')->references('id')->on('longos_live_chat_conversations')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('longos_live_chat_messages');
        Schema::dropIfExists('longos_live_chat_conversations');
    }
};
