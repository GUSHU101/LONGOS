<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDefaultConnection() === 'tenant') {
            return;
        }

        if (! Schema::hasTable('saas_platform_payment_accounts')) {
            Schema::create('saas_platform_payment_accounts', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique('sppa_uuid_uq');
                $table->string('provider', 24)->index('sppa_provider_idx');
                $table->string('label', 96);
                $table->string('api_mode', 16);
                $table->string('client_id', 512);
                $table->text('secret_ciphertext');
                $table->string('merchant_id', 13)->nullable();
                $table->string('callback_host', 253)->unique('sppa_callback_host_uq');
                $table->boolean('enabled')->default(true)->index('sppa_enabled_idx');
                $table->unsignedTinyInteger('rotation_weight')->default(1);
                $table->unsignedBigInteger('rotation_count')->default(0);
                $table->timestamp('last_selected_at')->nullable()->index('sppa_last_selected_idx');
                $table->string('health_status', 24)->default('unknown')->index('sppa_health_idx');
                $table->unsignedSmallInteger('consecutive_failures')->default(0);
                $table->timestamp('circuit_open_until')->nullable()->index('sppa_circuit_open_idx');
                $table->timestamp('last_health_checked_at')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'client_id'], 'sppa_provider_client_uq');
            });
        }

        // Support a resumable rollout if an earlier release created the
        // registry table before health/circuit fields were introduced.
        $addWeight = ! Schema::hasColumn('saas_platform_payment_accounts', 'rotation_weight');
        $addFailures = ! Schema::hasColumn('saas_platform_payment_accounts', 'consecutive_failures');
        $addCircuit = ! Schema::hasColumn('saas_platform_payment_accounts', 'circuit_open_until');
        if ($addWeight || $addFailures || $addCircuit) {
            Schema::table('saas_platform_payment_accounts', function (Blueprint $table) use ($addWeight, $addFailures, $addCircuit): void {
                if ($addWeight) {
                    $table->unsignedTinyInteger('rotation_weight')->default(1)->after('enabled');
                }
                if ($addFailures) {
                    $table->unsignedSmallInteger('consecutive_failures')->default(0)->after('health_status');
                }
                if ($addCircuit) {
                    $table->timestamp('circuit_open_until')->nullable()->after('consecutive_failures')->index('sppa_circuit_open_idx');
                }
            });
        }

        if (! Schema::hasTable('saas_platform_payment_sessions')) {
            return;
        }

        $addAccount = ! Schema::hasColumn('saas_platform_payment_sessions', 'payment_account_id');
        $addHost    = ! Schema::hasColumn('saas_platform_payment_sessions', 'callback_host');
        if (! $addAccount && ! $addHost) {
            return;
        }

        Schema::table('saas_platform_payment_sessions', function (Blueprint $table) use ($addAccount, $addHost): void {
            if ($addAccount) {
                $table->unsignedBigInteger('payment_account_id')->nullable()->after('locale')->index('spp_account_idx');
            }
            if ($addHost) {
                $table->string('callback_host', 253)->nullable()->after('payment_account_id')->index('spp_callback_host_idx');
            }
        });
    }

    public function down(): void
    {
        if (DB::getDefaultConnection() === 'tenant') {
            return;
        }

        if (Schema::hasTable('saas_platform_payment_sessions')) {
            $dropAccount = Schema::hasColumn('saas_platform_payment_sessions', 'payment_account_id');
            $dropHost    = Schema::hasColumn('saas_platform_payment_sessions', 'callback_host');
            if ($dropAccount || $dropHost) {
                Schema::table('saas_platform_payment_sessions', function (Blueprint $table) use ($dropAccount, $dropHost): void {
                    if ($dropHost) {
                        $table->dropIndex('spp_callback_host_idx');
                        $table->dropColumn('callback_host');
                    }
                    if ($dropAccount) {
                        $table->dropIndex('spp_account_idx');
                        $table->dropColumn('payment_account_id');
                    }
                });
            }
        }

        Schema::dropIfExists('saas_platform_payment_accounts');
    }
};
