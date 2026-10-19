<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('settings')) {
            $mapping = [
                'brand_name'        => 'store_name',
                'shop_logo'         => 'logo',
                'favicon'           => 'favicon',
                'storefront_preset' => 'storefront_preset',
                'primary_color'     => 'brand_primary_color',
                'accent_color'      => 'brand_accent_color',
                'border_radius'     => 'brand_border_radius',
                'meta_title'        => 'meta_title',
                'meta_description'  => 'meta_description',
            ];
            foreach ($mapping as $legacyName => $nativeName) {
                $legacy = DB::table('settings')
                    ->where('type', 'plugin')->where('space', 'longos_branding')->where('name', $legacyName)
                    ->orderByDesc('id')->first();
                if (! $legacy || trim((string) $legacy->value) === '') {
                    continue;
                }
                $value = $legacyName === 'border_radius'
                    ? (string) min(32, max(0, (int) $legacy->value))
                    : (string) $legacy->value;
                $native = DB::table('settings')
                    ->where('type', 'system')->where('space', 'base')->where('name', $nativeName)
                    ->orderByDesc('id')->first();
                if ($native) {
                    DB::table('settings')->where('id', $native->id)->update(['value' => $value, 'json' => 0, 'updated_at' => now()]);
                } else {
                    DB::table('settings')->insert([
                        'type'  => 'system', 'space' => 'base', 'name' => $nativeName,
                        'value' => $value, 'json' => 0, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            DB::table('settings')->where('type', 'plugin')->whereIn('space', ['longos_branding', 'latest_products', 'bestseller'])->delete();
        }

        if (Schema::hasTable('plugins')) {
            DB::table('plugins')->whereIn('code', ['longos_branding', 'latest_products', 'bestseller'])->delete();
        }
    }

    public function down(): void
    {
        // Native settings and design sections are intentionally retained.
    }
};
