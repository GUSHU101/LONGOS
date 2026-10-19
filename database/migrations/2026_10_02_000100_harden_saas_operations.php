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

        if (Schema::hasTable('saas_sites')) {
            $addHealthStatus = ! Schema::hasColumn('saas_sites', 'health_status');
            $addHealthCheckedAt = ! Schema::hasColumn('saas_sites', 'health_checked_at');
            Schema::table('saas_sites', function (Blueprint $table) use ($addHealthStatus, $addHealthCheckedAt): void {
                if ($addHealthStatus) {
                    $table->string('health_status', 24)->nullable()->after('status')->index();
                }
                if ($addHealthCheckedAt) {
                    $table->timestamp('health_checked_at')->nullable()->after('provisioned_at');
                }
            });
        }

        if (Schema::hasTable('saas_domains')) {
            $addLastCheckedAt = ! Schema::hasColumn('saas_domains', 'last_checked_at');
            $addVerificationError = ! Schema::hasColumn('saas_domains', 'verification_error');
            Schema::table('saas_domains', function (Blueprint $table) use ($addLastCheckedAt, $addVerificationError): void {
                if ($addLastCheckedAt) {
                    $table->timestamp('last_checked_at')->nullable()->after('verified_at');
                }
                if ($addVerificationError) {
                    $table->string('verification_error', 500)->nullable()->after('last_checked_at');
                }
            });
        }

        if (! Schema::hasTable('saas_site_events')) {
            Schema::create('saas_site_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->nullable()->constrained('saas_sites')->nullOnDelete();
                $table->string('event', 80)->index();
                $table->string('status', 24)->index();
                $table->string('message', 1000)->nullable();
                $table->json('context')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDefaultConnection() === 'tenant') {
            return;
        }

        Schema::dropIfExists('saas_site_events');

        if (Schema::hasTable('saas_domains')) {
            $columns = array_values(array_filter(['last_checked_at', 'verification_error'], static fn (string $column): bool => Schema::hasColumn('saas_domains', $column)));
            Schema::table('saas_domains', function (Blueprint $table) use ($columns): void {
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('saas_sites')) {
            $columns = array_values(array_filter(['health_status', 'health_checked_at'], static fn (string $column): bool => Schema::hasColumn('saas_sites', $column)));
            Schema::table('saas_sites', function (Blueprint $table) use ($columns): void {
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
