<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Beike\Admin\Services\DesignEditorCatalog;
use Beike\Services\BuilderComponentMigrator;
use Beike\Services\BuilderDataMigrator;
use Beike\Services\BuilderPageSchema;
use Beike\Services\BuilderStateService;
use Beike\Services\DesignService;
use Beike\Services\PuckDataAdapter;
use Beike\Services\ShopifyLiquidParser;
use Beike\Services\ShopifyMigrationAnalyzer;
use Beike\Services\ShopifyMigrationReport;
use Beike\Services\ShopifyMigrationValidator;
use Beike\Services\ShopifyPuckRegistryGenerator;
use Beike\Services\ShopifyRendererRegistry;

$verify = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$module = BuilderPageSchema::normalizeModule([
    'component_version' => 400,
    'content'           => ['responsive' => ['visibility' => ['desktop' => false, 'tablet' => '1', 'mobile' => 'off']]],
]);
$verify($module['component_version'] === 99, 'Component version was not bounded.');
$verify($module['content']['responsive']['visibility']['tablet'] === true, 'Tablet visibility was not normalized.');

$legacy = BuilderDataMigrator::migrate([['code' => 'promo_bar', 'content' => ['responsive' => ['gap' => 16]]]]);
$verify($legacy['migrated'] === true && $legacy['data']['schema_version'] === BuilderPageSchema::VERSION, 'Legacy page data was not migrated.');
$verify($legacy['data']['modules'][0]['component_version'] === 1, 'Legacy module version was not supplied.');
$verify($legacy['data']['modules'][0]['content']['responsive']['visibility']['mobile'] === true, 'Legacy mobile visibility was not supplied.');
$verify($legacy['data']['modules'][0]['content']['responsive']['gap'] === 16, 'Unknown responsive settings must survive migration.');
$component = BuilderComponentMigrator::migrate(['code' => 'img_text_banner', 'component_version' => 1, 'content' => ['image' => '/legacy.jpg']]);
$verify($component['component_version'] === 2 && $component['content']['desktop_image'] === '/legacy.jpg', 'Component v1-to-v2 media migration failed.');

$pluginCatalog = DesignEditorCatalog::normalize([[
    'code'        => 'sample_extension',
    'name'        => 'Sample extension',
    'view_path'   => 'Sample::shop.section',
    'make'        => ['heading' => 'Hello'],
    'fields'      => [
        ['id' => 'heading', 'type' => 'text', 'label' => '<b>Heading</b>'],
        ['id' => 'layout', 'type' => 'select', 'label' => 'Layout', 'options' => [['label' => 'Grid', 'value' => 'grid']]],
        ['id' => 'unsafe-field', 'type' => 'script', 'label' => 'Unsafe'],
    ],
]]);
$verify(($pluginCatalog[0]['fields'][0]['label'] ?? null) === 'Heading' && ($pluginCatalog[0]['fields'][1]['options'][0]['value'] ?? null) === 'grid', 'Plugin Builder fields were not normalized into safe data-only controls.');
$verify(count($pluginCatalog[0]['fields'] ?? []) === 2, 'Unsafe executable plugin field metadata reached the Builder catalog.');

$page = PuckDataAdapter::fromPuck([
    'content' => [['type' => 'promo_bar', 'props' => ['id' => 'test-1', 'title' => 'Test']]],
    'root'    => ['props' => ['schemaVersion' => 5, 'pageType' => 'home', 'globalStyle' => [], 'appEmbeds' => [], 'pageMetadata' => ['title' => 'Launch', 'slug' => 'launch-page']]],
]);
$verify($page['engine'] === 'puck', 'Puck engine metadata was lost.');
$verify($page['modules'][0]['module_id'] === 'test-1', 'Puck module ID was lost.');
$verify($page['page_metadata']['slug'] === 'launch-page', 'Puck page metadata was lost.');
$puckRoundTrip = PuckDataAdapter::toPuck($page);
$verify($puckRoundTrip['root']['props']['pageMetadata']['title'] === 'Launch', 'Puck page metadata did not round-trip.');
$duplicated = PuckDataAdapter::fromPuck([
    'content' => [
        ['type' => 'promo_bar', 'props' => ['id' => 'original', 'title' => 'Original', '_builder' => ['module_id' => 'original']]],
        ['type' => 'promo_bar', 'props' => ['id' => 'duplicate', 'title' => 'Copy', '_builder' => ['module_id' => 'original', 'visible' => false]]],
    ],
]);
$verify($duplicated['modules'][1]['module_id'] === 'duplicate', 'Duplicating a module reused the source identity.');
$verify(PuckDataAdapter::toPuck($duplicated)['content'][1]['props']['_builder']['visible'] === false, 'Hidden module visibility was lost during save.');
$insertedModuleId = 'builder-payment_trust-0123456789abcdef01234567';
$insertedPuckPage = PuckDataAdapter::fromPuck([
    'content' => [['type' => 'payment_trust', 'props' => ['id' => $insertedModuleId, 'placement' => 'product', '_builder' => ['name' => '支付与购买保障']]]],
    'root'    => ['props' => ['schemaVersion' => 5, 'pageType' => 'product', 'globalStyle' => [], 'appEmbeds' => [], 'pageMetadata' => []]],
]);
$verify($insertedPuckPage['modules'][0]['module_id'] === $insertedModuleId && $insertedPuckPage['modules'][0]['code'] === 'payment_trust' && $insertedPuckPage['modules'][0]['content']['placement'] === 'product', 'An inserted Builder component did not retain its durable module identity and storefront placement through the save boundary.');

$advancedPuckPage = PuckDataAdapter::fromPuck([
    'content' => [[
        'type'  => 'feature_grid',
        'props' => [
            'id'             => 'advanced-grid',
            'title'          => '快捷标题',
            '_advanced_json' => '{"columns":2,"items":[{"title":"可编辑卡片","text":"真实前台内容"}]}',
            '_builder'       => ['module_id' => 'advanced-grid', 'name' => '精选卡片网格'],
        ],
    ]],
    'root' => ['props' => ['schemaVersion' => 5, 'pageType' => 'home', 'globalStyle' => [], 'appEmbeds' => [], 'pageMetadata' => []]],
]);
$verify(($advancedPuckPage['modules'][0]['content']['columns'] ?? null) === 2 && ($advancedPuckPage['modules'][0]['content']['items'][0]['title'] ?? null) === '可编辑卡片' && ! isset($advancedPuckPage['modules'][0]['content']['_advanced_json']), 'Advanced module configuration did not enter the durable Builder payload.');
$advancedPrecedence = PuckDataAdapter::fromPuck([
    'content' => [['type' => 'trust_cards', 'props' => ['id' => 'precedence-card', 'title' => '最新快捷标题', '_advanced_json' => '{"title":"过期 JSON 标题","custom_extension":"保留扩展"}']]],
]);
$verify(($advancedPrecedence['modules'][0]['content']['title'] ?? null) === '最新快捷标题' && ($advancedPrecedence['modules'][0]['content']['custom_extension'] ?? null) === '保留扩展', 'A stale advanced JSON value overrode a newer dedicated field edit.');
$invalidAdvancedPuckPage = PuckDataAdapter::fromPuck([
    'content' => [['type' => 'feature_grid', 'props' => ['id' => 'invalid-grid', 'title' => '保留快捷标题', '_advanced_json' => '{invalid', '_builder' => ['module_id' => 'invalid-grid']]]],
    'root'    => ['props' => ['schemaVersion' => 5, 'pageType' => 'home', 'globalStyle' => [], 'appEmbeds' => [], 'pageMetadata' => []]],
]);
$verify(($invalidAdvancedPuckPage['modules'][0]['content']['title'] ?? null) === '保留快捷标题' && ! isset($invalidAdvancedPuckPage['modules'][0]['content']['_advanced_json']), 'Invalid advanced configuration was not safely ignored.');

$storyImageAlt = DesignService::handleModuleContent('story_block', ['image' => '/image/catalog/story.jpg', 'image_alt' => '品牌故事图片']);
$verify($storyImageAlt['image'] === '/image/catalog/story.jpg' && $storyImageAlt['image_alt'] === '品牌故事图片', 'Story block did not preserve the visual image-alt field.');
$featureImageAlt = DesignService::handleModuleContent('feature_grid', ['items' => [['image' => '/image/catalog/card.jpg', 'image_alt' => '精选商品图片', 'title' => '精选']]]);
$verify(($featureImageAlt['items'][0]['image_alt'] ?? null) === '精选商品图片', 'Feature-grid did not preserve the visual card image-alt field.');

