<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('longos_live_chat_messages')) {
            if (! Schema::hasColumn('longos_live_chat_messages', 'client_message_id')) {
                Schema::table('longos_live_chat_messages', function (Blueprint $table): void {
                    $table->uuid('client_message_id')->nullable()->after('sender_id');
                });
            }
            if (! $this->hasIndex('longos_live_chat_messages', 'llc_messages_conversation_client_unique')) {
                Schema::table('longos_live_chat_messages', function (Blueprint $table): void {
                    $table->unique(
                        ['conversation_id', 'client_message_id'],
                        'llc_messages_conversation_client_unique',
                    );
                });
            }
            if (! $this->hasIndex('longos_live_chat_messages', 'llc_messages_conversation_sender_read_index')) {
                Schema::table('longos_live_chat_messages', function (Blueprint $table): void {
                    $table->index(
                        ['conversation_id', 'sender_type', 'read_at'],
                        'llc_messages_conversation_sender_read_index',
                    );
                });
            }
        }

        if (Schema::hasTable('longos_live_chat_conversations')
            && ! $this->hasIndex('longos_live_chat_conversations', 'llc_conversations_status_last_index')) {
            Schema::table('longos_live_chat_conversations', function (Blueprint $table): void {
                $table->index(['status', 'last_message_at'], 'llc_conversations_status_last_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('longos_live_chat_conversations')
            && $this->hasIndex('longos_live_chat_conversations', 'llc_conversations_status_last_index')) {
            Schema::table('longos_live_chat_conversations', function (Blueprint $table): void {
                $table->dropIndex('llc_conversations_status_last_index');
            });
        }

        if (Schema::hasTable('longos_live_chat_messages')
            && Schema::hasColumn('longos_live_chat_messages', 'client_message_id')) {
            Schema::table('longos_live_chat_messages', function (Blueprint $table): void {
                if ($this->hasIndex('longos_live_chat_messages', 'llc_messages_conversation_sender_read_index')) {
                    $table->dropIndex('llc_messages_conversation_sender_read_index');
                }
                if ($this->hasIndex('longos_live_chat_messages', 'llc_messages_conversation_client_unique')) {
                    $table->dropUnique('llc_messages_conversation_client_unique');
                }
                $table->dropColumn('client_message_id');
            });
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            static fn (array $index): bool => ($index['name'] ?? null) === $name,
        );
    }
};
