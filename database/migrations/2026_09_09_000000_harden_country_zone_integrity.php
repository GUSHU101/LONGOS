<?php

use Beike\Services\LocationIntegrityService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // These repairs update labels/codes in place and only disable the
        // known invalid legacy entry. No country, province, address or order
        // record is removed.
        app(LocationIntegrityService::class)->repair();

        Schema::table('countries', function (Blueprint $table) {
            $table->index('code', 'countries_code_index');
        });

        Schema::table('zones', function (Blueprint $table) {
            $table->index(['country_id', 'active', 'sort_order'], 'zones_country_active_sort_index');
            $table->index(['country_id', 'code'], 'zones_country_code_index');
        });
    }

    public function down(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->dropIndex('zones_country_active_sort_index');
            $table->dropIndex('zones_country_code_index');
        });

        Schema::table('countries', function (Blueprint $table) {
            $table->dropIndex('countries_code_index');
        });
    }
};
