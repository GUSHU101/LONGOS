<?php

use Beike\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Upgrade the original home-only campaign default without overwriting custom modules. */
    public function up(): void
    {
        $setting = Setting::query()
            ->where('type', 'system')
            ->where('space', 'base')
            ->where('name', 'design_setting')
            ->first();

        if (! $setting) {
            return;
        }

        $design = $setting->json ? json_decode((string) $setting->value, true) : null;
        if (! is_array($design) || ! is_array($design['modules'] ?? null)) {
            return;
        }

        $changed = false;
        foreach ($design['modules'] as &$module) {
            if (! is_array($module) || ($module['code'] ?? '') !== 'campaign_offer' || ! is_array($module['content'] ?? null)) {
                continue;
            }

            // "home" was the shipped default. Keep it on the home page and
            // add the product page; explicit product/both choices are intact.
            if (($module['content']['placement'] ?? 'home') === 'home') {
                $module['content']['placement'] = 'both';
                $changed = true;
            }
        }
        unset($module);

        if ($changed) {
            DB::table('settings')->where('id', $setting->id)->update([
                'value'      => json_encode($design, JSON_UNESCAPED_UNICODE),
                'json'       => 1,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Retain the merchant-visible placement selected by this upgrade.
    }
};