$withoutSubscriptions = DesignService::handleRequestModules(['modules' => [
    ['code' => 'newsletter', 'module_id' => 'old-newsletter', 'content' => []],
    ['code' => 'shopify-email-signup-banner', 'module_id' => 'old-shopify-newsletter', 'content' => []],
    ['code' => 'promo_bar', 'module_id' => 'kept-promo', 'content' => []],
]]);
$verify(count($withoutSubscriptions['modules']) === 1 && $withoutSubscriptions['modules'][0]['code'] === 'promo_bar', 'Removed subscription modules were not rejected.');
$boundedNativeData = DesignService::handleRequestModules(['modules' => [
    ['code' => 'icons', 'module_id' => 'bounded-icons', 'content' => ['images' => array_fill(0, 30, ['text' => 'item'])]],
    ['code' => 'tab_product', 'module_id' => 'bounded-tabs', 'content' => ['tabs' => array_fill(0, 20, ['title' => 'tab', 'products' => array_merge(range(1, 40), range(1, 40))])]],
]]);
$verify(count($boundedNativeData['modules'][0]['content']['images']) === 10, 'Icon module collection bounds were not enforced at the durable-data boundary.');
$verify(count($boundedNativeData['modules'][1]['content']['tabs']) === 8 && count($boundedNativeData['modules'][1]['content']['tabs'][0]['products']) === 24, 'Tabbed-product collection bounds or ID de-duplication were not enforced.');
$normalizedNativeLayouts = DesignService::handleRequestModules(['modules' => [
    ['code' => 'product', 'content' => ['columns_desktop' => 99, 'columns_mobile' => 0]],
    ['code' => 'page', 'content' => ['columns_desktop' => 1, 'columns_mobile' => 9, 'show_view_all' => 'false']],
    ['code' => 'icons', 'content' => ['style_variant' => 'scripted']],
    ['code' => 'faq', 'content' => ['style_variant' => 'unknown', 'open_first' => '0']],
    ['code' => 'trust_cards', 'content' => ['columns' => 99, 'style_variant' => 'unknown']],
    ['code' => 'testimonials', 'content' => ['columns' => 0, 'style_variant' => 'soft']],
    ['code' => 'feature_grid', 'content' => ['columns' => 99, 'image_ratio' => 'wide', 'card_style' => 'unknown']],
]]);
$nativeLayouts = array_column($normalizedNativeLayouts['modules'], 'content', 'code');
$verify($nativeLayouts['product']['columns_desktop'] === 5 && $nativeLayouts['product']['columns_tablet'] === 3 && $nativeLayouts['product']['columns_mobile'] === 1, 'Product grid controls were not bounded at the durable-data boundary.');
$verify($nativeLayouts['page']['columns_desktop'] === 2 && $nativeLayouts['page']['columns_tablet'] === 2 && $nativeLayouts['page']['columns_mobile'] === 2 && $nativeLayouts['page']['show_view_all'] === false, 'Page grid or view-all controls were not normalized.');
$verify($nativeLayouts['icons']['style_variant'] === 'minimal', 'Icon presentation variant was not normalized.');
$verify($nativeLayouts['faq']['style_variant'] === 'divided' && $nativeLayouts['faq']['open_first'] === false, 'FAQ presentation controls were not normalized.');
$verify($nativeLayouts['trust_cards']['columns'] === 4 && $nativeLayouts['trust_cards']['columns_tablet'] === 2 && $nativeLayouts['trust_cards']['columns_mobile'] === 1 && $nativeLayouts['trust_cards']['style_variant'] === 'soft', 'Trust-card presentation controls were not normalized.');
$verify($nativeLayouts['testimonials']['columns'] === 2 && $nativeLayouts['testimonials']['columns_tablet'] === 2 && $nativeLayouts['testimonials']['columns_mobile'] === 1 && $nativeLayouts['testimonials']['style_variant'] === 'soft', 'Testimonial presentation controls were not normalized.');
$verify($nativeLayouts['feature_grid']['columns'] === 4 && $nativeLayouts['feature_grid']['columns_tablet'] === 2 && $nativeLayouts['feature_grid']['columns_mobile'] === 1 && $nativeLayouts['feature_grid']['image_ratio'] === 'landscape' && $nativeLayouts['feature_grid']['card_style'] === 'editorial', 'Feature-grid presentation controls were not normalized.');

$nativeRichText = DesignService::handleModuleContent('rich_text', ['text' => '<h2>Section heading</h2><blockquote>Trusted quote</blockquote><script>alert(1)</script>']);
$verify(str_contains($nativeRichText['data'], '<h2>Section heading</h2>') && str_contains($nativeRichText['data'], '<blockquote>Trusted quote</blockquote>') && ! str_contains($nativeRichText['data'], '<script'), 'Native rich text did not preserve safe editorial structure or remove executable markup.');
$responsiveCustomCode = DesignService::handleModuleContent('custom_code', ['html' => '<img src="/image/catalog/card.jpg" alt="Card"><table><tr><td>Wide data</td></tr></table>']);
$verify(str_contains($responsiveCustomCode['document'], 'img,video,svg,canvas{display:block;max-width:100%}') && str_contains($responsiveCustomCode['document'], 'table{display:block;overflow-x:auto}'), 'Custom-code sections did not receive the safe responsive document baseline.');
$emptyNativeCards = DesignService::handleModuleContent('feature_grid', ['items' => [[], ['title' => 'Keep me'], ['image' => 'javascript:alert(1)']]]);
$verify(count($emptyNativeCards['items']) === 1 && $emptyNativeCards['items'][0]['title'] === 'Keep me', 'Feature-grid did not remove empty or unsafe cards.');
$nativeSlides = DesignService::handleModuleContent('img_text_slideshow', ['title' => 'Carousel', 'images' => [
    ['image' => 'javascript:alert(1)'],
    ['image' => 'javascript:alert(1)', 'title' => 'Editable placeholder'],
    ['image' => 'https://cdn.example.com/launch.webm', 'image_alt' => 'Launch video'],
]]);
$verify(count($nativeSlides['images']) === 2 && $nativeSlides['images'][0]['image'] === '' && $nativeSlides['images'][0]['image_path'] === '' && $nativeSlides['images'][1]['type'] === 'video', 'Native slideshow did not discard empty unsafe media or normalize supported video media.');
$sectionLayoutData = DesignService::handleRequestModules(['modules' => [[
    'code'      => 'promo_bar',
    'module_id' => 'section-layout',
    'content'   => ['anchor' => 'new-arrivals', 'section_layout' => ['spacing_mode' => 'custom', 'top' => -20, 'bottom' => 999]],
], [
    'code'      => 'promo_bar',
    'module_id' => 'unsafe-anchor',
    'content'   => ['anchor' => 'BAD anchor<script>', 'section_layout' => ['spacing_mode' => 'invalid', 'top' => 12, 'bottom' => 24]],
]]]);
$verify($sectionLayoutData['modules'][0]['content']['anchor']        === 'new-arrivals'
    && $sectionLayoutData['modules'][0]['content']['section_layout'] === ['spacing_mode' => 'custom', 'top' => 0, 'bottom' => 200],
    'Per-section spacing and anchor settings were not normalized at the durable-data boundary.');
$verify($sectionLayoutData['modules'][1]['content']['anchor']                        === ''
    && $sectionLayoutData['modules'][1]['content']['section_layout']['spacing_mode'] === 'inherit',
    'Invalid section anchors or spacing modes survived durable-data sanitization.');
$sectionWrapper = DesignService::wrapResponsiveModuleHtml('<section class="module-item"></section>', $sectionLayoutData['modules'][0]['content'], 'section-layout');
$verify(str_contains($sectionWrapper, 'id="new-arrivals"')
    && str_contains($sectionWrapper, '--builder-section-space-top: 0px')
    && str_contains($sectionWrapper, '--builder-section-space-bottom: 200px'),
    'The shared storefront wrapper did not apply verified section layout controls.');
