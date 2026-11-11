<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin\LongosInstantTranslate\Services\LanguageCatalog;
use Plugin\LongosInstantTranslate\Services\LocaleResolver;

class InstantTranslateLocaleResolverTest extends TestCase
{
    #[DataProvider('countryCases')]
    public function test_verified_country_mappings(string $country, string $expected): void
    {
        $decision = (new LocaleResolver)->decide($country, 'en-US', array_keys(LanguageCatalog::all()));

        self::assertSame($expected, $decision['language']);
        self::assertSame('country', $decision['source']);
    }

    public static function countryCases(): array
    {
        return [
            'Japan'    => ['JP', 'japanese'],
            'Germany'  => ['DE', 'deutsch'],
            'France'   => ['FR', 'french'],
            'Brazil'   => ['BR', 'portuguese'],
            'Taiwan'   => ['TW', 'chinese_traditional'],
            'China'    => ['CN', 'chinese_simplified'],
            'Ecuador'  => ['EC', 'spanish'],
            'Senegal'  => ['SN', 'french'],
            'UAE'      => ['AE', 'arabic'],
            'Malaysia' => ['MY', 'malay'],
        ];
    }

    #[DataProvider('multilingualCases')]
    public function test_multilingual_countries_consider_browser_preferences(string $country, string $header, string $expected): void
    {
        $decision = (new LocaleResolver)->decide($country, $header, array_keys(LanguageCatalog::all()));

        self::assertSame($expected, $decision['language']);
        self::assertSame('country_browser', $decision['source']);
    }

    public static function multilingualCases(): array
    {
        return [
            'French Canada'       => ['CA', 'fr-CA,fr;q=0.9,en;q=0.7', 'french'],
            'Dutch Belgium'       => ['BE', 'nl-BE,fr;q=0.8', 'dutch'],
            'Italian Switzerland' => ['CH', 'it-CH,de;q=0.8', 'italian'],
            'Chinese Singapore'   => ['SG', 'zh-Hans-SG,en;q=0.8', 'chinese_simplified'],
        ];
    }

    public function test_browser_fallback_and_disabled_language_fail_to_english(): void
    {
        $resolver = new LocaleResolver;

        self::assertSame('japanese', $resolver->decide(null, 'ja-JP', ['english', 'japanese'])['language']);
        self::assertSame('english', $resolver->decide('JP', 'ja-JP', ['english'], true, true)['language']);
        self::assertSame('english', $resolver->decide(null, 'fr-FR', ['english', 'french'], true, false)['language']);
    }

    public function test_bcp_47_codes_map_to_xnx3_language_ids(): void
    {
        self::assertSame('japanese', LanguageCatalog::fromBcp47('ja-JP'));
        self::assertSame('chinese_traditional', LanguageCatalog::fromBcp47('zh-Hant-TW'));
        self::assertSame('chinese_simplified', LanguageCatalog::fromBcp47('zh-Hans-CN'));
        self::assertSame('deutsch', LanguageCatalog::fromBcp47('de-DE'));
    }
}
