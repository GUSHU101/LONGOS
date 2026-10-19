<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('storefront_visits')) {
            return;
        }

        Schema::create('storefront_visits', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('bucket_at')->comment('Start of the visitor hour in the store timezone');
            $table->char('visitor_key', 64)->comment('One-way hash of the storefront session identifier');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('source_key', 32)->default('direct');
            $table->string('source_detail', 190)->nullable();
            $table->string('referer', 1000)->nullable();
            $table->string('landing_path', 1000)->nullable();
            $table->unsignedInteger('page_views')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['bucket_at', 'visitor_key'], 'storefront_visits_bucket_visitor_unique');
            $table->index(['bucket_at', 'source_key'], 'storefront_visits_bucket_source_index');
            $table->index(['bucket_at', 'country_code'], 'storefront_visits_bucket_country_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_visits');
    }
};