$unsafeNestedLink = DesignService::handleRequestModules(['modules' => [[
    'code'      => 'trust_cards',
    'module_id' => 'unsafe-link',
    'content'   => ['items' => [[
        'title' => 'Unsafe',
        'link'  => ['type' => 'custom', 'value' => '//evil.example/path', 'new_window' => true],
    ]]],
]]]);
$verify(($unsafeNestedLink['modules'][0]['content']['items'][0]['link']['value'] ?? null) === '', 'A protocol-relative nested module link survived durable-data sanitization.');

$metadata = DesignService::handleRequestModules(['page_metadata' => ['title' => '<b>Landing</b>', 'slug' => 'Summer Sale!', 'seo_description' => str_repeat('a', 340)], 'modules' => []]);
$verify($metadata['schema_version'] === 5 && $metadata['page_metadata']['title'] === 'Landing', 'Page metadata was not normalized.');
$verify($metadata['page_metadata']['slug'] === 'summer-sale' && strlen($metadata['page_metadata']['seo_description']) === 320, 'Page metadata bounds were not applied.');
$tokens = DesignService::normalizeGlobalStyle(['primary_color' => '#abc123', 'font_family' => 'serif', 'grid_gap' => 999, 'reduce_motion' => 'true']);
$verify($tokens['primary_color'] === '#ABC123' && $tokens['font_family'] === 'serif' && $tokens['grid_gap'] === 40 && $tokens['reduce_motion'] === true, 'Storefront global design tokens were not normalized.');
$verify(DesignService::matchesPlacement('category', 'home') === false, 'Category-only modules leaked onto the home page.');
$verify(DesignService::matchesPlacement('all', 'page') === true, 'Global modules did not match content pages.');
$verify(DesignService::matchesPlacement('both', 'home') === true && DesignService::matchesPlacement('both', 'product') === true && DesignService::matchesPlacement('both', 'category') === false, 'Legacy both placement was not bounded to home and product pages.');
$publishedState                  = BuilderStateService::normalize(['design' => $page, 'header' => [], 'footer' => [], 'menu' => []]);
$metadataOnlyDraft               = $publishedState;
$metadataOnlyDraft['version']    = 8;
$metadataOnlyDraft['updated_at'] = '2026-09-10T00:00:00+08:00';
$verify(BuilderStateService::hasUnpublishedChanges($metadataOnlyDraft, $publishedState) === false, 'Draft bookkeeping incorrectly marked published content as dirty.');
$changedDraft                                             = $metadataOnlyDraft;
$changedDraft['design']['modules'][0]['content']['title'] = 'Changed';
$verify(BuilderStateService::hasUnpublishedChanges($changedDraft, $publishedState) === true, 'A real Builder content change was not detected.');
$shopifiDefaults = DesignService::normalizeGlobalStyle([]);
$verify($shopifiDefaults['primary_color'] === '#000000' && $shopifiDefaults['accent_color'] === '#C1FBD4' && $shopifiDefaults['background_color'] === '#FBFBF5' && $shopifiDefaults['border_radius'] === 12 && $shopifiDefaults['page_width'] === 1440 && $shopifiDefaults['section_spacing'] === 64, 'Shopifi global design defaults were not applied.');
$upgradedLegacyDefaults = DesignService::normalizeGlobalStyle(['primary_color' => '#155EEF', 'accent_color' => '#F79009', 'background_color' => '#FFFFFF', 'text_color' => '#101828', 'border_radius' => 10, 'page_width' => 1400, 'section_spacing' => 32, 'grid_gap' => 16]);
$verify($upgradedLegacyDefaults['primary_color'] === '#000000' && $upgradedLegacyDefaults['accent_color'] === '#C1FBD4', 'Untouched legacy theme defaults were not upgraded.');
$shopifyVideo = DesignService::handleModuleContent('shopify-video', ['heading' => 'A film', 'video' => '/image/catalog/launch.mp4', 'video_url' => 'https://www.youtube.com/watch?v=_9VUPq3SxOc', 'cover_image' => '/image/catalog/poster.jpg', 'enable_video_looping' => true, 'padding_top' => 999]);
$verify(ShopifyRendererRegistry::viewFor('shopify-video') === 'design.shopify_video', 'Shopify video does not have a dedicated renderer.');
$verify($shopifyVideo['video'] === '/image/catalog/launch.mp4' && str_starts_with($shopifyVideo['external_embed'], 'https://www.youtube-nocookie.com/embed/_9VUPq3SxOc') && $shopifyVideo['padding_top'] === 100, 'Shopify video renderer data was not normalized.');
$unsafeShopifyVideo = DesignService::handleModuleContent('shopify-video', ['video' => '/image/catalog/../escape.mp4', 'video_url' => 'https://invalid.example/video']);
$verify($unsafeShopifyVideo['video'] === '' && $unsafeShopifyVideo['external_embed'] === '', 'Shopify video renderer accepted an unsafe source.');
$shopifyBanner = DesignService::handleModuleContent('shopify-image-banner', ['image' => '/image/catalog/hero.jpg', 'image_2' => '/image/catalog/hero-2.jpg', 'image_overlay_opacity' => 120, 'desktop_content_position' => 'middle-left', 'blocks' => [
    ['block_type' => 'heading', 'heading' => 'New collection', 'heading_size' => 'hxl'],
    ['block_type' => 'buttons', 'button_label_1' => 'Shop now', 'button_link_1' => '/collections/new', 'button_label_2' => 'Unsafe', 'button_link_2' => 'javascript:alert(1)'],
]]);
$verify(ShopifyRendererRegistry::viewFor('shopify-image-banner') === 'design.shopify_image_banner', 'Shopify image banner does not have a dedicated renderer.');
$verify($shopifyBanner['image'] === '/image/catalog/hero.jpg' && $shopifyBanner['image_overlay_opacity'] === 100 && $shopifyBanner['blocks'][1]['button_link_1'] === '/collections/new' && $shopifyBanner['blocks'][1]['button_link_2'] === '', 'Shopify image banner renderer data was not normalized.');
$legacyBanner = DesignService::handleModuleContent('shopify-image-banner', ['image' => 'https://cdn.example.test/hero.jpg', 'image_2' => 'javascript:alert(1)']);
$verify($legacyBanner['image'] === 'https://cdn.example.test/hero.jpg' && $legacyBanner['image_2'] === '', 'Shopify image banner did not preserve a safe legacy image source.');
$shopifySlideshow = DesignService::handleModuleContent('shopify-slideshow', ['layout' => 'grid', 'slide_height' => 'large', 'slider_visual' => 'dots', 'auto_rotate' => true, 'change_slides_speed' => 99, 'blocks' => [
    ['block_type' => 'slide', 'image' => '/image/catalog/slide.jpg', 'image_overlay_opacity' => 120, 'heading' => '<b>New season</b>', 'heading_size' => 'hxl', 'subheading' => '<script>alert(1)</script>Safe copy', 'button_label' => 'Shop now', 'link' => '/products/1', 'box_align' => 'bottom-right', 'text_alignment' => 'right', 'text_alignment_mobile' => 'left'],
    ['block_type' => 'slide', 'image' => 'javascript:alert(1)', 'link' => 'javascript:alert(1)'],
]]);
$verify(ShopifyRendererRegistry::viewFor('shopify-slideshow') === 'design.shopify_slideshow', 'Shopify slideshow does not have a dedicated renderer.');
$verify($shopifySlideshow['layout'] === 'grid' && $shopifySlideshow['change_slides_speed'] === 9 && $shopifySlideshow['slides'][0]['image'] === '/image/catalog/slide.jpg' && $shopifySlideshow['slides'][0]['image_overlay_opacity'] === 100 && $shopifySlideshow['slides'][0]['heading'] === 'New season' && $shopifySlideshow['slides'][0]['link'] === '/products/1' && $shopifySlideshow['slides'][0]['color_scheme'] === 'scheme-1' && $shopifySlideshow['slides'][1]['image'] === '' && $shopifySlideshow['slides'][1]['link'] === '', 'Shopify slideshow did not preserve its safe carousel boundary.');
$shopifyCollectionDirectory = DesignService::handleModuleContent('shopify-main-list-collections', ['title' => '<b>Browse</b>', 'sort' => 'products_high', 'image_ratio' => 'portrait', 'columns_desktop' => 99, 'columns_mobile' => 'invalid']);
$verify(ShopifyRendererRegistry::viewFor('shopify-main-list-collections') === 'design.shopify_main_list_collections', 'Shopify collection directory does not have a dedicated renderer.');
$verify($shopifyCollectionDirectory['title'] === 'Browse' && $shopifyCollectionDirectory['sort'] === 'products_high' && $shopifyCollectionDirectory['image_ratio'] === 'portrait' && $shopifyCollectionDirectory['columns_desktop'] === 6 && $shopifyCollectionDirectory['columns_mobile'] === 2 && isset($shopifyCollectionDirectory['pagination']['current']), 'Shopify collection directory did not retain its bounded local data contract.');
$shopifyRichText = DesignService::handleModuleContent('shopify-rich-text', ['content_alignment' => 'right', 'padding_top' => -1, 'blocks' => [
    ['block_type' => 'heading', 'heading' => '<b>Editorial</b>', 'heading_size' => 'hxl'],
    ['block_type' => 'text', 'text' => '<p>Safe <strong>copy</strong><script>alert(1)</script></p>'],
    ['block_type' => 'button', 'button_label' => 'Read', 'button_link' => 'https://example.test/story', 'button_label_2' => 'Bad', 'button_link_2' => 'javascript:alert(1)'],
]]);
$verify(ShopifyRendererRegistry::viewFor('shopify-rich-text') === 'design.shopify_rich_text', 'Shopify rich text does not have a dedicated renderer.');
$verify($shopifyRichText['content_alignment'] === 'right' && $shopifyRichText['padding_top'] === 0 && str_contains($shopifyRichText['blocks'][1]['html'], '<strong>copy</strong>') && ! str_contains($shopifyRichText['blocks'][1]['html'], 'script') && $shopifyRichText['blocks'][2]['button_link_2'] === '', 'Shopify rich-text renderer did not sanitize its data.');
$shopifyImageWithText = DesignService::handleModuleContent('shopify-image-with-text', ['image' => '/image/catalog/editorial.jpg', 'height' => 'large', 'desktop_image_width' => 'small', 'layout' => 'text_first', 'content_layout' => 'overlap', 'desktop_content_position' => 'middle', 'mobile_content_alignment' => 'center', 'padding_bottom' => 999, 'blocks' => [
    ['block_type' => 'heading', 'heading' => '<b>Editorial</b>', 'heading_size' => 'hxl'],
    ['block_type' => 'text', 'text' => '<p>Safe <strong>copy</strong><script>alert(1)</script></p>', 'text_style' => 'subtitle'],
    ['block_type' => 'button', 'button_label' => 'Explore', 'button_link' => '/collections/editorial'],
    ['block_type' => 'button', 'button_label' => 'Discarded duplicate', 'button_link' => 'javascript:alert(1)'],
]]);
$verify(ShopifyRendererRegistry::viewFor('shopify-image-with-text') === 'design.shopify_image_with_text', 'Shopify image-with-text does not have a dedicated renderer.');
$verify($shopifyImageWithText['image'] === '/image/catalog/editorial.jpg' && $shopifyImageWithText['layout'] === 'text_first' && $shopifyImageWithText['padding_bottom'] === 100 && str_contains($shopifyImageWithText['blocks'][1]['html'], '<strong>copy</strong>') && count($shopifyImageWithText['blocks']) === 3 && $shopifyImageWithText['blocks'][2]['button_link'] === '/collections/editorial', 'Shopify image-with-text renderer did not preserve its safe layout/content boundary.');
$shopifyMulticolumn = DesignService::handleModuleContent('shopify-multicolumn', ['title' => '<b>Highlights</b>', 'heading_size' => 'hxl', 'columns_desktop' => 99, 'columns_mobile' => '2', 'image_ratio' => 'circle', 'swipe_on_mobile' => true, 'button_label' => 'All stories', 'button_link' => '/pages/stories', 'blocks' => [
    ['block_type' => 'column', 'image' => '/image/catalog/story.jpg', 'title' => '<i>Story</i>', 'text' => '<p>Safe <strong>text</strong><script>alert(1)</script></p>', 'link_label' => 'Read story', 'link' => '/pages/story'],
    ['block_type' => 'column', 'image' => 'javascript:alert(1)', 'title' => 'Unsafe source', 'link_label' => 'Unsafe link', 'link' => 'javascript:alert(1)'],
]]);
$verify(ShopifyRendererRegistry::viewFor('shopify-multicolumn') === 'design.shopify_multicolumn', 'Shopify multicolumn does not have a dedicated renderer.');
$verify($shopifyMulticolumn['columns_desktop'] === 6 && $shopifyMulticolumn['columns_mobile'] === 2 && $shopifyMulticolumn['swipe_on_mobile'] === true && $shopifyMulticolumn['blocks'][0]['image'] === '/image/catalog/story.jpg' && str_contains($shopifyMulticolumn['blocks'][0]['html'], '<strong>text</strong>') && $shopifyMulticolumn['blocks'][1]['image'] === '' && $shopifyMulticolumn['blocks'][1]['link'] === '', 'Shopify multicolumn renderer did not preserve its safe card boundary.');
$shopifyCollapsible = DesignService::handleModuleContent('shopify-collapsible-content', ['heading' => '<b>Answers</b>', 'layout' => 'section', 'image' => '/image/catalog/faq.jpg', 'desktop_layout' => 'image_first', 'open_first_collapsible_row' => true, 'padding_top' => -1, 'blocks' => [
    ['block_type' => 'collapsible_row', 'heading' => 'Shipping', 'icon' => 'truck', 'row_content' => '<p>Safe <strong>answer</strong><script>alert(1)</script></p>'],
    ['block_type' => 'collapsible_row', 'heading' => 'Unsafe icon', 'icon' => '<script>', 'row_content' => ''],
]]);
$verify(ShopifyRendererRegistry::viewFor('shopify-collapsible-content') === 'design.shopify_collapsible_content', 'Shopify collapsible content does not have a dedicated renderer.');
$verify($shopifyCollapsible['layout'] === 'section' && $shopifyCollapsible['image'] === '/image/catalog/faq.jpg' && $shopifyCollapsible['open_first_collapsible_row'] === true && $shopifyCollapsible['padding_top'] === 0 && $shopifyCollapsible['blocks'][0]['icon'] === 'truck' && str_contains($shopifyCollapsible['blocks'][0]['html'], '<strong>answer</strong>') && ! str_contains($shopifyCollapsible['blocks'][0]['html'], 'script') && $shopifyCollapsible['blocks'][1]['icon'] === 'none', 'Shopify collapsible-content renderer did not preserve its safe disclosure boundary.');
$shopifyCollectionList = DesignService::handleModuleContent('shopify-collection-list', ['title' => '<b>Collections</b>', 'heading_size' => 'hxl', 'columns_desktop' => 99, 'columns_mobile' => '2', 'image_ratio' => 'portrait', 'show_view_all' => true, 'swipe_on_mobile' => true, 'padding_bottom' => -1, 'blocks' => [
    ['block_type' => 'featured_collection', 'collection' => 'not-an-id'],
]]);
$verify(ShopifyRendererRegistry::viewFor('shopify-collection-list') === 'design.shopify_collection_list', 'Shopify collection list does not have a dedicated renderer.');
$verify($shopifyCollectionList['columns_desktop'] === 6 && $shopifyCollectionList['columns_mobile'] === 2 && $shopifyCollectionList['image_ratio'] === 'portrait' && $shopifyCollectionList['show_view_all'] === true && $shopifyCollectionList['swipe_on_mobile'] === true && $shopifyCollectionList['padding_bottom'] === 0 && $shopifyCollectionList['blocks'][0]['collection'] === null && $shopifyCollectionList['categories'] === [], 'Shopify collection-list renderer did not preserve its category selection boundary.');
$shopifyMultirow = DesignService::handleModuleContent('shopify-multirow', ['image_height' => 'large', 'desktop_image_width' => 'small', 'image_layout' => 'alternate-right', 'text_style' => 'subtitle', 'desktop_content_alignment' => 'right', 'blocks' => [
    ['block_type' => 'row', 'image' => '/image/catalog/row-1.jpg', 'heading' => '<b>First</b>', 'text' => '<p>Safe <strong>copy</strong><script>alert(1)</script></p>', 'button_label' => 'Read', 'button_link' => '/pages/first'],
    ['block_type' => 'row', 'image' => 'javascript:alert(1)', 'heading' => 'Second', 'button_label' => 'Unsafe', 'button_link' => 'javascript:alert(1)'],
]]);
$verify(ShopifyRendererRegistry::viewFor('shopify-multirow') === 'design.shopify_multirow', 'Shopify multirow does not have a dedicated renderer.');
$verify($shopifyMultirow['image_height'] === 'large' && $shopifyMultirow['desktop_image_width'] === 'small' && $shopifyMultirow['blocks'][0]['image_position'] === 'second' && $shopifyMultirow['blocks'][1]['image_position'] === 'first' && $shopifyMultirow['blocks'][0]['image'] === '/image/catalog/row-1.jpg' && str_contains($shopifyMultirow['blocks'][0]['html'], '<strong>copy</strong>') && $shopifyMultirow['blocks'][1]['image'] === '' && $shopifyMultirow['blocks'][1]['button_link'] === '', 'Shopify multirow renderer did not preserve its safe repeated-row boundary.');

