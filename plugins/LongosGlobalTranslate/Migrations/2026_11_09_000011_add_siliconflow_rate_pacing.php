<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tenant-local, cross-worker, rolling-minute model budget for SiliconFlow. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lgt_provider_accounts')) {
            $needsRpm = ! Schema::hasColumn('lgt_provider_accounts', 'rpm_limit');
            $needsTpm = ! Schema::hasColumn('lgt_provider_accounts', 'tpm_limit');
            if ($needsRpm || $needsTpm) {
                Schema::table('lgt_provider_accounts', static function (Blueprint $table) use ($needsRpm, $needsTpm): void {
                    if ($needsRpm) {
                        $table->unsignedInteger('rpm_limit')->default(1000);
                    }
                    if ($needsTpm) {
                        $table->unsignedInteger('tpm_limit')->default(80000);
                    }
                });
            }
        }
        if (! Schema::hasTable('lgt_siliconflow_rate_models')) {
            Schema::create('lgt_siliconflow_rate_models', function (Blueprint $table): void {
                $table->id();
                $table->string('model', 96)->unique('lgt_sf_rate_model');
                $table->unsignedInteger('rpm_cap')->default(1000);
                $table->unsignedInteger('tpm_cap')->default(80000);
                $table->timestamp('cap_window_at')->nullable();
                $table->timestamp('cooldown_until')->nullable();
                $table->unsignedSmallInteger('consecutive_429')->default(0);
                $table->timestamp('last_pruned_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('lgt_siliconflow_rate_calls')) {
            Schema::create('lgt_siliconflow_rate_calls', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('provider_account_id');
                $table->string('model', 96);
                $table->unsignedInteger('reserved_tokens');
                $table->unsignedInteger('counted_tokens');
                $table->timestamp('dispatched_at');
                $table->timestamps();
                $table->index(['model', 'dispatched_at'], 'lgt_sf_calls_model_time');
                $table->index(['provider_account_id', 'dispatched_at'], 'lgt_sf_calls_account_time');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lgt_siliconflow_rate_calls');
        Schema::dropIfExists('lgt_siliconflow_rate_models');
        if (Schema::hasTable('lgt_provider_accounts')) {
            foreach (['rpm_limit', 'tpm_limit'] as $column) {
                if (Schema::hasColumn('lgt_provider_accounts', $column)) {
                    Schema::table('lgt_provider_accounts', static function (Blueprint $table) use ($column): void {
                        $table->dropColumn($column);
                    });
                }
            }
        }
    }
};
