<?php

namespace Beike\Services;

/**
 * Storefront rendering capability is intentionally separate from the scanner
 * and Puck schema registry. A component is marked MIGRATED only after this
 * registry points it at a purpose-built, safe LONGOS renderer.
 */
class ShopifyRendererRegistry
{
    /** @var array<string, string> Builder component code => Blade view. */
    private const VIEWS = [
        'shopify-image-banner'           => 'design.shopify_image_banner',
        'shopify-image-with-text'        => 'design.shopify_image_with_text',
        'shopify-announcement-bar'       => 'design.shopify_announcement_bar',
        'shopify-main-collection-banner' => 'design.shopify_main_collection_banner',
        'shopify-main-page'              => 'design.shopify_main_page',
        'shopify-page'                   => 'design.shopify_page',
        'shopify-featured-blog'          => 'design.shopify_featured_blog',
        'shopify-multirow'               => 'design.shopify_multirow',
        'shopify-collection-list'        => 'design.shopify_collection_list',
        'shopify-collapsible-content'    => 'design.shopify_collapsible_content',
        'shopify-multicolumn'            => 'design.shopify_multicolumn',
        'shopify-rich-text'              => 'design.shopify_rich_text',
        'shopify-video'                  => 'design.shopify_video',
        'shopify-slideshow'              => 'design.shopify_slideshow',
        'shopify-main-list-collections'  => 'design.shopify_main_list_collections',
    ];

    public static function viewFor(string $componentCode): ?string
    {
        return self::VIEWS[$componentCode] ?? null;
    }

    public static function isMigratedUnit(string $unitId): bool
    {
        return isset(self::VIEWS['shopify-' . $unitId]);
    }
}
