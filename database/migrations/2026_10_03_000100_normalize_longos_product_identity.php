<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Normalize only known stock values. Merchant-authored content and custom
     * tenant branding are intentionally left untouched.
     */
    public function up(): void
    {
        if (Schema::hasTable('settings')) {
            $this->replaceStockSetting('logo', ['image/logo.png', '/image/logo.png'], 'image/longos-logo.svg');
            $this->replaceStockSetting('favicon', ['image/favicon.png', '/image/favicon.png'], 'image/longos-favicon.svg');
            $this->replaceStockSetting('meta_title', ['BeikeShop', 'Beikeshop'], 'LONGOS');
            $this->normalizeStockSettingText('meta_description');
            $this->normalizeStockSettingText('meta_keywords');
        }

        if (Schema::hasTable('pages') && Schema::hasColumn('pages', 'author')) {
            DB::table('pages')
                ->whereIn('author', ['BeikeShop', 'Beikeshop', 'beikeshop'])
                ->update(['author' => 'LONGOS']);
        }

        if (Schema::hasTable('page_descriptions')) {
            DB::table('page_descriptions')
                ->whereIn('page_id', [22, 23, 24, 25])
                ->orderBy('id')
                ->get()
                ->each(function (object $description): void {
                    $updates = [];
                    foreach (['title', 'content', 'meta_title', 'meta_description', 'meta_keywords', 'meta_keyword'] as $column) {
                        if (property_exists($description, $column) && is_string($description->{$column})) {
                            $normalized = str_ireplace('BeikeShop', 'LONGOS', $description->{$column});
                            if ($normalized !== $description->{$column}) {
                                $updates[$column] = $normalized;
                            }
                        }
                    }

                    if ($updates !== []) {
                        DB::table('page_descriptions')->where('id', $description->id)->update($updates);
                    }
                });
        }
    }

    public function down(): void
    {
        // Product identity normalization is not reversed because doing so could
        // overwrite tenant content created after this migration was applied.
    }

    private function replaceStockSetting(string $name, array $stockValues, string $replacement): void
    {
        DB::table('settings')
            ->where('type', 'system')
            ->where('space', 'base')
            ->where('name', $name)
            ->whereIn('value', $stockValues)
            ->update(['value' => $replacement]);
    }

    private function normalizeStockSettingText(string $name): void
    {
        DB::table('settings')
            ->where('type', 'system')
            ->where('space', 'base')
            ->where('name', $name)
            ->orderBy('id')
            ->get()
            ->each(function (object $setting): void {
                $normalized = str_ireplace('BeikeShop', 'LONGOS', (string) $setting->value);
                if ($normalized !== $setting->value) {
                    DB::table('settings')->where('id', $setting->id)->update(['value' => $normalized]);
                }
            });
    }
};
