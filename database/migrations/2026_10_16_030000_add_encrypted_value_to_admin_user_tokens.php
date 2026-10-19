<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_user_tokens') || Schema::hasColumn('admin_user_tokens', 'token_ciphertext')) {
            return;
        }

        Schema::table('admin_user_tokens', function (Blueprint $table): void {
            $table->text('token_ciphertext')->nullable()->after('token_hash');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('admin_user_tokens') || ! Schema::hasColumn('admin_user_tokens', 'token_ciphertext')) {
            return;
        }

        Schema::table('admin_user_tokens', function (Blueprint $table): void {
            $table->dropColumn('token_ciphertext');
        });
    }
};