$shopifyFeaturedBlog = DesignService::handleModuleContent('shopify-featured-blog', ['blog' => 'not-an-id', 'post_limit' => 99, 'heading' => '<b>Journal</b>', 'columns_desktop' => 99, 'show_date' => false, 'padding_top' => -1]);
$verify(ShopifyRendererRegistry::viewFor('shopify-featured-blog') === 'design.shopify_featured_blog', 'Shopify featured blog does not have a dedicated renderer.');
$verify($shopifyFeaturedBlog['blog'] === null && $shopifyFeaturedBlog['post_limit'] === 4 && $shopifyFeaturedBlog['columns_desktop'] === 4 && $shopifyFeaturedBlog['show_date'] === false && $shopifyFeaturedBlog['padding_top'] === 0 && $shopifyFeaturedBlog['articles'] === [] && $shopifyFeaturedBlog['view_all_url'] === '', 'Shopify featured-blog renderer did not preserve its page-category boundary.');
$shopifyPage = DesignService::handleModuleContent('shopify-page', ['page' => 'not-an-id', 'heading_size' => 'hxl', 'padding_top' => -1, 'padding_bottom' => 999]);
$verify(ShopifyRendererRegistry::viewFor('shopify-page') === 'design.shopify_page', 'Shopify page does not have a dedicated renderer.');
$verify($shopifyPage['page'] === null && $shopifyPage['heading_size'] === 'hxl' && $shopifyPage['padding_top'] === 0 && $shopifyPage['padding_bottom'] === 100 && $shopifyPage['page_content'] === ['title' => '', 'html' => '', 'url' => ''], 'Shopify page renderer did not preserve its safe local-page boundary.');
$shopifyMainPage = DesignService::handleModuleContent('shopify-main-page', ['page' => 'not-an-id', 'padding_top' => -1, 'padding_bottom' => 999]);
$verify(ShopifyRendererRegistry::viewFor('shopify-main-page') === 'design.shopify_main_page', 'Shopify main-page does not have a dedicated renderer.');
$verify($shopifyMainPage['page'] === null && $shopifyMainPage['padding_top'] === 0 && $shopifyMainPage['padding_bottom'] === 100 && $shopifyMainPage['page_content'] === ['title' => '', 'html' => '', 'url' => ''], 'Shopify main-page did not preserve its safe local-page boundary.');
$shopifyCollectionBanner = DesignService::handleModuleContent('shopify-main-collection-banner', ['collection' => 'not-an-id', 'show_collection_description' => false, 'show_collection_image' => true]);
$verify(ShopifyRendererRegistry::viewFor('shopify-main-collection-banner') === 'design.shopify_main_collection_banner', 'Shopify main-collection-banner does not have a dedicated renderer.');
$verify($shopifyCollectionBanner['collection'] === null && $shopifyCollectionBanner['show_collection_description'] === false && $shopifyCollectionBanner['show_collection_image'] === true && $shopifyCollectionBanner['collection_content'] === ['id' => null, 'name' => '', 'html' => '', 'image' => '', 'url' => ''], 'Shopify main-collection-banner did not preserve its safe local-category boundary.');
$designServiceSource      = (string) file_get_contents(dirname(__DIR__) . '/beike/Services/DesignService.php');
$retiredShopifyComponents = ['shopify-apps', 'shopify-cart-icon-bubble', 'shopify-cart-notification-button', 'shopify-cart-notification-product', 'shopify-main-404', 'shopify-main-password-footer'];
$verify(array_filter($retiredShopifyComponents, static fn (string $code): bool => ShopifyRendererRegistry::viewFor($code) !== null) === [] && ShopifyRendererRegistry::viewFor('shopify-pickup-availability') === null && ! str_contains($designServiceSource, 'Central pickup point') && ! str_contains($designServiceSource, "'simulated'"), 'A Shopify runtime-only surface must not remain an addable Builder component.');

