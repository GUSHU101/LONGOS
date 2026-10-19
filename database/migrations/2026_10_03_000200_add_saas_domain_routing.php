<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDefaultConnection() === 'tenant' || ! Schema::hasTable('saas_domains')) {
            return;
        }

        if (! Schema::hasColumn('saas_domains', 'routing_behavior')) {
            Schema::table('saas_domains', function (Blueprint $table): void {
                // The primary domain is identified by is_primary. Every
                // secondary domain can either redirect to it or act as an
                // alias, matching Shopify's public domain model.
                $table->string('routing_behavior', 16)
                    ->default('redirect')
                    ->after('is_primary')
                    ->index();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDefaultConnection() === 'tenant'
            || ! Schema::hasTable('saas_domains')
            || ! Schema::hasColumn('saas_domains', 'routing_behavior')) {
            return;
        }

        Schema::table('saas_domains', function (Blueprint $table): void {
            $table->dropIndex(['routing_behavior']);
        });
        Schema::table('saas_domains', function (Blueprint $table): void {
            $table->dropColumn('routing_behavior');
        });
    }
};
