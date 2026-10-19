<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shopify_product_imports')) {
            $columns = [
                'admin_user_id', 'original_name', 'file_path', 'replace_existing', 'status',
                'processed', 'total', 'result', 'error', 'started_at', 'finished_at',
                'created_at', 'updated_at',
            ];
            if (collect($columns)->contains(fn (string $column): bool => ! Schema::hasColumn('shopify_product_imports', $column))) {
                Schema::table('shopify_product_imports', function (Blueprint $table): void {
                    if (! Schema::hasColumn('shopify_product_imports', 'admin_user_id')) {
                        $table->unsignedBigInteger('admin_user_id')->nullable()->index();
                    }
                    if (! Schema::hasColumn('shopify_product_imports', 'original_name')) {
                        $table->string('original_name')->nullable();
                    }
                    if (! Schema::hasColumn('shopify_product_imports', 'file_path')) {
                        $table->string('file_path', 500)->nullable();
                    }
                    if (! Schema::hasColumn('shopify_product_imports', 'replace_existing')) {
                        $table->boolean('replace_existing')->default(false);
                    }
                    if (! Schema::hasColumn('shopify_product_imports', 'status')) {
                        $table->string('status', 24)->default('failed')->index();
                    }
                    if (! Schema::hasColumn('shopify_product_imports', 'processed')) {
                        $table->unsignedInteger('processed')->default(0);
                    }
                    if (! Schema::hasColumn('shopify_product_imports', 'total')) {
                        $table->unsignedInteger('total')->default(0);
                    }
                    if (! Schema::hasColumn('shopify_product_imports', 'result')) {
                        $table->json('result')->nullable();
                    }
                    if (! Schema::hasColumn('shopify_product_imports', 'error')) {
                        $table->text('error')->nullable();
                    }
                    if (! Schema::hasColumn('shopify_product_imports', 'started_at')) {
                        $table->timestamp('started_at')->nullable();
                    }
                    if (! Schema::hasColumn('shopify_product_imports', 'finished_at')) {
                        $table->timestamp('finished_at')->nullable();
                    }
                    if (! Schema::hasColumn('shopify_product_imports', 'created_at')) {
                        $table->timestamp('created_at')->nullable();
                    }
                    if (! Schema::hasColumn('shopify_product_imports', 'updated_at')) {
                        $table->timestamp('updated_at')->nullable();
                    }
                });
            }

            return;
        }

        Schema::create('shopify_product_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('admin_user_id')->nullable()->index();
            $table->string('original_name');
            $table->string('file_path', 500);
            $table->boolean('replace_existing')->default(false);
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_product_imports');
    }
};
