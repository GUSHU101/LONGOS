<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('settings')) {
            DB::table('settings')
                ->where('type', 'system')
                ->where('space', 'base')
                ->where('name', 'return_url')
                ->delete();
        }

        $permissionsTable = config('permission.table_names.permissions', 'permissions');
        if (is_string($permissionsTable) && Schema::hasTable($permissionsTable)) {
            // Related model/role assignments use cascading foreign keys.
            DB::table($permissionsTable)->where('name', 'help_index')->delete();
        }
    }

    public function down(): void
    {
        // Both records were application residue, not user-authored settings.
    }
};
