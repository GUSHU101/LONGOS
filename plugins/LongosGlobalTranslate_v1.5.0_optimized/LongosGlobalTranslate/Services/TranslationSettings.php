<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Tenant-local, non-secret presentation settings. Provider credentials live
 * only in lgt_provider_accounts and are never returned by this class.
 */
final class TranslationSettings
{
    public const MODE_PREVIEW = 'preview_only';

    public const MODE_FREE_ONLY = 'free_only';

    public const MODE_OFFLINE = 'offline_dictionary';

    /** @return array<string, mixed> */
    public function all(): array
    {
        $defaults = [
            'mode'                    => self::MODE_PREVIEW,
            'source_locale'           => 'en',
            'enabled_locales'         => [],
            'accept_language_enabled' => false,
            'widget'                  => [
                'enabled'              => false,
                'position'             => 'right',
                'bottom_offset'        => 84,
                'mobile_bottom_offset' => 88,
                'theme'                => 'system',
            ],
        ];
        if (! Schema::hasTable('lgt_settings')) {
            return $defaults;
        }

        foreach (DB::table('lgt_settings')->get(['key', 'value']) as $row) {
            $value = json_decode((string) $row->value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $defaults[$row->key] = $value;
            }
        }

        try {
            return $this->validate($defaults);
        } catch (InvalidArgumentException) {
            // Settings are operator-editable persistent data.  An invalid
            // value left by an interrupted preview upgrade must not make the
            // optional plugin (or the whole admin page) unavailable.  Return
            // conservative, source-English defaults; the next successful
            // save writes a normalized revision back to the tenant database.
            return $this->validate([
                'mode'                    => self::MODE_PREVIEW,
                'source_locale'           => 'en',
                'enabled_locales'         => [],
                'accept_language_enabled' => false,
                'widget'                  => ['enabled' => false, 'position' => 'right', 'bottom_offset' => 84, 'mobile_bottom_offset' => 88, 'theme' => 'system'],
            ]);
        }
    }

    /** @param array<string, mixed> $settings */
    public function save(array $settings): array
    {
        $settings = $this->validate([...$this->all(), ...$settings]);
        // Save all settings as one logical revision. A failure halfway
        // through used to leave enabled locales, mode and widget flags out
        // of sync; that made a published library look broken on the front end.
        DB::transaction(function () use ($settings): void {
            foreach ($settings as $key => $value) {
                $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $existing = DB::table('lgt_settings')->where('key', $key)->lockForUpdate()->first();
                if ($existing === null) {
                    DB::table('lgt_settings')->insert([
                        'key'        => $key,
                        'value'      => $encoded,
                        'revision'   => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('lgt_settings')->where('id', $existing->id)->update([
                        'value'      => $encoded,
                        'revision'   => (int) $existing->revision + 1,
                        'updated_at' => now(),
                    ]);
                }
            }
        }, 3);

        return $settings;
    }

    /** @param array<string, mixed> $settings
     *  @return array<string, mixed>
     */
    private function validate(array $settings): array
    {
        $mode = (string) ($settings['mode'] ?? self::MODE_PREVIEW);
        if (! in_array($mode, [self::MODE_PREVIEW, self::MODE_FREE_ONLY, self::MODE_OFFLINE], true)) {
            throw new InvalidArgumentException('INVALID_TRANSLATION_MODE');
        }
        $sourceLocale = self::locale((string) ($settings['source_locale'] ?? 'en'));
        if ($sourceLocale !== 'en') {
            // V1 is a presentation layer over LONGOS' locked English source.
            // Supporting another authoring source needs an explicit new data
            // contract; accepting it here would make scanner and storefront
            // hashes disagree silently.
            throw new InvalidArgumentException('SOURCE_LOCALE_LOCKED');
        }
        $locales      = array_values(array_unique(array_filter(
            array_map(static fn (mixed $locale): ?string => is_string($locale) ? self::locale($locale) : null, (array) ($settings['enabled_locales'] ?? [])),
        )));
        if (count($locales) > 24) {
            throw new InvalidArgumentException('TOO_MANY_LOCALES');
        }
        $widget   = is_array($settings['widget'] ?? null) ? $settings['widget'] : [];
        $position = (string) ($widget['position'] ?? 'right');
        if (! in_array($position, ['left', 'right'], true)) {
            throw new InvalidArgumentException('INVALID_WIDGET_POSITION');
        }
        $theme = (string) ($widget['theme'] ?? 'system');
        if (! in_array($theme, ['system', 'light', 'dark'], true)) {
            throw new InvalidArgumentException('INVALID_WIDGET_THEME');
        }

        return [
            'mode'                    => $mode,
            'source_locale'           => $sourceLocale,
            'enabled_locales'         => array_values(array_filter($locales, static fn (string $locale): bool => $locale !== $sourceLocale)),
            'accept_language_enabled' => (bool) ($settings['accept_language_enabled'] ?? false),
            'widget'                  => [
                'enabled'              => (bool) ($widget['enabled'] ?? false),
                'position'             => $position,
                'bottom_offset'        => min(240, max(16, (int) ($widget['bottom_offset'] ?? 84))),
                'mobile_bottom_offset' => min(260, max(16, (int) ($widget['mobile_bottom_offset'] ?? 88))),
                'theme'                => $theme,
            ],
        ];
    }

    public static function locale(string $locale): string
    {
        $locale = str_replace('_', '-', trim($locale));
        if (preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8}){0,2}$/', $locale) !== 1) {
            throw new InvalidArgumentException('INVALID_LOCALE');
        }

        return strtolower($locale);
    }
}
