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
                // Explicit compact names are required because shared-database
                // tenants prepend a 16-character namespace to every table.
                // Laravel's generated names can otherwise exceed MySQL's
                // 64-character identifier limit during tenant provisioning.
                $table->uuid('public_id')->unique('uq_llc_conversation_public');
                $table->string('visitor_token', 64)->unique('uq_llc_conversation_visitor');
                $table->unsignedBigInteger('customer_id')->nullable()->index('idx_llc_conversation_customer');
                $table->string('visitor_name')->nullable();
                $table->string('visitor_email')->nullable();
                $table->string('status', 16)->default('open')->index('idx_llc_conversation_status');
                $table->timestamp('last_message_at')->nullable()->index('idx_llc_conversation_last_message');
                $table->timestamp('admin_last_read_at')->nullable();
                $table->timestamp('visitor_last_read_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('longos_live_chat_messages')) {
            // Foreign-key symbols are schema-wide in MySQL. Include a compact
            // hash of the physical tenant prefix so each tenant gets a unique
            // symbol without approaching the 64-character identifier limit.
            $conversationForeignKey = 'fk_llcm_' . substr(
                hash('sha256', Schema::getConnection()->getTablePrefix() ?: 'control'),
                0,
                16,
            );

            Schema::create('longos_live_chat_messages', function (Blueprint $table) use ($conversationForeignKey) {
                $table->id();
                $table->unsignedBigInteger('conversation_id')->index('idx_llc_message_conversation');
                $table->string('sender_type', 16)->index('idx_llc_message_sender');
                $table->unsignedBigInteger('sender_id')->nullable();
                $table->text('body');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->foreign('conversation_id', $conversationForeignKey)
                    ->references('id')
                    ->on('longos_live_chat_conversations')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('longos_live_chat_messages');
        Schema::dropIfExists('longos_live_chat_conversations');
    }
};