$liquid = (new ShopifyLiquidParser)->inspect("{% render 'card-product' %}{{ 'card.css' | asset_url }}{% schema %}{\"name\":\"Card\"}{% endschema %}");
$verify(in_array('snippets/card-product.liquid', $liquid['dependencies'], true), 'Liquid snippet dependency was not found.');
$verify($liquid['schema']['name'] === 'Card', 'Liquid schema was not parsed.');
$objectInspection = (new ShopifyLiquidParser)->inspect('{{- product.title | escape -}} {{ section.settings.heading }}');
$verify($objectInspection['objects'] === ['product', 'section'], 'Liquid object roots were not parsed.');

$registry = (new ShopifyPuckRegistryGenerator)->generate(['component_units' => [[
    'id'     => 'hero', 'name' => 'Hero', 'settings' => [['id' => 'show_button', 'type' => 'checkbox']],
    'blocks' => [['type' => 'slide', 'settings' => [['id' => 'heading', 'type' => 'text']]]],
]]]);
$verify($registry['components'][0]['fields'][0]['type'] === 'checkbox', 'Generated registry did not preserve field type.');
$verify($registry['components'][0]['blocks'][0]['settings'][0]['type'] === 'text', 'Generated registry block settings are not Puck-editable.');
$verify(strlen($registry['components'][0]['source_hash']) === 64, 'Generated registry has no stable source hash.');
$videoRegistry = (new ShopifyPuckRegistryGenerator)->generate(['component_units' => [[
    'id' => 'video', 'name' => 'Video', 'settings' => [['id' => 'video', 'type' => 'video'], ['id' => 'video_url', 'type' => 'video_url'], ['id' => 'link', 'type' => 'url'], ['id' => 'menu', 'type' => 'link_list']], 'blocks' => [],
]]]);
$videoFields = collect($videoRegistry['components'][0]['fields'])->keyBy('id');
$verify(($videoFields['video']['puck_type'] ?? null) === 'custom' && ($videoFields['video']['requires_native_picker'] ?? true) === false, 'Local video must use the dedicated Builder media field.');
$verify(($videoFields['video_url']['puck_type'] ?? null) === 'custom', 'External video must use the dedicated Builder field.');
$verify(($videoFields['link']['puck_type'] ?? null) === 'custom', 'Shopify URL must use the dedicated safe Builder link field.');
$verify(($videoFields['menu']['requires_native_picker'] ?? false) === true, 'Unavailable Shopify link lists must remain explicit recovery fields.');
$rangeRegistry = (new ShopifyPuckRegistryGenerator)->generate(['component_units' => [[
    'id' => 'slideshow', 'name' => 'Slideshow', 'settings' => [['id' => 'speed', 'type' => 'range', 'label' => 'Slide speed', 'info' => 'Seconds', 'min' => 3, 'max' => 9, 'step' => 2, 'unit' => 's']], 'blocks' => [],
]]]);
$rangeField = $rangeRegistry['components'][0]['fields'][0];
$verify(($rangeField['label'] ?? '') === 'Slide speed' && ($rangeField['info'] ?? '') === 'Seconds' && ($rangeField['min'] ?? null) === 3 && ($rangeField['max'] ?? null) === 9 && ($rangeField['step'] ?? null) === 2 && ($rangeField['unit'] ?? '') === 's', 'Generated registry lost Shopify range metadata required by the Builder field.');
$blogRegistry = (new ShopifyPuckRegistryGenerator)->generate(['component_units' => [[
    'id' => 'featured-blog', 'name' => 'Featured blog', 'settings' => [['id' => 'blog', 'type' => 'blog']], 'blocks' => [],
]]]);
$verify(($blogRegistry['components'][0]['fields'][0]['puck_type'] ?? null) === 'custom', 'Shopify blog must use the dedicated page-category picker.');

