<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_user_tokens')) {
            return;
        }

        $columns = array_flip(Schema::getColumnListing('admin_user_tokens'));
        Schema::table('admin_user_tokens', function (Blueprint $table) use ($columns): void {
            if (! isset($columns['last_revealed_at'])) {
                $table->timestamp('last_revealed_at')->nullable()->after('last_used_at');
            }
            if (! isset($columns['reveal_count'])) {
                $table->unsignedInteger('reveal_count')->default(0)->after('last_revealed_at');
            }
        });

        if (! Schema::hasIndex('admin_user_tokens', ['admin_user_id'])) {
            Schema::table('admin_user_tokens', function (Blueprint $table): void {
                $table->index('admin_user_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('admin_user_tokens')) {
            return;
        }

        $columns = array_flip(Schema::getColumnListing('admin_user_tokens'));
        if (Schema::hasIndex('admin_user_tokens', 'admin_user_tokens_admin_user_id_index')) {
            Schema::table('admin_user_tokens', function (Blueprint $table): void {
                $table->dropIndex('admin_user_tokens_admin_user_id_index');
            });
        }
        Schema::table('admin_user_tokens', function (Blueprint $table) use ($columns): void {
            $drop = array_values(array_filter(
                ['last_revealed_at', 'reveal_count'],
                static fn (string $column): bool => isset($columns[$column]),
            ));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
