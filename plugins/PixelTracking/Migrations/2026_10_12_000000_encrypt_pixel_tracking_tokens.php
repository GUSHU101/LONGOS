<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugin\PixelTracking\Services\PixelProfiles;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach (['meta_pixels' => 'meta', 'tiktok_pixels' => 'tiktok'] as $name => $channel) {
            $setting = DB::table('settings')
                ->where('type', 'plugin')
                ->where('space', 'pixel_tracking')
                ->where('name', $name)
                ->first();
            if (! $setting || ! is_string($setting->value)) {
                continue;
            }

            $normalized = PixelProfiles::normalizeForStorage($setting->value, $channel);
            if ($normalized === $setting->value) {
                continue;
            }

            DB::table('settings')->where('id', $setting->id)->update([
                'value'      => $normalized,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Encryption is deliberately not reversed during rollback. Returning
        // secrets to plaintext storage would create a security regression.
    }
};