$ir = (new ShopifyMigrationAnalyzer)->analyze(['source' => 'fixture', 'summary' => ['discovered' => 1, 'warnings' => 0, 'by_type' => ['section' => 1]], 'files' => [[
    'relative_path' => 'sections/hero.liquid', 'file_type' => 'section', 'warnings' => [],
    'liquid'        => ['tags' => ['if'], 'filters' => ['escape'], 'objects' => ['product']],
]], 'component_units' => [[
    'id' => 'hero', 'name' => 'Hero', 'source_files' => ['sections/hero.liquid'], 'settings' => [], 'blocks' => [], 'presets' => [],
]]]);
$verify($ir['components'][0]['status'] === 'ANALYZED', 'Shopify IR did not record an evidence-based status.');
$verify($ir['components'][0]['manual_review_reasons'] === [], 'Non-withheld Shopify IR unexpectedly has runtime boundaries.');
$verify(in_array('product', $ir['coverage']['commerce_bindings'], true), 'Shopify IR did not identify commerce bindings.');
$verify(($ir['coverage']['field_mapping']['puck_builtin_field'] ?? 0) === 0, 'Unexpected field mapping in empty IR fixture.');
$verify(str_contains((new ShopifyMigrationReport)->render($ir), 'Runtime boundary / review reason'), 'Migration report was not rendered with review boundaries.');
$runtimeIr = (new ShopifyMigrationAnalyzer)->analyze(['source' => 'fixture', 'summary' => ['discovered' => 1, 'warnings' => 0, 'by_type' => ['section' => 1]], 'files' => [[
    'relative_path' => 'sections/cart.liquid', 'file_type' => 'section', 'warnings' => [],
    'liquid'        => ['tags' => ['form'], 'filters' => [], 'objects' => ['cart']],
]], 'component_units' => [[
    'id' => 'cart', 'name' => 'Cart', 'source_files' => ['sections/cart.liquid'], 'settings' => [], 'blocks' => [], 'presets' => [],
]]]);
$verify($runtimeIr['components'][0]['status'] === 'MANUAL_REVIEW' && count($runtimeIr['components'][0]['manual_review_reasons']) >= 2, 'Runtime-dependent Shopify IR must declare its withholding boundaries.');
$videoIr = (new ShopifyMigrationAnalyzer)->analyze(['source' => 'fixture', 'summary' => ['discovered' => 1, 'warnings' => 0, 'by_type' => ['section' => 1]], 'files' => [], 'component_units' => [[
    'id' => 'video', 'name' => 'Video', 'source_files' => [], 'settings' => [['id' => 'video', 'type' => 'video'], ['id' => 'video_url', 'type' => 'video_url'], ['id' => 'link', 'type' => 'url']], 'blocks' => [], 'presets' => [],
]]]);
$verify(($videoIr['coverage']['field_mapping']['puck_video_media_field'] ?? 0) === 1 && ($videoIr['coverage']['field_mapping']['puck_external_video_field'] ?? 0) === 1, 'Video field coverage does not reflect the dedicated adapters.');
$verify(($videoIr['coverage']['field_mapping']['puck_safe_url_field'] ?? 0) === 1, 'URL field coverage does not reflect the safe Builder adapter.');
$verify($videoIr['components'][0]['status'] === 'MIGRATED', 'Dedicated Shopify renderer did not update migration evidence.');
$validation = (new ShopifyMigrationValidator)->validate(
    ['files' => [['relative_path' => 'sections/hero.liquid']]],
    ['files'      => [['relative_path' => 'sections/hero.liquid']], 'components' => [['id' => 'hero', 'source_files' => ['sections/hero.liquid']]]],
    ['components' => [['type' => 'shopify-hero']]],
);
$verify($validation['valid'] === true, 'Migration validator rejected a consistent pipeline.');

