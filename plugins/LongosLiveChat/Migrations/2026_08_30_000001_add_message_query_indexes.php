<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('longos_live_chat_messages')) {
            Schema::table('longos_live_chat_messages', function (Blueprint $table) {
                $table->index(['conversation_id', 'id'], 'llc_messages_conversation_id_id_index');
                $table->index(['sender_type', 'read_at'], 'llc_messages_sender_read_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('longos_live_chat_messages')) {
            Schema::table('longos_live_chat_messages', function (Blueprint $table) {
                $table->dropIndex('llc_messages_conversation_id_id_index');
                $table->dropIndex('llc_messages_sender_read_index');
            });
        }
    }
};
