<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDefaultConnection() === 'tenant' || Schema::hasTable('saas_idempotency_records')) {
            return;
        }

        Schema::create('saas_idempotency_records', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 80);
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->string('status', 16)->default('processing')->index();
            $table->unsignedSmallInteger('response_status')->nullable();
            // Batch responses may contain one-time generated credentials.
            // The service encrypts the complete response with APP_KEY.
            $table->longText('response_ciphertext')->nullable();
            $table->uuid('lock_token')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['scope', 'idempotency_key'], 'saas_idempotency_scope_key_unique');
        });
    }

    public function down(): void
    {
        if (DB::getDefaultConnection() === 'tenant') {
            return;
        }

        Schema::dropIfExists('saas_idempotency_records');
    }
};
