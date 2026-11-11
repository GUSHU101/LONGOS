<?php

namespace Plugin\LongosInstantTranslate\Services;

final class LanguageCatalog
{
    /** @return array<string, array{native: string, short: string, bcp47: string, dir: string}> */
    public static function all(): array
    {
        return [
            'english'             => ['native' => 'English', 'short' => 'EN', 'bcp47' => 'en', 'dir' => 'ltr'],
            'japanese'            => ['native' => '日本語', 'short' => 'JA', 'bcp47' => 'ja', 'dir' => 'ltr'],
            'deutsch'             => ['native' => 'Deutsch', 'short' => 'DE', 'bcp47' => 'de', 'dir' => 'ltr'],
            'french'              => ['native' => 'Français', 'short' => 'FR', 'bcp47' => 'fr', 'dir' => 'ltr'],
            'spanish'             => ['native' => 'Español', 'short' => 'ES', 'bcp47' => 'es', 'dir' => 'ltr'],
            'italian'             => ['native' => 'Italiano', 'short' => 'IT', 'bcp47' => 'it', 'dir' => 'ltr'],
            'korean'              => ['native' => '한국어', 'short' => 'KO', 'bcp47' => 'ko', 'dir' => 'ltr'],
            'portuguese'          => ['native' => 'Português', 'short' => 'PT', 'bcp47' => 'pt', 'dir' => 'ltr'],
            'polish'              => ['native' => 'Polski', 'short' => 'PL', 'bcp47' => 'pl', 'dir' => 'ltr'],
            'dutch'               => ['native' => 'Nederlands', 'short' => 'NL', 'bcp47' => 'nl', 'dir' => 'ltr'],
            'chinese_traditional' => ['native' => '繁體中文', 'short' => '繁中', 'bcp47' => 'zh-Hant', 'dir' => 'ltr'],
            'chinese_simplified'  => ['native' => '简体中文', 'short' => '简中', 'bcp47' => 'zh-Hans', 'dir' => 'ltr'],
            'arabic'              => ['native' => 'العربية', 'short' => 'AR', 'bcp47' => 'ar', 'dir' => 'rtl'],
            'russian'             => ['native' => 'Русский', 'short' => 'RU', 'bcp47' => 'ru', 'dir' => 'ltr'],
            'vietnamese'          => ['native' => 'Tiếng Việt', 'short' => 'VI', 'bcp47' => 'vi', 'dir' => 'ltr'],
            'indonesian'          => ['native' => 'Bahasa Indonesia', 'short' => 'ID', 'bcp47' => 'id', 'dir' => 'ltr'],
            'malay'               => ['native' => 'Bahasa Melayu', 'short' => 'MS', 'bcp47' => 'ms', 'dir' => 'ltr'],
            'hindi'               => ['native' => 'हिन्दी', 'short' => 'HI', 'bcp47' => 'hi', 'dir' => 'ltr'],
        ];
    }

    /** @param mixed $languages @return list<string> */
    public static function normalize(mixed $languages): array
    {
        $allowed = array_keys(self::all());
        $values  = is_array($languages) ? $languages : [];
        $values  = array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => strtolower(trim((string) $value)), $values),
            static fn (string $value): bool => in_array($value, $allowed, true),
        )));

        if (! in_array('english', $values, true)) {
            array_unshift($values, 'english');
        }

        return $values;
    }

    public static function fromBcp47(string $tag): ?string
    {
        $tag = strtolower(str_replace('_', '-', trim($tag)));
        if ($tag === '') {
            return null;
        }
        if (str_starts_with($tag, 'zh')) {
            return preg_match('/(?:^|-)(hant|tw|hk|mo)(?:-|$)/', $tag) === 1
                ? 'chinese_traditional'
                : 'chinese_simplified';
        }

        return match (explode('-', $tag, 2)[0]) {
            'en'    => 'english',
            'ja'    => 'japanese',
            'de'    => 'deutsch',
            'fr'    => 'french',
            'es'    => 'spanish',
            'it'    => 'italian',
            'ko'    => 'korean',
            'pt'    => 'portuguese',
            'pl'    => 'polish',
            'nl'    => 'dutch',
            'ar'    => 'arabic',
            'ru'    => 'russian',
            'vi'    => 'vietnamese',
            'id'    => 'indonesian',
            'ms'    => 'malay',
            'hi'    => 'hindi',
            default => null,
        };
    }
}
