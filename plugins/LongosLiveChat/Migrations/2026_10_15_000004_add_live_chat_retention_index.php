<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('longos_live_chat_conversations')
            && ! $this->hasIndex('longos_live_chat_conversations', 'llc_conversations_created_index')) {
            Schema::table('longos_live_chat_conversations', function (Blueprint $table): void {
                $table->index('created_at', 'llc_conversations_created_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('longos_live_chat_conversations')
            && $this->hasIndex('longos_live_chat_conversations', 'llc_conversations_created_index')) {
            Schema::table('longos_live_chat_conversations', function (Blueprint $table): void {
                $table->dropIndex('llc_conversations_created_index');
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
