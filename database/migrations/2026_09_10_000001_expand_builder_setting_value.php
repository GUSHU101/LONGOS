<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Builder drafts and bounded publish history can legitimately exceed the
     * 64 KiB limit of MySQL TEXT. PostgreSQL and SQLite keep their native text
     * semantics while MySQL receives MEDIUMTEXT capacity.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->mediumText('value')->change();
        });
    }

    public function down(): void
    {
        // Do not silently truncate an existing visual-builder draft/history on
        // rollback. MEDIUMTEXT is backward-compatible with all former values.
    }
};
