<?php

use Beike\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $setting = Setting::query()
            ->where('type', 'system')
            ->where('space', 'base')
            ->where('name', 'footer_setting')
            ->first();

        if (! $setting) {
            return;
        }

        $footer = $setting->json ? json_decode((string) $setting->value, true) : null;
        if (! is_array($footer)) {
            return;
        }

        $changed = false;
        if (! isset($footer['content']) || ! is_array($footer['content'])) {
            return;
        }

        if (! isset($footer['content']['intro']) || ! is_array($footer['content']['intro'])) {
            $footer['content']['intro'] = [];
        }
        $intro = &$footer['content']['intro']['text'];
        if (is_array($intro)) {
            foreach ($intro as $locale => $text) {
                if (! is_string($text)) {
                    continue;
                }

                if (str_contains($text, 'Chengdu Guangda Network Technology') || str_contains($text, 'LONGOS')) {
                    $intro[$locale] = $locale === 'zh_cn'
                        ? '<p><strong>LONGOS</strong></p><p>打造可靠、灵活且面向全球客户的品牌体验。</p>'
                        : '<p><strong>LONGOS</strong></p><p>Building reliable, flexible commerce experiences for global customers.</p>';
                    $changed = true;
                }
            }
        }

        if (! isset($footer['content']['contact']) || ! is_array($footer['content']['contact'])) {
            $footer['content']['contact'] = [];
        }
        $contact = &$footer['content']['contact'];
        if (is_array($contact)) {
            if (($contact['telephone'] ?? '') === '028-xxxxxxxx') {
                $contact['telephone'] = '';
                $changed = true;
            }
            if (is_array($contact['address'] ?? null)) {
                foreach ($contact['address'] as $locale => $address) {
                    if (in_array($address, ['Your company address', '您的公司地址'], true)) {
                        $contact['address'][$locale] = '';
                        $changed = true;
                    }
                }
            }

            $sampleEmail = Setting::query()
                ->where('type', 'system')
                ->where('space', 'base')
                ->where('name', 'email')
                ->value('value');
            if ($sampleEmail === 'support@example.com' && ($contact['email'] ?? false)) {
                // Do not display a placeholder contact address publicly. Keep
                // the system setting intact so order notifications are not
                // unexpectedly discarded before the merchant enters an email.
                $contact['email'] = false;
                $changed = true;
            }
        }

        if (! $changed) {
            return;
        }

        DB::table('settings')
            ->where('id', $setting->id)
            ->update([
                'value'      => json_encode($footer, JSON_UNESCAPED_UNICODE),
                'json'       => 1,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // This migration intentionally does not restore removed demo content.
    }
};