// A drawer entry is never allowed to outlive its storefront renderer. Boot
// only here: the preceding pure assertions intentionally test URL fallbacks
// without an application URL, while the catalog needs Laravel localization.
$testApplication = require dirname(__DIR__) . '/bootstrap/app.php';
$testApplication->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$verify(type_route('custom', '//evil.example/path') === '' && type_route('custom', 'javascript:alert(1)') === '', 'Custom storefront links accepted an unsafe destination.');
$verify(type_route('custom', '/collections/new') === '/collections/new' && type_route('custom', 'https://example.test/path') === 'https://example.test/path', 'Safe custom storefront links were rejected.');
$fixedCampaign = DesignService::handleModuleContent('campaign_offer', ['timer_mode' => 'fixed', 'ends_at' => '2026-12-31 23:30']);
$verify(str_contains($fixedCampaign['ends_at'], '2026-12-31T23:30:00') && preg_match('/[+-]\d{2}:\d{2}$/', $fixedCampaign['ends_at']), 'Fixed campaign time was not normalized to an unambiguous store-time offset.');
$invalidCampaign = DesignService::handleModuleContent('campaign_offer', ['timer_mode' => 'fixed', 'ends_at' => 'not-a-date']);
$verify($invalidCampaign['ends_at'] === '', 'Invalid fixed campaign time survived storefront normalization.');
$promoLink = DesignService::handleModuleContent('promo_bar', ['button_text' => '查看活动', 'link' => ['type' => 'custom', 'value' => '/campaign', 'new_window' => true]]);
$slideLink = DesignService::handleModuleContent('img_text_slideshow', ['images' => [['image' => '/image/catalog/slide.jpg', 'link' => ['type' => 'custom', 'value' => '/campaign', 'new_window' => true]]]]);
$iconLink  = DesignService::handleModuleContent('icons', ['images' => [['image' => '/image/catalog/icon.svg', 'link' => ['type' => 'custom', 'value' => '/campaign', 'new_window' => true]]]]);
$verify(($promoLink['new_window'] ?? false) === true && ($slideLink['images'][0]['link']['new_window'] ?? false) === true && ($iconLink['images'][0]['new_window'] ?? false) === true, 'Configured Builder links must retain their new-window behavior through storefront normalization.');
$viewFile           = static fn (string $view): string => dirname(__DIR__) . '/themes/default/' . str_replace('.', '/', $view) . '.blade.php';
$nativeCatalog      = DesignEditorCatalog::core();
$missingNativeViews = array_filter($nativeCatalog, static fn (array $item): bool => ! is_file($viewFile($item['view_path'])));
$verify($missingNativeViews === [], 'A native Builder component has no storefront renderer: ' . implode(', ', array_column($missingNativeViews, 'code')));
$nativeCodes = array_column($nativeCatalog, 'code');
$verify(array_intersect(DesignEditorCatalog::RETIRED_DUPLICATE_CODES, $nativeCodes) === [], 'A retired duplicate component returned to the Builder insert inventory.');
$verify(array_diff(['faq', 'feature_grid', 'rich_text'], $nativeCodes) === [], 'Complete native content sections are missing from the insert inventory.');
$missingNativeDescriptions = array_filter($nativeCatalog, static fn (array $item): bool => trim((string) ($item['description'] ?? '')) === '');
$verify($missingNativeDescriptions === [], 'A native Builder component is missing its purpose description: ' . implode(', ', array_column($missingNativeDescriptions, 'code')));
$catalogByCode = array_column($nativeCatalog, null, 'code');
$verify(($catalogByCode['payment_trust']['available_on'] ?? null) === ['product'] && ! in_array('product', $catalogByCode['img_text_slideshow']['available_on'] ?? [], true), 'Native placement constraints allow an incompatible full-width module in the product purchase column.');
$verify(($catalogByCode['payment_trust']['max_instances'] ?? null) === 1 && ($catalogByCode['img_text_slideshow']['max_instances'] ?? null) === 6, 'Native module instance constraints are missing.');
$nativeDefaults = array_column($nativeCatalog, 'make', 'code');
$verify(isset($nativeDefaults['img_text_slideshow']['images'][0]['link'], $nativeDefaults['img_text_slideshow']['scroll_text']) && ($nativeDefaults['img_text_slideshow']['autoplay'] ?? null) === false && ($nativeDefaults['img_text_slideshow']['autoplay_delay'] ?? null) === 5000 && isset($nativeDefaults['tab_product']['tabs'][0]['products']) && array_key_exists('products', $nativeDefaults['product']) && array_key_exists('brands', $nativeDefaults['brand']) && isset($nativeDefaults['icons']['images'][0]['link']) && array_key_exists('items', $nativeDefaults['page']), 'Native Builder modules must retain their renderer-specific default payloads rather than share a generic section shape.');
$pageRepoSource = (string) file_get_contents(dirname(__DIR__) . '/beike/Admin/Repositories/PageRepo.php');
$verify(str_contains($pageRepoSource, 'array_unique(array_filter(array_map') && str_contains($pageRepoSource, 'FIELD({$column}') && str_contains($pageRepoSource, 'CASE {$caseWhen}'), 'Manually selected content pages must retain the merchant-defined Builder order on MySQL and PostgreSQL.');
$safeRichText = DesignService::handleModuleContent('rich_text', ['text' => '<h2>Story</h2><p>Safe <strong>copy</strong><script>alert(1)</script></p>']);
$verify(str_contains($safeRichText['data'], '<strong>copy</strong>') && ! str_contains($safeRichText['data'], 'script'), 'Native rich text was not sanitized before storefront rendering.');
$renderedFaq = DesignService::renderBuilderModule([
    'code'    => 'faq', 'module_id' => 'live-faq', 'view_path' => 'design.faq', 'visible' => true,
    'content' => ['title' => ['zh_cn' => '问答'], 'items' => [['question' => ['zh_cn' => '物流'], 'answer' => ['zh_cn' => '可实时追踪']]]],
]);
$verify(str_contains($renderedFaq['html'], 'data-builder-module-id="live-faq"') && str_contains($renderedFaq['html'], 'module-live-faq'), 'Live Builder fragments do not use the real responsive storefront wrapper.');
$verify(array_diff(['latest_products', 'bestseller'], $nativeCodes) === [], 'Automatic product feeds were not promoted into the native Builder inventory.');
$verify(($catalogByCode['latest_products']['component_version'] ?? null) === 2 && ($catalogByCode['bestseller']['component_version'] ?? null) === 2, 'Native automatic product feeds did not receive the upgraded schema version.');
$verify(isset($nativeDefaults['latest_products']['layout'], $nativeDefaults['latest_products']['columns_desktop'], $nativeDefaults['latest_products']['columns_tablet'], $nativeDefaults['bestseller']['limit']), 'Automatic product feeds are missing responsive layout controls.');
$verify(! is_dir(dirname(__DIR__) . '/plugins/LatestProducts') && ! is_dir(dirname(__DIR__) . '/plugins/Bestseller'), 'Retired automatic-product plugins still exist after their native migration.');
$generatedRegistryPath  = dirname(__DIR__) . '/storage/app/builder/shopify-puck-registry.json';
$generatedRegistry      = json_decode((string) file_get_contents($generatedRegistryPath), true, 512, JSON_THROW_ON_ERROR);
$withheldUnits          = array_values(array_filter($generatedRegistry['components'] ?? [], static fn (array $item): bool => ($item['status'] ?? '') === 'MANUAL_REVIEW'));
$missingWithheldReasons = array_filter($withheldUnits, static fn (array $item): bool => ! is_array($item['manual_review_reasons'] ?? null) || $item['manual_review_reasons'] === []);
$verify($missingWithheldReasons === [], 'A withheld Shopify schema lacks its declared runtime boundary: ' . implode(', ', array_column($missingWithheldReasons, 'type')));
$migratedUnits                 = array_values(array_filter($generatedRegistry['components'] ?? [], static fn (array $item): bool => ($item['status'] ?? '') === 'MIGRATED'));
$requiredDuplicateReplacements = ['shopify-image-with-text', 'shopify-slideshow', 'shopify-image-banner', 'shopify-multicolumn', 'shopify-rich-text', 'shopify-collapsible-content'];
$verify(array_diff($requiredDuplicateReplacements, array_column($migratedUnits, 'type')) === [], 'A duplicate component was retired without its verified replacement.');
$missingMigratedViews = array_filter($migratedUnits, static function (array $item) use ($viewFile): bool {
    $view = ShopifyRendererRegistry::viewFor((string) ($item['type'] ?? ''));

    return ! $view || ! is_file($viewFile($view));
});
$verify($missingMigratedViews === [], 'A historical migrated Shopify component has no dedicated storefront renderer: ' . implode(', ', array_column($missingMigratedViews, 'type')));
$builderViewPaths = array_unique(array_merge(
    array_column($nativeCatalog, 'view_path'),
    array_filter(array_map(static fn (array $item): ?string => ShopifyRendererRegistry::viewFor((string) ($item['type'] ?? '')), $migratedUnits))
));
$uncompiledDirectives = [];
$designTemplatePaths  = array_unique(array_merge(
    array_map($viewFile, $builderViewPaths),
    glob(dirname(__DIR__) . '/themes/default/design/*.blade.php') ?: []
));
foreach ($designTemplatePaths as $designTemplatePath) {
    $source = (string) file_get_contents($designTemplatePath);
    // Blade leaves a malformed directive untouched. This catches a real
    // storefront failure that a mere "view file exists" assertion misses.
    $compiled = app('blade.compiler')->compileString($source);
    if (preg_match('/@(?:include|if|elseif|else|endif|foreach|endforeach|forelse|empty|endforelse|php|endphp)\b/', $compiled)) {
        $uncompiledDirectives[] = basename($designTemplatePath);
    }
}
$verify($uncompiledDirectives === [], 'A Builder storefront template has an uncompiled Blade directive: ' . implode(', ', $uncompiledDirectives));
$slideshowSources = (string) file_get_contents($viewFile('design.img_text_slideshow')) . (string) file_get_contents($viewFile('design.img_text_slideshow_2'));
$verify(substr_count($slideshowSources, 'builder-slideshow-toggle') >= 2 && substr_count($slideshowSources, 'prefers-reduced-motion') >= 2 && substr_count($slideshowSources, 'keyboard: { enabled: true') >= 2, 'Functional slideshow controls do not cover pause, reduced motion, and keyboard operation.');
$nativeTemplateText = implode("\n", array_map(static fn (array $item): string => (string) file_get_contents($viewFile($item['view_path'])), $nativeCatalog));
$verify(! str_contains($nativeTemplateText, 'Configure products') && ! str_contains($nativeTemplateText, 'Configure articles'), 'A native module still exposes an unfinished English-only empty state.');
$renderCandidates = [];
foreach ($nativeCatalog as $item) {
    $renderCandidates[] = [
        'code'    => (string) $item['code'],
        'view'    => (string) $item['view_path'],
        'content' => is_array($item['make'] ?? null) ? $item['make'] : [],
    ];
}
foreach ($migratedUnits as $item) {
    $renderCandidates[] = [
        'code'    => (string) $item['type'],
        'view'    => (string) ShopifyRendererRegistry::viewFor((string) $item['type']),
        'content' => [],
    ];
}
$renderFailures = [];
foreach ($renderCandidates as $candidate) {
    try {
        // Automatic feeds intentionally execute a real catalog query. This
        // source-level renderer smoke test has no database, so provide the
        // already-normalized empty feed shape and cover queries in feature tests.
        $normalizedContent = in_array($candidate['code'], ['latest_products', 'bestseller'], true)
            ? array_merge($candidate['content'], ['title' => 'Products', 'sub_title' => '', 'products' => []])
            : DesignService::handleModuleContent($candidate['code'], $candidate['content']);
        foreach ([false, true] as $designMode) {
            $rendered = view($candidate['view'], [
                'code'      => $candidate['code'],
                'module_id' => 'builder-render-check',
                'view_path' => $candidate['view'],
                'content'   => $normalizedContent,
                'design'    => $designMode,
            ])->render();
            if ($designMode && trim($rendered) === '') {
                throw new RuntimeException('Design-mode renderer returned an empty module.');
            }
        }
    } catch (Throwable $exception) {
        $renderFailures[] = $candidate['code'] . ': ' . $exception->getMessage();
    }
}
$verify($renderFailures === [], 'An addable Builder component cannot render its normalized storefront view: ' . implode(' | ', $renderFailures));

