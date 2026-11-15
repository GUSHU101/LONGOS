<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'longos_product_review_generation_batches';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || $this->hasProductForeignKey()) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->foreign('product_id', $this->productForeignKeyName())
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! $this->hasProductForeignKey()) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->dropForeign(['product_id']);
            } else {
                $table->dropForeign($this->productForeignKeyName());
            }
        });
    }

    private function hasProductForeignKey(): bool
    {
        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === ['product_id']) {
                return true;
            }
        }

        return false;
    }

    private function productForeignKeyName(): string
    {
        $prefix = Schema::getConnection()->getTablePrefix();

        return 'lprgb_' . substr(hash('sha256', $prefix), 0, 12) . '_product_fk';
    }
};
