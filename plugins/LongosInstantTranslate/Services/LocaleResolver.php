<?php

namespace Plugin\LongosInstantTranslate\Services;

final class LocaleResolver
{
    /** @var array<string, string> */
    private const COUNTRY_LANGUAGE = [
        'JP' => 'japanese', 'DE' => 'deutsch', 'AT' => 'deutsch', 'FR' => 'french',
        'ES' => 'spanish', 'MX' => 'spanish', 'AR' => 'spanish', 'CL' => 'spanish',
        'CO' => 'spanish', 'PE' => 'spanish', 'IT' => 'italian', 'KR' => 'korean',
        'BR' => 'portuguese', 'PT' => 'portuguese', 'PL' => 'polish', 'NL' => 'dutch',
        'TW' => 'chinese_traditional', 'HK' => 'chinese_traditional', 'MO' => 'chinese_traditional',
        'CN' => 'chinese_simplified', 'RU' => 'russian', 'VN' => 'vietnamese',
        'ID' => 'indonesian', 'SA' => 'arabic', 'AE' => 'arabic', 'EG' => 'arabic',
        'US' => 'english', 'GB' => 'english', 'AU' => 'english', 'NZ' => 'english', 'IE' => 'english',
    ];

    /** @var array<string, list<string>> */
    private const MULTILINGUAL_COUNTRIES = [
        'CA' => ['english', 'french'],
        'BE' => ['dutch', 'french', 'deutsch'],
        'CH' => ['deutsch', 'french', 'italian'],
        'SG' => ['english', 'chinese_simplified', 'malay'],
        'LU' => ['french', 'deutsch'],
        'IN' => ['english', 'hindi'],
    ];

    /**
     * @param string|list<string>|null $browserLanguages
     * @param list<string>             $enabledLanguages
     * @return array{language: string, source: string}
     */
    public function decide(
        ?string $country,
        string|array|null $browserLanguages,
        array $enabledLanguages,
        bool $autoCountry = true,
        bool $browserFallback = true,
    ): array {
        $enabled = LanguageCatalog::normalize($enabledLanguages);
        $browser = $this->browserTags($browserLanguages);
        $country = $this->normalizeCountry($country);

        if ($autoCountry && $country !== null) {
            $official = self::MULTILINGUAL_COUNTRIES[$country] ?? null;
            if ($official !== null) {
                $match = $this->browserMatch($browser, $enabled, $official);
                if ($match !== null) {
                    return ['language' => $match, 'source' => 'country_browser'];
                }
                foreach ($official as $language) {
                    if (in_array($language, $enabled, true)) {
                        return ['language' => $language, 'source' => 'country'];
                    }
                }
            }

            $mapped = self::COUNTRY_LANGUAGE[$country] ?? null;
            if ($mapped !== null && in_array($mapped, $enabled, true)) {
                return ['language' => $mapped, 'source' => 'country'];
            }
        }

        if ($browserFallback) {
            $match = $this->browserMatch($browser, $enabled);
            if ($match !== null) {
                return ['language' => $match, 'source' => 'browser'];
            }
        }

        return ['language' => 'english', 'source' => 'source'];
    }

    /** @param string|list<string>|null $languages @return list<string> */
    public function browserTags(string|array|null $languages): array
    {
        if (is_array($languages)) {
            return array_values(array_filter(array_map('strval', $languages)));
        }
        if (! is_string($languages) || trim($languages) === '') {
            return [];
        }

        $weighted = [];
        foreach (explode(',', $languages) as $index => $part) {
            [$tag, $parameters] = array_pad(explode(';', trim($part), 2), 2, '');
            $quality            = 1.0;
            if (preg_match('/(?:^|;)\s*q=([01](?:\.\d{1,3})?)/i', $parameters, $match) === 1) {
                $quality = (float) $match[1];
            }
            if ($quality > 0 && $tag !== '*') {
                $weighted[] = ['tag' => $tag, 'quality' => $quality, 'index' => $index];
            }
        }
        usort($weighted, static fn (array $left, array $right): int => ($right['quality'] <=> $left['quality']) ?: ($left['index'] <=> $right['index'])
        );

        return array_values(array_map(static fn (array $item): string => $item['tag'], $weighted));
    }

    /** @param list<string> $tags @param list<string> $enabled @param list<string>|null $restricted */
    private function browserMatch(array $tags, array $enabled, ?array $restricted = null): ?string
    {
        foreach ($tags as $tag) {
            $language = LanguageCatalog::fromBcp47($tag);
            if ($language !== null
                && in_array($language, $enabled, true)
                && ($restricted === null || in_array($language, $restricted, true))) {
                return $language;
            }
        }

        return null;
    }

    private function normalizeCountry(?string $country): ?string
    {
        $country = strtoupper(trim((string) $country));

        return preg_match('/^[A-Z]{2}$/', $country) === 1
            && ! in_array($country, ['XX', 'EU', 'AP', 'ZZ'], true)
            ? $country
            : null;
    }
}
