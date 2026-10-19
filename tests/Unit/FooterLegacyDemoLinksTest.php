<?php

namespace Tests\Unit;

use Beike\Repositories\FooterRepo;
use PHPUnit\Framework\TestCase;

class FooterLegacyDemoLinksTest extends TestCase
{
    public function test_new_store_seed_does_not_restore_the_demo_other_group(): void
    {
        $seeder = (string) file_get_contents(dirname(__DIR__, 2) . '/database/seeders/ThemeSeeder.php');

        self::assertStringNotContainsString("'Other'", $seeder);
        self::assertStringNotContainsString("'value' => 20", $this->footerSection($seeder));
    }

    public function test_exact_legacy_other_group_is_removed_for_existing_stores(): void
    {
        $footer = FooterRepo::normalizeFooterSetting([
            'content' => [
                'link3' => $this->legacyGroup(),
            ],
        ]);

        self::assertSame([], $footer['content']['link3']['title']);
        self::assertSame([], $footer['content']['link3']['links']);
    }

    public function test_merchant_customized_third_group_is_preserved(): void
    {
        $group                     = $this->legacyGroup();
        $group['links'][0]['text'] = ['en' => 'Our designers'];

        $footer = FooterRepo::normalizeFooterSetting(['content' => ['link3' => $group]]);

        self::assertSame('Other', $footer['content']['link3']['title']['en']);
        self::assertSame('Our designers', $footer['content']['link3']['links'][0]['text']['en']);
    }

    public function test_exact_legacy_service_placeholders_are_upgraded(): void
    {
        $footer = FooterRepo::normalizeFooterSetting([
            'services' => [
                'enable' => true,
                'items'  => array_map(
                    static fn (string $title): array => ['title' => ['en' => $title], 'sub_title' => [], 'image' => ''],
                    ['With reduced activity', 'Delicate service', 'Material world', 'Return all']
                ),
            ],
        ]);

        self::assertCount(3, $footer['services']['items']);
        self::assertSame('Secure checkout', $footer['services']['items'][0]['title']['en']);
        self::assertSame('Tracked delivery', $footer['services']['items'][2]['title']['en']);
    }

    public function test_merchant_customized_service_items_are_preserved(): void
    {
        $footer = FooterRepo::normalizeFooterSetting([
            'services' => [
                'enable' => true,
                'items'  => [['title' => ['en' => 'Free engraving'], 'sub_title' => ['en' => 'On eligible products']]],
            ],
        ]);

        self::assertCount(1, $footer['services']['items']);
        self::assertSame('Free engraving', $footer['services']['items'][0]['title']['en']);
    }

    private function legacyGroup(): array
    {
        return [
            'title' => ['en' => 'Other', 'zh_cn' => '其他'],
            'links' => [
                ['type' => 'static', 'value' => 'brands.index', 'text' => []],
                ['type' => 'static', 'value' => 'account.index', 'text' => []],
                ['type' => 'page', 'value' => 20, 'text' => []],
                ['type' => 'page', 'value' => 21, 'text' => []],
            ],
        ];
    }

    private function footerSection(string $seeder): string
    {
        $start = strpos($seeder, 'private function getFooterSetting');
        $end   = strpos($seeder, 'private function getHeaderSetting');

        return $start === false ? '' : substr($seeder, $start, $end === false ? null : $end - $start);
    }
}
