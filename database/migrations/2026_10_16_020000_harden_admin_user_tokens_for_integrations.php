<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_user_tokens')) {
            return;
        }

        $existingColumns = array_flip(Schema::getColumnListing('admin_user_tokens'));

        Schema::table('admin_user_tokens', function (Blueprint $table) use ($existingColumns): void {
            if (! isset($existingColumns['name'])) {
                $table->string('name', 80)->nullable()->after('admin_user_id');
            }
            if (! isset($existingColumns['token_hash'])) {
                $table->char('token_hash', 64)->nullable()->index()->after('token');
            }
            if (! isset($existingColumns['token_hint'])) {
                $table->string('token_hint', 16)->nullable()->after('token_hash');
            }
            if (! isset($existingColumns['abilities'])) {
                $table->json('abilities')->nullable()->after('token_hint');
            }
            if (! isset($existingColumns['last_used_at'])) {
                $table->timestamp('last_used_at')->nullable()->after('abilities');
            }
            if (! isset($existingColumns['expires_at'])) {
                $table->timestamp('expires_at')->nullable()->after('last_used_at');
            }
            if (! isset($existingColumns['revoked_at'])) {
                $table->timestamp('revoked_at')->nullable()->after('expires_at');
            }
        });

        DB::table('admin_user_tokens')
            ->whereNull('token_hash')
            ->orderBy('id')
            ->chunkById(100, function ($tokens): void {
                foreach ($tokens as $token) {
                    $plain = trim((string) $token->token);
                    $hash  = hash('sha256', $plain);

                    DB::table('admin_user_tokens')->where('id', $token->id)->update([
                        // The legacy column remains for upstream schema
                        // compatibility, but no plaintext credential survives.
                        'token'      => $hash,
                        'token_hash' => $hash,
                        'token_hint' => $plain === '' ? null : substr($plain, -8),
                        'name'       => 'Legacy integration token',
                        // Legacy tokens were never scoped for Cloud Mail and
                        // must not silently gain a new sending capability.
                        // Revoke them because their plaintext may have been
                        // displayed, copied or logged by the legacy UI.
                        'abilities'  => json_encode([], JSON_THROW_ON_ERROR),
                        'revoked_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('admin_user_tokens')) {
            return;
        }

        // Plaintext credentials cannot be reconstructed. Deleting the rows is
        // safer than letting the compatibility hash become a usable plaintext
        // token if an operator rolls back to the legacy unscoped code.
        DB::table('admin_user_tokens')->delete();

        $existingColumns = array_flip(Schema::getColumnListing('admin_user_tokens'));

        if (isset($existingColumns['token_hash'])) {
            Schema::table('admin_user_tokens', function (Blueprint $table): void {
                $table->dropIndex(['token_hash']);
            });
        }

        Schema::table('admin_user_tokens', function (Blueprint $table) use ($existingColumns): void {
            $columns = array_values(array_filter([
                'name', 'token_hash', 'token_hint', 'abilities',
                'last_used_at', 'expires_at', 'revoked_at',
            ], static fn (string $column): bool => isset($existingColumns[$column])));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
