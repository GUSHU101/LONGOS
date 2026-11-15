<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('longos_product_reviews')) {
            return;
        }

        Schema::table('longos_product_reviews', function (Blueprint $table): void {
            if (! Schema::hasColumn('longos_product_reviews', 'content_source')) {
                $table->string('content_source', 24)->default('manual')->index();
            }
            if (! Schema::hasColumn('longos_product_reviews', 'generation_batch_id')) {
                $table->uuid('generation_batch_id')->nullable()->index();
            }
            if (! Schema::hasColumn('longos_product_reviews', 'staff_reviewed')) {
                $table->boolean('staff_reviewed')->default(true)->index();
            }
        });

        if (! Schema::hasTable('longos_product_review_generation_batches')) {
            Schema::create('longos_product_review_generation_batches', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('admin_user_id')->index('lprgb_admin_idx');
                $table->unsignedBigInteger('product_id')->index('lprgb_product_idx');
                $table->string('status', 24)->index('lprgb_status_idx');
                $table->string('model', 160);
                $table->unsignedSmallInteger('requested_count');
                $table->unsignedSmallInteger('processed_count')->default(0);
                $table->longText('request_ciphertext');
                $table->longText('result_ciphertext')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('expires_at')->index('lprgb_expires_idx');
                $table->timestamps();

                $table->index(['admin_user_id', 'created_at'], 'longos_review_gen_owner_index');
                // Shared-database tenants prepend a table prefix.
                // Laravel's default constraint name then exceeds MySQL's
                // 64-character identifier limit, so use a compact tenant-unique
                // name derived from that prefix.
                $table->foreign('product_id', $this->productForeignKeyName())
                    ->references('id')
                    ->on('products')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('longos_product_review_generation_batches');

        if (! Schema::hasTable('longos_product_reviews')) {
            return;
        }

        Schema::table('longos_product_reviews', function (Blueprint $table): void {
            if (Schema::hasColumn('longos_product_reviews', 'staff_reviewed')) {
                $table->dropColumn('staff_reviewed');
            }
            if (Schema::hasColumn('longos_product_reviews', 'generation_batch_id')) {
                $table->dropColumn('generation_batch_id');
            }
            if (Schema::hasColumn('longos_product_reviews', 'content_source')) {
                $table->dropColumn('content_source');
            }
        });
    }

    private function productForeignKeyName(): string
    {
        $prefix = Schema::getConnection()->getTablePrefix();

        return 'lprgb_' . substr(hash('sha256', $prefix), 0, 12) . '_product_fk';
    }
};
