<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $english = DB::table('languages')->where('code', 'en');
        if ($english->exists()) {
            $english->update([
                'name'       => 'English',
                'locale'     => 'en',
                'active'     => true,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('languages')->insert([
                'name'       => 'English',
                'code'       => 'en',
                'locale'     => 'en',
                'image'      => '',
                'sort_order' => 1,
                'active'     => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Preserve historical descriptions, but deactivate their language
        // records so no runtime caller can expose them as selectable locales.
        DB::table('languages')->where('code', '!=', 'en')->update([
            'active'     => false,
            'updated_at' => now(),
        ]);

        $this->storeSetting('locale', 'en');
        $this->storeSetting('admin_locale', 'zh_cn');
    }

    public function down(): void
    {
        // Locale locking is intentionally irreversible. Existing translated
        // descriptions are preserved in the database for safe archival.
    }

    private function storeSetting(string $name, string $value): void
    {
        $query = DB::table('settings')
            ->where('type', 'system')
            ->where('space', 'base')
            ->where('name', $name);

        if ($query->exists()) {
            $query->update(['value' => $value, 'json' => false, 'updated_at' => now()]);

            return;
        }

        DB::table('settings')->insert([
            'type'       => 'system',
            'space'      => 'base',
            'name'       => $name,
            'value'      => $value,
            'json'       => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
