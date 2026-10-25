<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugin\Paypal\Services\PaypalSettings;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $settings = DB::table('settings')
            ->where('type', 'plugin')
            ->where('space', 'paypal')
            ->get();

        foreach ($settings as $setting) {
            if (! PaypalSettings::isSecretField((string) $setting->name) || ! is_string($setting->value)) {
                continue;
            }

            $normalized = PaypalSettings::normalizeForStorage([
                (string) $setting->name => $setting->value,
            ]);
            $value = (string) ($normalized[$setting->name] ?? $setting->value);
            if ($value === $setting->value) {
                continue;
            }

            DB::table('settings')->where('id', $setting->id)->update([
                'value'      => $value,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Rollback deliberately keeps credentials encrypted.
    }
};
