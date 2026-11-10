<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One SiliconFlow RPM/TPM budget for a remote billing identity across tenants.
 * This is control-plane state, never a tenant-local quota or credential store.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDefaultConnection() === 'tenant') {
            return;
        }

        $schema = Schema::connection('saas');
        if (! $schema->hasTable('saas_translation_rate_accounts')) {
            $schema->create('saas_translation_rate_accounts', static function (Blueprint $table): void {
                $table->id();
                $table->char('billing_identity_hash', 64);
                $table->string('model', 96);
                $table->unsignedInteger('rpm_cap');
                $table->unsignedInteger('tpm_cap');
                $table->timestamp('cap_window_at')->nullable();
                $table->timestamp('cooldown_until')->nullable();
                $table->unsignedSmallInteger('consecutive_429')->default(0);
                $table->timestamp('last_pruned_at')->nullable();
                $table->timestamps();
                $table->unique(['billing_identity_hash', 'model'], 'saas_translate_identity_model');
            });
        }
        if (! $schema->hasTable('saas_translation_rate_calls')) {
            $schema->create('saas_translation_rate_calls', static function (Blueprint $table): void {
                $table->id();
                $table->char('billing_identity_hash', 64);
                $table->string('model', 96);
                $table->unsignedInteger('reserved_tokens');
                $table->unsignedInteger('counted_tokens');
                $table->timestamp('dispatched_at');
                $table->timestamps();
                $table->index(['billing_identity_hash', 'model', 'dispatched_at'], 'saas_translate_calls_window');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDefaultConnection() === 'tenant') {
            return;
        }

        $schema = Schema::connection('saas');
        $schema->dropIfExists('saas_translation_rate_calls');
        $schema->dropIfExists('saas_translation_rate_accounts');
    }
};