// The editor uses Puck's native three-pane workbench, but its center preview
// must remain the actual storefront rather than generic component cards.
$builderClient           = (string) file_get_contents(dirname(__DIR__) . '/resources/beike/admin/js/builder/index.tsx');
$builderView             = (string) file_get_contents(dirname(__DIR__) . '/resources/beike/admin/views/pages/design/builder/puck.blade.php');
$chromeSettings          = (string) file_get_contents(dirname(__DIR__) . '/resources/beike/admin/js/builder/chrome-settings.tsx');
$nativeFields            = (string) file_get_contents(dirname(__DIR__) . '/resources/beike/admin/js/builder/native-component-fields.ts');
$designControllerSource  = (string) file_get_contents(dirname(__DIR__) . '/beike/Admin/Http/Controllers/DesignController.php');
$builderStateSource      = (string) file_get_contents(dirname(__DIR__) . '/beike/Services/BuilderStateService.php');
$settingRepoSource       = (string) file_get_contents(dirname(__DIR__) . '/beike/Repositories/SettingRepo.php');
$placementTemplateSource = implode("\n", array_map(static fn (string $path): string => (string) file_get_contents($path), [
    dirname(__DIR__) . '/themes/default/category.blade.php',
    dirname(__DIR__) . '/themes/default/pages/single.blade.php',
    dirname(__DIR__) . '/themes/default/product/product.blade.php',
]));
$verify(str_contains($builderClient, 'StorefrontThemePreview') && str_contains($builderClient, 'getSelectorForId') && str_contains($builderClient, '真实前台主题预览'), 'The Builder must render the real storefront and bind its module selection to Puck.');
$verify(! str_contains($builderClient, 'setThemeMode') && ! str_contains($builderClient, 'editorOpen') && str_contains($builderClient, 'overrides={editorOverrides}') && str_contains($builderClient, 'iframe={{ enabled: false }}') && str_contains($builderView, '.beike-puck-storefront-preview'), 'The native Puck workspace must stay visible while its center canvas renders the storefront.');
$builderConfig = (string) file_get_contents(dirname(__DIR__) . '/resources/beike/admin/js/builder/config.tsx');
$verify(str_contains($builderClient, 'name: "blocks"') && str_contains($builderClient, 'ExistingComponentInsertPanel') && str_contains($builderClient, 'type: "setData"') && str_contains($builderClient, 'puckConfig.components[item.type].defaultProps') && str_contains($builderClient, 'placement: page') && str_contains($builderClient, '添加模块') && str_contains($builderClient, '页面模板') && ! str_contains($builderConfig, 'label: "显示位置"'), 'Existing verified components must insert directly into the selected real storefront page without a redundant placement field.');
$verify(str_contains($builderClient, 'PageStructurePanel') && str_contains($builderClient, 'previewMatchesPlacement') && str_contains($builderClient, '({ ...previous, root: { ...previous.root, props: { ...previous.root.props, pageType: next } } })') && str_contains($builderClient, 'name: "outline"') && str_contains($builderClient, 'draggable') && str_contains($builderClient, 'moveModule') && str_contains($builderClient, 'type: "remove"'), 'Switching preview pages must preserve all modules while synchronizing the Puck root and providing drag, move, and delete controls.');
$verify(str_contains($builderClient, 'undoDelete') && str_contains($builderClient, '已删除“{deletedModule.label}”') && ! str_contains($builderClient, 'window.confirm'), 'Destructive editor actions must use recoverable or contextual confirmation flows instead of blocking browser dialogs.');
$verify(str_contains($builderConfig, 'sectionLayoutFields') && str_contains($builderConfig, '模块锚点（可选）') && str_contains($designServiceSource, '--builder-section-space-top'), 'Native and plugin modules must share bounded section spacing and anchor controls.');
$verify(str_contains($builderClient, 'name: "fields"') && str_contains($builderClient, '<Puck.Fields />') && str_contains($builderClient, 'onSelectChrome') && ! str_contains($builderClient, 'chromeOpen') && str_contains($chromeSettings, 'beike-puck-chrome-panel') && ! str_contains($chromeSettings, '<dialog'), 'Header and footer controls must remain inline with the real theme preview, not in a separate modal.');
$verify(str_contains($builderClient, '保存草稿') && str_contains($builderClient, '发布更改') && str_contains($builderClient, 'PublishDialog') && str_contains($builderClient, 'BuilderConflictDialog') && str_contains($builderClient, '下载我的修改') && str_contains($builderClient, 'HeaderMoreMenu') && str_contains($builderClient, '查看发布记录') && str_contains($builderClient, '导出 LONGOS 备份') && str_contains($builderClient, '导入 LONGOS 备份'), 'The Builder header must separate draft and publish actions and provide conflict recovery.');
$verify(str_contains($builderClient, 'onPublish={() => requestPublish()}') && str_contains($builderClient, 'Array.isArray(data?.content)') && str_contains($builderClient, 'data && Array.isArray(data.content) ? data : latest.current'), 'Header publishing must never pass a click event as Builder data, and legacy drafts without a content array must remain publishable.');
$verify(str_contains($builderClient, 'headerTitle="LONGOS 店铺编辑器"') && str_contains($builderView, '<title>LONGOS 店铺编辑器</title>') && str_contains($builderView, 'aria-label="LONGOS 店铺编辑器"'), 'The visual editor must use the LONGOS Editor product name consistently.');
$verify(str_contains($nativeFields, 'img_text_slideshow') && str_contains($nativeFields, '轮播内容') && str_contains($nativeFields, 'tab_product') && str_contains($nativeFields, '商品标签页') && str_contains($nativeFields, 'brandList') && str_contains($nativeFields, 'pageList') && str_contains($nativeFields, 'latest_products') && str_contains($nativeFields, 'bestseller') && str_contains($nativeFields, 'PaymentMethodsField') && str_contains($nativeFields, 'CommerceField') && str_contains($nativeFields, 'nativeSharedFields') && str_contains($nativeFields, 'getItemSummary') && str_contains($nativeFields, 'defaultItemProps'), 'Native Builder modules must expose their own real media, commerce, product-feed, and payment controls instead of a shared generic inspector.');
$verify(str_contains($builderClient, 'item.description') && str_contains($builderClient, '按用途、名称或功能关键词搜索') && ! str_contains($builderClient, 'bootstrap.shopifyRegistry.map'), 'The insert panel must use Chinese names and describe only supported native components.');
$verify(str_contains($designControllerSource, '$shopifyRegistry = [];') && ! str_contains($designControllerSource, '$component[\'status\'] ?? null) === \'MIGRATED\''), 'Migrated Shopify modules must not be loaded into the new editor inventory.');
$verify(str_contains($designControllerSource, 'BuilderStateService::DRAFT_SETTING') && str_contains($designControllerSource, "SettingRepo::storeValue('design_setting'") && str_contains($designControllerSource, 'if ($publish)') && str_contains($designControllerSource, 'readPersistedPublishedState') && str_contains($designControllerSource, "'revision_created'") && str_contains($designControllerSource, 'X-Builder-Draft-Version') && str_contains($designControllerSource, "'has_unpublished_changes'"), 'Draft, verified publish, revision recovery, and optimistic-concurrency boundaries are missing from the Builder controller.');
$verify(str_contains($builderStateSource, 'Auth::guard(AdminUser::AUTH_GUARD)->check()') && str_contains($builderStateSource, "request()->boolean('design')") && str_contains($builderStateSource, 'self::isPreviewRequest() ? self::draft() : self::published()'), 'Draft storefront preview must require an authenticated administrator.');
$verify(! str_contains($placementTemplateSource, "request('design')") && ! str_contains($placementTemplateSource, 'request("design")'), 'Storefront templates must not activate Builder-only UI from a public query parameter.');
$verify(str_contains($settingRepoSource, 'bool $clearCache = true') && str_contains($settingRepoSource, 'if ($clearCache)'), 'Builder autosave must be able to persist without clearing every application cache.');
$designRoutes = app('router')->getRoutes();
$builderRoute = $designRoutes->getByName('admin.design.index');
$verify($builderRoute !== null && $builderRoute->uri() === 'admin/design/builder' && str_contains($builderRoute->getActionName(), 'DesignController@index') && str_contains($designControllerSource, "return view('admin::pages.design.builder.puck', \$data);"), 'The design-builder entry route must resolve only to the Puck editor view.');

echo "Builder core assertions: PASS\n";
