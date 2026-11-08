<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin\LongosGlobalTranslate\Services\ContentScanner;

class GlobalTranslationBuilderMarkerTest extends TestCase
{
    #[DataProvider('supportedBuilderViews')]
    public function test_supported_builder_view_has_exact_field_markers(string $view): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/themes/default/design/' . self::viewPath($view));

        self::assertIsString($contents);
        self::assertStringContainsString('data-lgt-field=', $contents);
    }

    /** @return iterable<string, array{string}> */
    public static function supportedBuilderViews(): iterable
    {
        foreach ([
            'promo_bar', 'faq', 'trust_cards', 'testimonials', 'campaign_offer',
            'payment_trust', 'story_block', 'feature_grid', 'social_proof',
            'img_text_slideshow', 'img_text_slideshow_2', 'icons', 'img_text_banner',
            'img_text_banner_multiple', 'latest_products', 'bestseller', 'tab_product',
        ] as $view) {
            yield $view => [$view];
        }
    }

    public function test_rich_html_builder_component_uses_only_text_node_replacement(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/themes/default/design/rich_text.blade.php');

        self::assertIsString($contents);
        self::assertStringNotContainsString('data-lgt-root', $contents);
        self::assertStringNotContainsString('data-lgt-field=', $contents);
        self::assertStringContainsString('data-lgt-richtext="module.{{ $module_id }}.data"', $contents);
    }

    public function test_every_scannable_builder_component_has_a_safe_public_render_path(): void
    {
        foreach (ContentScanner::builderFieldContract() as $component => $contract) {
            $contents = file_get_contents(dirname(__DIR__, 2) . '/themes/default/design/' . self::viewPath($component));

            self::assertIsString($contents, $component . ' view must exist');
            self::assertStringContainsString('$module_id', $contents, $component . ' must render under the stable Builder module wrapper');
        }
    }

    public function test_chrome_fields_are_limited_to_english_public_merchant_copy(): void
    {
        $fields = ContentScanner::chromeTextFields([
            'header' => ['header_ads' => ['active' => true, 'items' => [[
                'title' => ['en' => 'Free shipping worldwide', 'zh_cn' => '全球免邮'],
                'link'  => ['link' => 'https://example.test/ignored'],
            ]]]],
            'menu' => ['menus' => [[
                'name'          => ['en' => 'Accessories', 'fr' => 'Accessoires'],
                'badge'         => ['isShow' => true, 'name' => ['en' => 'New']],
                'isChildren'    => true,
                'childrenGroup' => [[
                    'name'     => ['en' => 'Shop by type'],
                    'children' => [[
                        'link' => ['text' => ['en' => 'Phone cases'], 'link' => '/collections/cases'],
                    ]],
                ]],
            ]]],
            'footer' => ['services' => ['enable' => true, 'items' => [[
                'title' => ['en' => 'Secure checkout'], 'sub_title' => ['en' => 'Protected payments'],
            ]]]],
        ]);

        self::assertSame('Free shipping worldwide', $fields['header.header_ads.items.0.title']);
        self::assertSame('Accessories', $fields['menu.menus.0.name']);
        self::assertSame('New', $fields['menu.menus.0.badge.name']);
        self::assertSame('Shop by type', $fields['menu.menus.0.childrenGroup.0.name']);
        self::assertSame('Phone cases', $fields['menu.menus.0.childrenGroup.0.children.0.link.text']);
        self::assertSame('Secure checkout', $fields['footer.services.items.0.title']);
        self::assertArrayNotHasKey('header.header_ads.items.0.link', $fields);
    }

    public function test_design_service_gives_every_builder_module_a_stable_public_dictionary_root(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/beike/Services/DesignService.php');

        self::assertIsString($contents);
        self::assertStringContainsString('data-lgt-root', $contents);
        self::assertStringContainsString("hash('sha256', 'lgt-builder:'", $contents);
    }

    public function test_shopify_builder_codes_are_canonicalised_before_contract_lookup(): void
    {
        self::assertSame('shopify_slideshow', ContentScanner::canonicalBuilderCode('shopify-slideshow'));
        self::assertSame('shopify_collection_list', ContentScanner::canonicalBuilderCode(' shopify_collection_list '));
    }

    private static function viewPath(string $component): string
    {
        return in_array($component, ['latest_products', 'bestseller'], true)
            ? 'partials/automatic_products.blade.php'
            : $component . '.blade.php';
    }
}
