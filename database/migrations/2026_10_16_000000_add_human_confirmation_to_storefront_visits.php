<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('storefront_visits')) {
            return;
        }

        Schema::table('storefront_visits', function (Blueprint $table): void {
            if (! Schema::hasColumn('storefront_visits', 'human_confirmed_at')) {
                $table->timestamp('human_confirmed_at')->nullable()->after('last_seen_at');
            }
            if (! Schema::hasColumn('storefront_visits', 'confirmation_method')) {
                $table->string('confirmation_method', 16)->nullable()->after('human_confirmed_at');
            }
        });

        if (! Schema::hasIndex('storefront_visits', 'storefront_visits_confirmed_bucket_index')) {
            Schema::table('storefront_visits', function (Blueprint $table): void {
                $table->index(['human_confirmed_at', 'bucket_at'], 'storefront_visits_confirmed_bucket_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('storefront_visits')) {
            return;
        }

        if (Schema::hasIndex('storefront_visits', 'storefront_visits_confirmed_bucket_index')) {
            Schema::table('storefront_visits', function (Blueprint $table): void {
                $table->dropIndex('storefront_visits_confirmed_bucket_index');
            });
        }

        Schema::table('storefront_visits', function (Blueprint $table): void {
            if (Schema::hasColumn('storefront_visits', 'confirmation_method')) {
                $table->dropColumn('confirmation_method');
            }
            if (Schema::hasColumn('storefront_visits', 'human_confirmed_at')) {
                $table->dropColumn('human_confirmed_at');
            }
        });
    }
};
