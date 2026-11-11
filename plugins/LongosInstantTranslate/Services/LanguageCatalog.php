<?php

namespace Plugin\LongosInstantTranslate\Services;

final class LanguageCatalog
{
    /** @return array<string, array{native: string, short: string}> */
    public static function all(): array
    {
        return [
            'english'             => ['native' => 'English', 'short' => 'EN'],
            'japanese'            => ['native' => '日本語', 'short' => 'JA'],
            'deutsch'             => ['native' => 'Deutsch', 'short' => 'DE'],
            'french'              => ['native' => 'Français', 'short' => 'FR'],
            'spanish'             => ['native' => 'Español', 'short' => 'ES'],
            'italian'             => ['native' => 'Italiano', 'short' => 'IT'],
            'korean'              => ['native' => '한국어', 'short' => 'KO'],
            'portuguese'          => ['native' => 'Português', 'short' => 'PT'],
            'polish'              => ['native' => 'Polski', 'short' => 'PL'],
            'dutch'               => ['native' => 'Nederlands', 'short' => 'NL'],
            'chinese_traditional' => ['native' => '繁體中文', 'short' => '繁中'],
            'chinese_simplified'  => ['native' => '简体中文', 'short' => '简中'],
            'arabic'              => ['native' => 'العربية', 'short' => 'AR'],
            'russian'             => ['native' => 'Русский', 'short' => 'RU'],
            'vietnamese'          => ['native' => 'Tiếng Việt', 'short' => 'VI'],
            'indonesian'          => ['native' => 'Bahasa Indonesia', 'short' => 'ID'],
            'malay'               => ['native' => 'Bahasa Melayu', 'short' => 'MS'],
            'hindi'               => ['native' => 'हिन्दी', 'short' => 'HI'],
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
