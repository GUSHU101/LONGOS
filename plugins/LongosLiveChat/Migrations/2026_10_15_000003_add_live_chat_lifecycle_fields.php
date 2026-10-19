<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('longos_live_chat_conversations')) {
            return;
        }

        if (! Schema::hasColumn('longos_live_chat_conversations', 'closed_at')) {
            Schema::table('longos_live_chat_conversations', function (Blueprint $table): void {
                $table->timestamp('closed_at')->nullable()->after('status');
            });
        }

        if (! $this->hasIndex('longos_live_chat_conversations', 'llc_conversations_status_closed_index')) {
            Schema::table('longos_live_chat_conversations', function (Blueprint $table): void {
                $table->index(['status', 'closed_at'], 'llc_conversations_status_closed_index');
            });
        }

        DB::table('longos_live_chat_conversations')
            ->where('status', 'closed')
            ->whereNull('closed_at')
            ->update(['closed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        if (Schema::hasTable('longos_live_chat_conversations')
            && Schema::hasColumn('longos_live_chat_conversations', 'closed_at')) {
            Schema::table('longos_live_chat_conversations', function (Blueprint $table): void {
                if ($this->hasIndex('longos_live_chat_conversations', 'llc_conversations_status_closed_index')) {
                    $table->dropIndex('llc_conversations_status_closed_index');
                }
                $table->dropColumn('closed_at');
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
