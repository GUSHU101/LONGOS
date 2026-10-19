<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant databases only contain storefront/business tables. The
        // control-plane registry must exist once on the central connection.
        if (DB::getDefaultConnection() === 'tenant') {
            return;
        }

        if (! Schema::hasTable('saas_sites')) {
            Schema::create('saas_sites', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('code', 64)->unique();
                $table->string('name');
                $table->string('database_name')->unique();
                $table->string('status', 24)->default('provisioning')->index();
                $table->string('locale', 16)->default('zh_cn');
                $table->string('timezone', 64)->default('Asia/Shanghai');
                $table->json('meta')->nullable();
                $table->timestamp('provisioned_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('saas_domains')) {
            Schema::create('saas_domains', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained('saas_sites')->cascadeOnDelete();
                $table->string('host')->unique();
                $table->string('scheme', 8)->default('https');
                $table->boolean('is_primary')->default(false)->index();
                $table->string('status', 24)->default('active')->index();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDefaultConnection() === 'tenant') {
            return;
        }

        Schema::dropIfExists('saas_domains');
        Schema::dropIfExists('saas_sites');
    }
};
