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

        if (Schema::hasTable('saas_platform_payment_sessions')) {
            return;
        }

        Schema::create('saas_platform_payment_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique('spp_uuid_uq');
            $table->char('token_hash', 64)->unique('spp_token_uq');
            $table->foreignId('site_id')->constrained('saas_sites')->cascadeOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->string('order_number', 128);
            $table->decimal('amount', 20, 8);
            $table->char('currency', 3);
            $table->string('locale', 16)->default('en');
            $table->string('status', 24)->default('pending')->index('spp_status_idx');
            $table->string('provider', 24)->nullable()->index('spp_provider_idx');
            $table->string('provider_order_id', 191)->nullable()->index('spp_provider_order_idx');
            $table->string('provider_transaction_id', 191)->nullable()->index('spp_provider_tx_idx');
            $table->json('provider_payload')->nullable();
            $table->timestamp('expires_at')->index('spp_expires_idx');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'order_id'], 'spp_site_order_idx');
            $table->index(['status', 'expires_at'], 'spp_status_exp_idx');
            $table->unique(['provider', 'provider_order_id'], 'saas_platform_pay_provider_order_unique');
            $table->unique(['provider', 'provider_transaction_id'], 'saas_platform_pay_provider_tx_unique');
        });
    }

    public function down(): void
    {
        if (DB::getDefaultConnection() !== 'tenant') {
            Schema::dropIfExists('saas_platform_payment_sessions');
        }
    }
};
