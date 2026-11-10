<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separate character-budget providers from rate-only free models and isolate
 * SiliconFlow rolling windows by the credential account that owns them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lgt_provider_accounts')
            && ! Schema::hasColumn('lgt_provider_accounts', 'budget_mode')) {
            Schema::table('lgt_provider_accounts', static function (Blueprint $table): void {
                $table->string('budget_mode', 24)->default('metered')->after('account_tier');
            });
        }

        if (Schema::hasTable('lgt_provider_accounts')) {
            DB::table('lgt_provider_accounts')->where('provider', 'siliconflow')->update([
                'budget_mode' => 'rate_only',
                'updated_at'  => now(),
            ]);
        }

        if (! Schema::hasTable('lgt_siliconflow_rate_account_models')) {
            Schema::create('lgt_siliconflow_rate_account_models', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('provider_account_id');
                $table->string('model', 96);
                $table->unsignedInteger('rpm_cap')->default(1000);
                $table->unsignedInteger('tpm_cap')->default(80000);
                $table->timestamp('cap_window_at')->nullable();
                $table->timestamp('cooldown_until')->nullable();
                $table->unsignedSmallInteger('consecutive_429')->default(0);
                $table->timestamp('last_pruned_at')->nullable();
                $table->timestamps();
                $table->unique(['provider_account_id', 'model'], 'lgt_sf_rate_account_model');
            });
        }

        // Carry an active legacy model cooldown into every account/model pair
        // that made a recent call. The old aggregate table is retained as
        // migration history but is no longer consulted for admission.
        if (Schema::hasTable('lgt_siliconflow_rate_models')
            && Schema::hasTable('lgt_siliconflow_rate_calls')) {
            $pairs = DB::table('lgt_siliconflow_rate_calls')
                ->select(['provider_account_id', 'model'])
                ->where('dispatched_at', '>', now()->subMinutes(10))
                ->distinct()->get();
            foreach ($pairs as $pair) {
                $legacy = DB::table('lgt_siliconflow_rate_models')->where('model', $pair->model)->first();
                DB::table('lgt_siliconflow_rate_account_models')->insertOrIgnore([
                    'provider_account_id' => (int) $pair->provider_account_id,
                    'model'               => (string) $pair->model,
                    'rpm_cap'             => (int) ($legacy->rpm_cap ?? 1000),
                    'tpm_cap'             => (int) ($legacy->tpm_cap ?? 80000),
                    'cap_window_at'       => $legacy->cap_window_at  ?? null,
                    'cooldown_until'      => $legacy->cooldown_until ?? null,
                    'consecutive_429'     => (int) ($legacy->consecutive_429 ?? 0),
                    'last_pruned_at'      => $legacy->last_pruned_at ?? null,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lgt_siliconflow_rate_account_models');
        if (Schema::hasTable('lgt_provider_accounts')
            && Schema::hasColumn('lgt_provider_accounts', 'budget_mode')) {
            Schema::table('lgt_provider_accounts', static function (Blueprint $table): void {
                $table->dropColumn('budget_mode');
            });
        }
    }
};
