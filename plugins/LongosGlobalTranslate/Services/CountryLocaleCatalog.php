<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-local bridge between LONGOS' enabled country catalogue and the
 * translation locales supported by every bundled provider.
 *
 * The catalogue intentionally recommends only a language, never assumes a
 * visitor's personal preference. Visitors can still choose a language, and
 * the country default is applied only through the existing trusted-edge rule.
 */
final class CountryLocaleCatalog
{
    /** @var array<string, string> */
    private const LOCALE_LABELS = [
        'ar'    => '阿拉伯语', 'de' => '德语', 'es' => '西班牙语', 'fr' => '法语',
        'id'    => '印度尼西亚语', 'it' => '意大利语', 'ja' => '日语', 'ko' => '韩语',
        'nl'    => '荷兰语', 'pl' => '波兰语', 'pt' => '葡萄牙语', 'ru' => '俄语',
        'th'    => '泰语', 'tr' => '土耳其语', 'vi' => '越南语', 'zh-cn' => '简体中文',
        'zh-tw' => '繁体中文',
    ];

    /** @var array<string, list<string>> */
    private const COUNTRY_GROUPS = [
        'zh-cn' => ['CN', 'SG'],
        'zh-tw' => ['TW', 'HK', 'MO'],
        'fr'    => ['FR', 'BE', 'CH', 'LU', 'MC', 'CA', 'CI', 'CM', 'SN', 'ML', 'NE', 'BF', 'BJ', 'TG', 'GA', 'CG', 'CD', 'MG', 'HT', 'RE', 'GP', 'MQ', 'PF', 'NC'],
        'de'    => ['DE', 'AT', 'LI'],
        'es'    => ['ES', 'MX', 'AR', 'CO', 'CL', 'PE', 'VE', 'EC', 'GT', 'CU', 'BO', 'DO', 'HN', 'PY', 'SV', 'NI', 'CR', 'PA', 'UY', 'PR', 'GQ'],
        'it'    => ['IT', 'SM', 'VA'],
        'pt'    => ['PT', 'BR', 'AO', 'MZ', 'CV', 'GW', 'ST', 'TL'],
        'nl'    => ['NL', 'SR', 'AW', 'CW', 'SX'],
        'pl'    => ['PL'],
        'ru'    => ['RU', 'BY', 'KZ', 'KG'],
        'ja'    => ['JP'],
        'ko'    => ['KR', 'KP'],
        'ar'    => ['SA', 'AE', 'EG', 'DZ', 'BH', 'IQ', 'JO', 'KW', 'LB', 'LY', 'MA', 'OM', 'PS', 'QA', 'SD', 'SY', 'TN', 'YE', 'MR'],
        'tr'    => ['TR'],
        'vi'    => ['VN'],
        'th'    => ['TH'],
        'id'    => ['ID'],
    ];

    /** @return list<array{code:string,name:string,locale:?string,locale_label:?string}> */
    public function enabledCountries(): array
    {
        if (! Schema::hasTable('countries')) {
            return [];
        }

        $activeColumn = Schema::hasColumn('countries', 'active') ? 'active' : 'status';
        if (! Schema::hasColumn('countries', $activeColumn)) {
            return [];
        }

        return DB::table('countries')
            ->where($activeColumn, true)
            ->whereNotNull('code')
            ->select(['code', 'name'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (object $country): array {
                $code   = strtoupper(trim((string) $country->code));
                $locale = $this->suggestedLocale($code);

                return [
                    'code'         => $code,
                    'name'         => (string) $country->name,
                    'locale'       => $locale,
                    'locale_label' => $locale === null ? null : self::LOCALE_LABELS[$locale],
                ];
            })
            ->filter(static fn (array $country): bool => preg_match('/^[A-Z]{2}$/', $country['code']) === 1)
            ->values()
            ->all();
    }

    /** @return array<string, string> Country code => suggested supported locale. */
    public function suggestionsFor(array $countries): array
    {
        $available   = collect($this->enabledCountries())->keyBy('code');
        $suggestions = [];
        foreach (array_values(array_unique(array_map(static fn (mixed $country): string => strtoupper(trim((string) $country)), $countries))) as $country) {
            $row = $available->get($country);
            if (! is_array($row) || ! is_string($row['locale']) || $row['locale'] === '') {
                continue;
            }
            $suggestions[$country] = $row['locale'];
        }

        return $suggestions;
    }

    public function suggestedLocale(string $country): ?string
    {
        $country = strtoupper(trim($country));
        // Do not force French on English-speaking Canadians or French on
        // Dutch-speaking Belgians. The resolver can use browser hints for
        // these multilingual markets, while an explicit country rule wins.
        if (in_array($country, ['CA', 'BE', 'CH', 'SG', 'HK', 'LU'], true)) {
            return null;
        }
        foreach (self::COUNTRY_GROUPS as $locale => $countries) {
            if (in_array($country, $countries, true)) {
                return $locale;
            }
        }

        return null;
    }

    /** Ordered defaults for markets that cannot be reduced to one language. */
    public function candidateLocales(string $country): array
    {
        return match (strtoupper(trim($country))) {
            'CA'    => ['en', 'fr'],
            'BE'    => ['nl', 'fr', 'de'],
            'CH'    => ['de', 'fr', 'it'],
            'SG'    => ['en', 'zh-cn'],
            'HK'    => ['zh-tw', 'en'],
            'LU'    => ['fr', 'de'],
            default => [],
        };
    }

    public static function label(string $locale): ?string
    {
        return self::LOCALE_LABELS[strtolower($locale)] ?? null;
    }
}
