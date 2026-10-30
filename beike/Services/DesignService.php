<?php

/**
 * DesignService.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-07-14 20:57:37
 * @modified   2022-07-14 20:57:37
 */

namespace Beike\Services;

use Beike\Admin\Repositories\PageRepo;
use Beike\Repositories\BrandRepo;
use Beike\Repositories\ProductRepo;
use Beike\Shop\Http\Resources\BrandDetail;
use Beike\Shop\Services\ProductFeedService;
use Illuminate\Support\Str;

class DesignService
{
    public static function normalizeDesignSetting(mixed $designSetting): array
    {
        if (is_string($designSetting)) {
            $decoded = json_decode($designSetting, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $designSetting = $decoded;
            }
        }
        if (! is_array($designSetting)) {
            return BuilderPageSchema::envelope(['modules' => [], 'global_style' => self::normalizeGlobalStyle([]), 'app_embeds' => [], 'page_metadata' => self::normalizePageMetadata([])]);
        }

        return self::handleRequestModules($designSetting);
    }

    public static function handleRequestModules($modulesData): array
    {
        if (is_string($modulesData)) {
            $decoded = json_decode($modulesData, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $modulesData = $decoded;
            }
        }
        if (! is_array($modulesData)) {
            return BuilderPageSchema::envelope(['modules' => [], 'global_style' => self::normalizeGlobalStyle([]), 'app_embeds' => [], 'page_metadata' => self::normalizePageMetadata([])]);
        }
        $modulesData = BuilderDataMigrator::migrate($modulesData)['data'];
        // Preserve root-level protocol metadata before unwrapping `modules`.
        // In particular this retains `engine: puck` for an adapted save.
        $rootPayload = $modulesData;
        // Keep storefront-wide design controls beside the module collection.
        // Earlier releases only stored {modules: []}; retaining the default
        // makes existing shops fully backward compatible.
        $globalStyle  = self::normalizeGlobalStyle($modulesData['global_style'] ?? []);
        $appEmbeds    = self::normalizeAppEmbeds($modulesData['app_embeds'] ?? []);
        $pageMetadata = self::normalizePageMetadata($modulesData['page_metadata'] ?? []);
        // Very early builder versions stored the module list directly while
        // current versions wrap it in {modules: []}. Read both formats so an
        // upgrade cannot make an existing homepage look empty in the editor.
        $modulesData = array_key_exists('modules', $modulesData)
            ? $modulesData['modules']
            : (array_is_list($modulesData) ? $modulesData : []);
        if (! is_array($modulesData)) {
            return BuilderPageSchema::envelope(['modules' => [], 'global_style' => $globalStyle, 'app_embeds' => $appEmbeds, 'page_metadata' => $pageMetadata], $rootPayload);
        }
        if (empty($modulesData)) {
            return BuilderPageSchema::envelope(['modules' => [], 'global_style' => $globalStyle, 'app_embeds' => $appEmbeds, 'page_metadata' => $pageMetadata], $rootPayload);
        }

        $normalizedModules = [];
        $moduleIds         = [];
        // Keep the builder bounded even when an imported file or a plugin
        // submits an unexpectedly large module list.
        foreach (array_slice($modulesData, 0, 100) as $moduleData) {
            if (! is_array($moduleData)) {
                continue;
            }
            $code = trim((string) ($moduleData['code'] ?? ''));
            if (! preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $code)) {
                continue;
            }
            // The visual builder deliberately has no subscription surfaces.
            // Retire these legacy/Shopify imports at the normalized data
            // boundary so they cannot reappear through an old backup, API
            // write, or generated Shopify registry.
            if (self::isRetiredBuilderModule($code)) {
                continue;
            }
            $moduleData['code'] = $code;

            $moduleId = trim((string) ($moduleData['module_id'] ?? ''));
            if (! preg_match('/^[A-Za-z0-9_-]{1,96}$/', $moduleId) || isset($moduleIds[$moduleId])) {
                do {
                    $moduleId = Str::random(16);
                } while (isset($moduleIds[$moduleId]));
            }
            $moduleData['module_id'] = $moduleId;
            $moduleIds[$moduleId]    = true;

            $name               = $moduleData['name'] ?? $code;
            $moduleData['name'] = is_scalar($name) ? mb_substr(trim(strip_tags((string) $name)), 0, 100) : $code;

            $viewPath     = trim((string) ($moduleData['view_path'] ?? ''));
            $isCoreView   = preg_match('/^design\.[A-Za-z][A-Za-z0-9_.\/-]{0,191}$/', $viewPath);
            $isPluginView = preg_match('/^([A-Za-z][A-Za-z0-9_]{0,63})::[A-Za-z][A-Za-z0-9_.\/-]{0,191}$/', $viewPath, $viewMatches)
                && self::isActiveDesignPlugin((string) $viewMatches[1]);
            $validViewPath = $viewPath === ''
                || ($viewPath !== 'design.' && ! str_contains($viewPath, '..') && ($isCoreView || $isPluginView));
            if (! $validViewPath) {
                // Fall back to the module's default design namespace. This
                // keeps a malformed import from rendering an arbitrary view.
                $viewPath = '';
            }
            $moduleData['view_path'] = $viewPath;

            // Existing modules without this field remain published. This keeps
            // older design_setting data fully backward compatible.
            $moduleData['visible'] = self::normalizeBoolean($moduleData['visible'] ?? true, true);

            $moduleData = BuilderPageSchema::normalizeModule($moduleData);
            $moduleData = BuilderComponentMigrator::migrate($moduleData);
            $moduleData = self::sanitizeModuleData($moduleData);

            $normalizedModules[] = $moduleData;
        }

        return BuilderPageSchema::envelope([
            'modules'       => $normalizedModules,
            'global_style'  => $globalStyle,
            'app_embeds'    => $appEmbeds,
            'page_metadata' => $pageMetadata,
        ], $rootPayload);
    }

    /**
     * Theme app embeds are data-only, independent from the page section tree.
     * Keeping the payload small and whitelisted lets an extension participate
     * in the editor without executing extension code in the admin browser.
     */
    public static function normalizeAppEmbeds(mixed $appEmbeds): array
    {
        if (! is_array($appEmbeds)) {
            return [];
        }

        $normalized = [];
        foreach (array_slice($appEmbeds, 0, 50, true) as $handle => $embed) {
            if (! is_string($handle) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $handle)) {
                continue;
            }
            $enabled             = is_array($embed) ? ($embed['enabled'] ?? true) : $embed;
            $normalized[$handle] = ['enabled' => self::normalizeBoolean($enabled, true)];
        }

        return $normalized;
    }

    /** Normalize editor-owned page metadata without overriding CMS page records. */
    public static function normalizePageMetadata(mixed $metadata): array
    {
        $metadata = is_array($metadata) ? $metadata : [];
        $text     = static fn (mixed $value, int $limit): string => is_scalar($value) ? mb_substr(trim(strip_tags((string) $value)), 0, $limit) : '';
        $slug     = strtolower($text($metadata['slug'] ?? '', 160));
        $slug     = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
        $image    = is_scalar($metadata['featured_image'] ?? null) ? trim((string) $metadata['featured_image']) : '';

        return [
            'title'           => $text($metadata['title'] ?? '', 160),
            'slug'            => mb_substr($slug, 0, 160),
            'seo_title'       => $text($metadata['seo_title'] ?? '', 160),
            'seo_description' => $text($metadata['seo_description'] ?? '', 320),
            'featured_image'  => preg_match('/^(?:https?:\/\/|\/)/i', $image) ? mb_substr($image, 0, 2048) : '',
        ];
    }

    /** Read the published app-embed state while preserving extension defaults for existing stores. */
    public static function isAppEmbedEnabled(string $handle, bool $default = true): bool
    {
        if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $handle)) {
            return $default;
        }
        $settings = self::normalizeDesignSetting(system_setting('base.design_setting'));
        $embed    = $settings['app_embeds'][$handle]['enabled'] ?? $default;

        return self::normalizeBoolean($embed, $default);
    }

    /** Normalize the storefront announcement bar shared by desktop and mobile header. */
    public static function normalizeHeaderSetting(mixed $headerSetting): array
    {
        $default = [
            'header_ads' => [
                'active'   => false,
                'bg_color' => '#101828',
                'color'    => '#FFFFFF',
                'items'    => [],
            ],
        ];
        if (! is_array($headerSetting)) {
            return $default;
        }
        $setting = array_replace_recursive($default, $headerSetting);
        $ads     = is_array($setting['header_ads'] ?? null) ? $setting['header_ads'] : $default['header_ads'];
        $color   = static function (mixed $value, string $fallback): string {
            $value = is_string($value) ? trim($value) : '';

            return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? strtoupper($value) : $fallback;
        };
        $items           = is_array($ads['items'] ?? null) ? $ads['items'] : [];
        $normalizedItems = [];
        foreach (array_slice($items, 0, 12) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = $item['title'] ?? [];
            $link  = is_array($item['link'] ?? null) ? $item['link'] : [];
            if (! is_array($title)) {
                $title = is_scalar($title) ? [locale() => (string) $title] : [];
            }
            $normalizedItems[] = [
                'title' => $title,
                'link'  => [
                    'type'       => is_scalar($link['type'] ?? null) ? (string) $link['type'] : 'custom',
                    'value'      => is_scalar($link['value'] ?? null) ? (string) $link['value'] : '',
                    'text'       => is_array($link['text'] ?? null) ? $link['text'] : [],
                    'new_window' => self::normalizeBoolean($link['new_window'] ?? false, false),
                ],
            ];
        }

        // Retire only the untouched black demo promotion shipped by older
        // releases. Merchant-authored announcement bars, including custom
        // black ones, remain fully supported in the visual builder.
        $legacyDemoTitles = [
            'Spring Fashion Refresh Season: Limited Time 50% Off on New Arrivals',
            'Spring Trend Collection Launch: Special 50% Off Offer',
            'Spring Affection Sale Event: Up to 50% Off on All Items',
            'Spring New Arrivals Mega Sale: Enjoy 50% Off on Fashion',
            'Spring Style Promotion: Select Items Starting at 50% Off',
            '春日时尚焕新季 全场新品限时5折起',
            '春季潮流新品发布 特惠5折起购',
            '春意浓情特卖会 全场5折起狂欢',
            '春季新品大放价 5折起享时尚',
            '春日风尚大促销 精选商品5折起',
        ];
        $isLegacyDemo = $normalizedItems !== [] && collect($normalizedItems)->every(
            static fn (array $item): bool => collect($item['title'] ?? [])->contains(
                static fn (mixed $title): bool => in_array(trim((string) $title), $legacyDemoTitles, true)
            )
        );
        $active = self::normalizeBoolean($ads['active'] ?? false, false) && ! $isLegacyDemo;

        return ['header_ads' => [
            'active'   => $active,
            'bg_color' => $color($ads['bg_color'] ?? null, '#101828'),
            'color'    => $color($ads['color'] ?? null, '#FFFFFF'),
            'items'    => $normalizedItems,
        ]];
    }

    /** Interpret JSON, form, and legacy boolean values consistently. */
    private static function normalizeBoolean(mixed $value, bool $fallback): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off', '' => false,
                default => $fallback,
            };
        }

        return $fallback;
    }

    /**
     * Normalize the storefront design tokens shared by every template.
     * Values are deliberately bounded so an invalid API payload cannot make
     * the shop unreadable or inject unsafe values into the inline CSS tokens.
     */
    public static function normalizeGlobalStyle(mixed $globalStyle): array
    {
        $globalStyle = is_array($globalStyle) ? $globalStyle : [];
        // Earlier Builder releases seeded every store with this blue/orange
        // token set. It is a product default rather than merchant-authored
        // branding, so upgrade the exact untouched set to the current
        // Shopifi design language while preserving any partial/custom choice.
        $legacyDefaults = [
            'primary_color'    => '#155EEF',
            'accent_color'     => '#F79009',
            'background_color' => '#FFFFFF',
            'text_color'       => '#101828',
            'border_radius'    => 10,
            'page_width'       => 1400,
            'section_spacing'  => 32,
            'grid_gap'         => 16,
        ];
        $isUntouchedLegacyDefault = true;
        foreach ($legacyDefaults as $key => $value) {
            $actual                   = $globalStyle[$key] ?? null;
            $isUntouchedLegacyDefault = $isUntouchedLegacyDefault && (is_int($value)
                ? (int) $actual                === $value
                : strtoupper((string) $actual) === $value);
        }
        if ($isUntouchedLegacyDefault) {
            $globalStyle = [];
        }
        $fontSize    = (int) ($globalStyle['font_size'] ?? 15);
        $color       = static function (mixed $value, string $fallback): string {
            $value = is_string($value) ? trim($value) : '';

            return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? strtoupper($value) : $fallback;
        };
        $fontFamily  = in_array($globalStyle['font_family'] ?? '', ['system', 'serif', 'rounded'], true)
            ? $globalStyle['font_family']
            : 'system';

        return [
            'font_size'        => min(20, max(13, $fontSize)),
            'font_family'      => $fontFamily,
            // Shopifi's light commerce track: black action ink on a quiet
            // cream canvas, with aloe reserved for the supporting accent.
            'primary_color'    => $color($globalStyle['primary_color'] ?? null, '#000000'),
            'accent_color'     => $color($globalStyle['accent_color'] ?? null, '#C1FBD4'),
            'background_color' => $color($globalStyle['background_color'] ?? null, '#FBFBF5'),
            'text_color'       => $color($globalStyle['text_color'] ?? null, '#000000'),
            'border_radius'    => min(24, max(0, (int) ($globalStyle['border_radius'] ?? 12))),
            'page_width'       => min(1600, max(1000, (int) ($globalStyle['page_width'] ?? 1440))),
            'section_spacing'  => min(160, max(0, (int) ($globalStyle['section_spacing'] ?? 64))),
            'grid_gap'         => min(40, max(4, (int) ($globalStyle['grid_gap'] ?? 16))),
            'card_style'       => in_array($globalStyle['card_style'] ?? '', ['standard', 'card'], true) ? $globalStyle['card_style'] : 'card',
            // Keep this a strict boolean because it becomes a body data
            // attribute consumed by every frontend transition and animation.
            'reduce_motion'    => in_array($globalStyle['reduce_motion'] ?? false, [true, 1, '1', 'true'], true),
        ];
    }

    /**
     * @throws \Exception
     */
    public static function handleModuleContent($moduleCode, $content)
    {
        $content                = is_array($content) ? $content : [];
        $productCodes           = ['product', 'category', 'latest'];
        $content['module_code'] = $moduleCode;
        if ($moduleCode === 'shopify-rich-text') {
            return self::handleShopifyRichText($content);
        } elseif ($moduleCode === 'shopify-main-page') {
            return self::handleShopifyMainPage($content);
        } elseif ($moduleCode === 'shopify-main-collection-banner') {
            return self::handleShopifyMainCollectionBanner($content);
        } elseif ($moduleCode === 'shopify-announcement-bar') {
            $content['announcements'] = self::normalizeHeaderSetting(system_setting('base.header_setting'))['header_ads'];

            return $content;
        } elseif ($moduleCode === 'shopify-page') {
            return self::handleShopifyPage($content);
        } elseif ($moduleCode === 'shopify-featured-blog') {
            return self::handleShopifyFeaturedBlog($content);
        } elseif ($moduleCode === 'shopify-multirow') {
            return self::handleShopifyMultirow($content);
        } elseif ($moduleCode === 'shopify-collection-list') {
            return self::handleShopifyCollectionList($content);
        } elseif ($moduleCode === 'shopify-collapsible-content') {
            return self::handleShopifyCollapsibleContent($content);
        } elseif ($moduleCode === 'shopify-multicolumn') {
            return self::handleShopifyMulticolumn($content);
        } elseif ($moduleCode === 'shopify-image-with-text') {
            return self::handleShopifyImageWithText($content);
        } elseif ($moduleCode === 'shopify-image-banner') {
            return self::handleShopifyImageBanner($content);
        } elseif ($moduleCode === 'shopify-video') {
            return self::handleShopifyVideo($content);
        } elseif ($moduleCode === 'shopify-slideshow') {
            return self::handleShopifySlideshow($content);
        } elseif ($moduleCode === 'shopify-main-list-collections') {
            return self::handleShopifyMainListCollections($content);
        } elseif ($moduleCode == 'slideshow') {
            return self::handleSlideShow($content);
        } elseif (in_array($moduleCode, ['img_text_slideshow', 'img_text_slideshow_2'])) {
            return self::handleImgTextSlideShow($content);
        } elseif ($moduleCode == 'img_text_banner') {
            return self::handleImgTextBanner($content);
        } elseif (in_array($moduleCode, ['image400', 'image401', 'image402', 'image403', 'image100', 'image200', 'image300', 'image301'])) {
            return self::handleBanner($content);
        } elseif ($moduleCode == 'brand') {
            return self::handleBrand($content);
        } elseif ($moduleCode == 'tab_product') {
            return self::handleTabProducts($content);
        } elseif (in_array($moduleCode, ['latest_products', 'bestseller'], true)) {
            return self::handleAutomaticProducts($moduleCode, $content);
        } elseif (in_array($moduleCode, $productCodes)) {
            return self::handleProducts($content);
        } elseif (in_array($moduleCode, ['icons', 'img_text_banner_multiple'])) {
            return self::handleIcons($content);
        } elseif ($moduleCode == 'rich_text') {
            return self::handleRichText($content);
        } elseif ($moduleCode == 'page') {
            return self::handlePage($content);
        } elseif ($moduleCode === 'promo_bar') {
            return self::handleCallToAction($content);
        } elseif ($moduleCode == 'custom_code') {
            return self::handleCustomCode($content);
        } elseif ($moduleCode == 'faq') {
            return self::handleFaq($content);
        } elseif ($moduleCode == 'trust_cards') {
            return self::handleTrustCards($content);
        } elseif ($moduleCode == 'testimonials') {
            return self::handleTestimonials($content);
        } elseif ($moduleCode == 'campaign_offer') {
            return self::handleCampaignOffer($content);
        } elseif ($moduleCode == 'payment_trust') {
            return self::handlePaymentTrust($content);
        } elseif ($moduleCode == 'story_block') {
            return self::handleStoryBlock($content);
        } elseif ($moduleCode == 'feature_grid') {
            return self::handleFeatureGrid($content);
        } elseif ($moduleCode == 'social_proof') {
            return self::handleSocialProof($content);
        }

        return hook_filter('service.design.module.content', $content);
    }

    private static function handleCallToAction(array $content): array
    {
        $content['title']       = self::localizedValue($content['title'] ?? '');
        $content['sub_title']   = self::localizedValue($content['sub_title'] ?? '');
        $content['button_text'] = self::localizedValue($content['button_text'] ?? '') ?: trans('common.view_more');
        $link                   = is_array($content['link'] ?? null) ? $content['link'] : [];
        $content['link']        = ! empty($link['type']) && array_key_exists('value', $link)
            ? self::handleLink($link['type'], $link['value'])
            : '';
        $content['new_window']  = self::normalizeBoolean($link['new_window'] ?? false, false);
        $content['module_size'] = $content['module_size'] ?? 'container-fluid';
        $content['style']       = $content['style']       ?? [];

        return $content;
    }

    /**
     * Render a Shopify-style custom section without evaluating server-side code.
     * The resulting document is placed in a sandboxed iframe by the theme, so
     * custom CSS cannot leak into checkout, account, or other storefront pages.
     */
    private static function handleCustomCode(array $content): array
    {
        $settings = self::normalizeCustomCodeSettings($content['settings'] ?? '{}');
        $html     = self::renderCustomCodeTemplate(self::localizedValue($content['html'] ?? ''), $settings);
        $css      = self::sanitizeCustomCodeCss(self::localizedValue($content['css'] ?? ''));

        $config = HtmlPurifierConfig::create();
        // HTMLPurifier's installed HTML definition is intentionally
        // conservative. Keep the custom-section vocabulary within its
        // supported elements so valid sections are preserved without runtime
        // warnings or an unsafe broad HTML5 definition.
        $config->set('HTML.Allowed', 'a[href|title|target|rel|class],abbr[title],blockquote[cite|class],br,code[class],div[class|id|title],em[class],h1[class],h2[class],h3[class],h4[class],h5[class],h6[class],hr,img[src|alt|width|height|class],li[class],ol[class],p[class],pre[class],small[class],span[class|id|title],strong[class],table[class],tbody,td[colspan|rowspan|class],th[colspan|rowspan|class],thead,tr,ul[class]');
        $config->set('Attr.EnableID', true);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('URI.DisableExternalResources', true);
        $config->set('CSS.AllowedProperties', []);

        $html = (new \HTMLPurifier($config))->purify($html);

        $content['height']   = min(1200, max(160, (int) ($content['height'] ?? 360)));
        $content['title']    = self::localizedValue($content['title'] ?? '') ?: 'Custom section';
        $content['html']     = $html;
        $content['css']      = $css;
        $content['settings'] = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $content['document'] = '<!doctype html><html lang="' . e(locale()) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>*,*:before,*:after{box-sizing:border-box}html,body{margin:0;min-height:100%;max-width:100%;font-family:Arial,sans-serif;color:#171717}body{padding:1px;overflow-wrap:anywhere}img,video,svg,canvas{display:block;max-width:100%}img,video{height:auto}iframe,table,pre{max-width:100%}table{display:block;overflow-x:auto}pre{overflow:auto;white-space:pre-wrap}'
            . $css
            . '@media(prefers-reduced-motion:reduce){*,*:before,*:after{scroll-behavior:auto!important;animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}}'
            . '</style></head><body>' . $html . '</body></html>';

        return $content;
    }

    private static function handleFaq(array $content): array
    {
        $content['title']         = self::localizedValue($content['title'] ?? '');
        $content['sub_title']     = self::localizedValue($content['sub_title'] ?? '');
        $content['module_size']   = $content['module_size'] ?? 'container-fluid';
        $content['style_variant'] = in_array($content['style_variant'] ?? '', ['divided', 'cards'], true) ? $content['style_variant'] : 'divided';
        $content['open_first']    = self::normalizeBoolean($content['open_first'] ?? true, true);
        $items                    = is_array($content['items'] ?? null) ? $content['items'] : [];
        $normalizedItems          = [];

        foreach (array_slice($items, 0, 12) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $question = trim(strip_tags(self::localizedValue($item['question'] ?? '')));
            $answer   = trim(strip_tags(self::localizedValue($item['answer'] ?? '')));
            if ($question === '' && $answer === '') {
                continue;
            }
            $normalizedItems[] = compact('question', 'answer');
        }

        $content['items'] = $normalizedItems;

        return $content;
    }

    private static function handleTrustCards(array $content): array
    {
        $content['title']         = self::localizedValue($content['title'] ?? '');
        $content['sub_title']     = self::localizedValue($content['sub_title'] ?? '');
        $content['module_size']   = $content['module_size'] ?? 'container-fluid';
        $content                  = self::normalizeResponsiveColumns($content, 'columns', 3, 4, 2, 1);
        $content['style_variant'] = in_array($content['style_variant'] ?? '', ['soft', 'outline', 'contrast'], true) ? $content['style_variant'] : 'soft';
        $items                    = is_array($content['items'] ?? null) ? $content['items'] : [];
        $normalizedItems          = [];

        foreach (array_slice($items, 0, 6) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $link              = is_array($item['link'] ?? null) ? $item['link'] : [];
            $normalized        = [
                'icon'        => mb_substr(trim(strip_tags((string) ($item['icon'] ?? ''))), 0, 8),
                'title'       => trim(strip_tags(self::localizedValue($item['title'] ?? ''))),
                'description' => trim(strip_tags(self::localizedValue($item['description'] ?? ''))),
                'link'        => self::handleLink($link['type'] ?? '', $link['value'] ?? ''),
                'new_window'  => self::normalizeBoolean($link['new_window'] ?? false, false),
            ];
            if ($normalized['icon'] !== '' || $normalized['title'] !== '' || $normalized['description'] !== '') {
                $normalizedItems[] = $normalized;
            }
        }

        $content['items'] = $normalizedItems;

        return $content;
    }

    private static function handleTestimonials(array $content): array
    {
        $content['title']         = self::localizedValue($content['title'] ?? '');
        $content['sub_title']     = self::localizedValue($content['sub_title'] ?? '');
        $content['module_size']   = $content['module_size'] ?? 'container-fluid';
        $content                  = self::normalizeResponsiveColumns($content, 'columns', 3, 4, 2, 1);
        $content['style_variant'] = in_array($content['style_variant'] ?? '', ['cards', 'soft', 'minimal'], true) ? $content['style_variant'] : 'cards';
        $items                    = is_array($content['items'] ?? null) ? $content['items'] : [];
        $normalizedItems          = [];

        foreach (array_slice($items, 0, 8) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $quote = trim(strip_tags(self::localizedValue($item['quote'] ?? '')));
            if ($quote === '') {
                continue;
            }
            $normalizedItems[] = [
                'quote'  => $quote,
                'name'   => trim(strip_tags(self::localizedValue($item['name'] ?? ''))),
                'detail' => trim(strip_tags(self::localizedValue($item['detail'] ?? ''))),
                'rating' => min(5, max(1, (int) ($item['rating'] ?? 5))),
            ];
        }

        $content['items'] = $normalizedItems;

        return $content;
    }

    private static function handleCampaignOffer(array $content): array
    {
        $content['title']        = self::localizedValue($content['title'] ?? '');
        $content['sub_title']    = self::localizedValue($content['sub_title'] ?? '');
        $content['module_size']  = $content['module_size'] ?? 'container-fluid';
        $content['placement']    = self::normalizePlacement($content['placement'] ?? 'home');
        $content['code_label']   = self::localizedValue($content['code_label'] ?? '');
        $content['code']         = strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($content['code'] ?? '')) ?? '', 0, 48));
        $content['terms']        = self::localizedValue($content['terms'] ?? '');
        $timerMode               = $content['timer_mode'] ?? 'none';
        $content['timer_mode']   = in_array($timerMode, ['none', 'daily', 'fixed'], true) ? $timerMode : 'none';
        $endsAt                  = $content['ends_at'] ?? '';
        $content['ends_at']      = '';
        if ($content['timer_mode'] === 'fixed' && is_string($endsAt) && trim($endsAt) !== '') {
            try {
                // Persist an explicit offset so the same campaign expires at
                // one instant for customers in every browser timezone.
                $content['ends_at'] = \Carbon\Carbon::parse(
                    mb_substr(trim($endsAt), 0, 64),
                    config('app.timezone', 'UTC')
                )->toIso8601String();
            } catch (\Throwable) {
                $content['ends_at'] = '';
            }
        }
        if ($content['timer_mode'] === 'fixed' && $content['ends_at'] === '') {
            $content['timer_mode'] = 'none';
        }
        $offers                  = is_array($content['offers'] ?? null) ? $content['offers'] : [];
        $normalizedOffers        = [];

        foreach (array_slice($offers, 0, 4) as $offer) {
            if (! is_array($offer)) {
                continue;
            }
            $normalizedOffers[] = [
                'quantity'      => min(99, max(1, (int) ($offer['quantity'] ?? 1))),
                'condition'     => self::localizedValue($offer['condition'] ?? ''),
                'benefit'       => self::localizedValue($offer['benefit'] ?? ''),
                'description'   => self::localizedValue($offer['description'] ?? ''),
                'badge'         => self::localizedValue($offer['badge'] ?? ''),
                'best'          => self::normalizeBoolean($offer['best'] ?? false, false),
                'free_shipping' => self::normalizeBoolean($offer['free_shipping'] ?? false, false),
            ];
        }
        $content['offers'] = $normalizedOffers;

        return $content;
    }

    private static function handlePaymentTrust(array $content): array
    {
        $content['title']          = self::localizedValue($content['title'] ?? '');
        $content['sub_title']      = self::localizedValue($content['sub_title'] ?? '');
        $content['status_text']    = self::localizedValue($content['status_text'] ?? '');
        $content['payments_title'] = self::localizedValue($content['payments_title'] ?? '');
        $content['payments_sub']   = self::localizedValue($content['payments_sub'] ?? '');
        $content['module_size']    = $content['module_size'] ?? 'container-fluid';
        $content['placement']      = self::normalizePlacement($content['placement'] ?? 'product');
        $benefits                  = is_array($content['benefits'] ?? null) ? $content['benefits'] : [];
        $normalizedBenefits        = [];
        foreach (array_slice($benefits, 0, 4) as $benefit) {
            if (! is_array($benefit)) {
                continue;
            }
            $normalizedBenefits[] = [
                'icon'  => mb_substr(trim(strip_tags((string) ($benefit['icon'] ?? '✓'))), 0, 8),
                'title' => self::localizedValue($benefit['title'] ?? ''),
                'text'  => self::localizedValue($benefit['text'] ?? ''),
                'gold'  => self::normalizeBoolean($benefit['gold'] ?? false, false),
            ];
        }
        $content['benefits'] = $normalizedBenefits;
        $supportedPayments   = self::supportedPaymentMethods();
        $payments            = is_array($content['payments'] ?? null) ? $content['payments'] : [];
        $content['payments'] = array_values(array_intersect($supportedPayments, array_map('strval', $payments)));

        return $content;
    }

    /** A modern editorial image-and-copy section, usable on home and product pages. */
    private static function handleStoryBlock(array $content): array
    {
        $image     = $content['image'] ?? '';
        $imageData = is_array($image) ? $image : [];
        $link      = is_array($content['link'] ?? null) ? $content['link'] : [];

        $content['module_size']    = $content['module_size'] ?? 'container-fluid';
        $content['placement']      = self::normalizePlacement($content['placement'] ?? 'home');
        $content['eyebrow']        = trim(strip_tags(self::localizedValue($content['eyebrow'] ?? '')));
        $content['title']          = trim(strip_tags(self::localizedValue($content['title'] ?? '')));
        $content['description']    = trim(strip_tags(self::localizedValue($content['description'] ?? '')));
        $content['button_text']    = trim(strip_tags(self::localizedValue($content['button_text'] ?? '')));
        $content['image']          = self::safeImageSource(is_string($image) ? $image : ($imageData['src'] ?? ''));
        $content['image_alt']      = trim(strip_tags(self::localizedValue($imageData['alt'] ?? $content['image_alt'] ?? '')));
        $content['image_position'] = in_array($content['image_position'] ?? '', ['left', 'right'], true) ? $content['image_position'] : 'right';
        $content['theme']          = in_array($content['theme'] ?? '', ['light', 'ink', 'sand'], true) ? $content['theme'] : 'light';
        $content['link']           = ! empty($link['type']) && array_key_exists('value', $link)
            ? self::handleLink($link['type'], $link['value'])
            : '';
        $content['new_window'] = self::normalizeBoolean($link['new_window'] ?? false, false);

        return $content;
    }

    /** Image-led category, collection, or campaign cards. */
    private static function handleFeatureGrid(array $content): array
    {
        $content['module_size'] = $content['module_size'] ?? 'container-fluid';
        $content['placement']   = self::normalizePlacement($content['placement'] ?? 'home');
        $content['title']       = trim(strip_tags(self::localizedValue($content['title'] ?? '')));
        $content['sub_title']   = trim(strip_tags(self::localizedValue($content['sub_title'] ?? '')));
        $content                = self::normalizeResponsiveColumns($content, 'columns', 3, 4, 2, 1);
        $content['image_ratio'] = in_array($content['image_ratio'] ?? '', ['landscape', 'square', 'portrait'], true) ? $content['image_ratio'] : 'landscape';
        $content['card_style']  = in_array($content['card_style'] ?? '', ['editorial', 'bordered', 'minimal'], true) ? $content['card_style'] : 'editorial';
        $items                  = is_array($content['items'] ?? null) ? $content['items'] : [];
        $normalizedItems        = [];

        foreach (array_slice($items, 0, 8) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $image             = $item['image'] ?? '';
            $imageData         = is_array($image) ? $image : [];
            $link              = is_array($item['link'] ?? null) ? $item['link'] : [];
            $normalized        = [
                'image'      => self::safeImageSource(is_string($image) ? $image : (string) ($imageData['src'] ?? '')),
                'image_alt'  => trim(strip_tags(self::localizedValue($imageData['alt'] ?? $item['image_alt'] ?? ''))),
                'eyebrow'    => trim(strip_tags(self::localizedValue($item['eyebrow'] ?? ''))),
                'title'      => trim(strip_tags(self::localizedValue($item['title'] ?? ''))),
                'text'       => trim(strip_tags(self::localizedValue($item['text'] ?? ''))),
                'link_label' => trim(strip_tags(self::localizedValue($item['link_label'] ?? ''))),
                'link'       => self::handleLink($link['type'] ?? '', $link['value'] ?? ''),
                'new_window' => self::normalizeBoolean($link['new_window'] ?? false, false),
            ];
            if ($normalized['image'] !== '' || $normalized['title'] !== '' || $normalized['text'] !== '') {
                $normalizedItems[] = $normalized;
            }
        }

        $content['items'] = $normalizedItems;

        return $content;
    }

    /** Compact proof points for a storefront or a product-detail purchase area. */
    private static function handleSocialProof(array $content): array
    {
        $content['module_size'] = $content['module_size'] ?? 'container-fluid';
        $content['placement']   = self::normalizePlacement($content['placement'] ?? 'both');
        $content['title']       = trim(strip_tags(self::localizedValue($content['title'] ?? '')));
        $content['sub_title']   = trim(strip_tags(self::localizedValue($content['sub_title'] ?? '')));
        $content['theme']       = in_array($content['theme'] ?? '', ['light', 'ink', 'sand'], true) ? $content['theme'] : 'ink';
        $items                  = is_array($content['items'] ?? null) ? $content['items'] : [];
        $normalizedItems        = [];

        foreach (array_slice($items, 0, 5) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $value = mb_substr(trim(strip_tags(self::localizedValue($item['value'] ?? ''))), 0, 32);
            $label = mb_substr(trim(strip_tags(self::localizedValue($item['label'] ?? ''))), 0, 90);
            if ($value === '' && $label === '') {
                continue;
            }
            $normalizedItems[] = [
                'value'  => $value,
                'label'  => $label,
                'detail' => mb_substr(trim(strip_tags(self::localizedValue($item['detail'] ?? ''))), 0, 140),
            ];
        }

        $content['items'] = $normalizedItems;

        return $content;
    }

    /** Modules can be placed in home, product, category, content-page, or all storefront templates. */
    public static function getPlacementModules(string $placement): array
    {
        $designMode = BuilderStateService::isPreviewRequest();
        $settings   = BuilderStateService::storefrontState()['design'];
        $modules    = [];
        foreach ($settings['modules'] as $module) {
            if (! $designMode && ($module['visible'] ?? true) === false) {
                continue;
            }
            $modulePlacement = self::normalizePlacement($module['content']['placement'] ?? 'home');
            if (! self::matchesPlacement($modulePlacement, $placement)) {
                continue;
            }
            $code          = $module['code']      ?? '';
            $moduleId      = $module['module_id'] ?? '';
            $viewPath      = $module['view_path'] ?? '';
            $viewPath      = $viewPath ?: ShopifyRendererRegistry::viewFor($code) ?: (str_starts_with($code, 'shopify-') ? 'design.shopify_migrated' : "design.{$code}");
            $validViewPath = is_string($viewPath)
                && ! str_contains($viewPath, '..')
                && preg_match('/^(?:[A-Za-z][A-Za-z0-9_]*::)?[A-Za-z0-9_.\/-]+$/', $viewPath);
            if (! is_string($code) || ! is_string($moduleId) || $code === '' || $moduleId === '' || ! $validViewPath || ! view()->exists($viewPath)) {
                if ($designMode) {
                    $modules[] = self::previewErrorModule($moduleId, (string) $code, count($modules));
                }

                continue;
            }
            if (str_contains($viewPath, '::') && ! app('plugin')->checkActive(Str::before($viewPath, '::'))) {
                continue;
            }

            try {
                $moduleContent = self::handleModuleContent($code, $module['content'] ?? []);
                $moduleData    = [
                    'code'      => $code,
                    'module_id' => $moduleId,
                    'view_path' => $viewPath,
                    'content'   => $moduleContent,
                    'visible'   => $module['visible'] ?? true,
                    'design'    => $designMode,
                ];
                // Product and category templates render these blocks outside
                // HomeController. Render here so a bad plugin/template is
                // isolated just like a homepage section instead of turning a
                // product or category page into a 500 response.
                $moduleData['html'] = self::wrapResponsiveModuleHtml(
                    view($viewPath, $moduleData)->render(),
                    $module['content'] ?? [],
                    $moduleId,
                    (string) ($module['code'] ?? '')
                );
            } catch (\Throwable $exception) {
                logger()->warning('Skipping an invalid placement design module.', [
                    'module_id' => $moduleId,
                    'code'      => $code,
                    'exception' => $exception->getMessage(),
                ]);

                if ($designMode) {
                    $modules[] = self::previewErrorModule($moduleId, (string) $code, count($modules));
                }

                continue;
            }

            $modules[] = $moduleData;
        }

        return $modules;
    }

    /**
     * Render one normalized Builder module through the exact storefront view.
     *
     * The visual editor uses this for its low-latency fragment preview. Keeping
     * rendering here prevents the browser from maintaining a second, gradually
     * diverging implementation of every theme and plugin section.
     *
     * @throws \InvalidArgumentException
     */
    public static function renderBuilderModule(array $module, bool $designMode = true): array
    {
        $code     = is_string($module['code'] ?? null) ? $module['code'] : '';
        $moduleId = is_string($module['module_id'] ?? null) ? $module['module_id'] : '';
        if (! preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $code)
            || ! preg_match('/^[A-Za-z0-9_-]{1,96}$/', $moduleId)) {
            throw new \InvalidArgumentException('Invalid Builder module identity.');
        }

        $viewPath      = is_string($module['view_path'] ?? null) ? trim($module['view_path']) : '';
        $viewPath      = $viewPath ?: ShopifyRendererRegistry::viewFor($code) ?: (str_starts_with($code, 'shopify-') ? 'design.shopify_migrated' : "design.{$code}");
        $validViewPath = ! str_contains($viewPath, '..')
            && preg_match('/^(?:[A-Za-z][A-Za-z0-9_]*::)?[A-Za-z0-9_.\/-]+$/', $viewPath);
        if (! $validViewPath || ! view()->exists($viewPath)) {
            throw new \InvalidArgumentException('The Builder module view is unavailable.');
        }
        if (str_contains($viewPath, '::') && ! self::isActiveDesignPlugin(Str::before($viewPath, '::'))) {
            throw new \InvalidArgumentException('The Builder module plugin is inactive.');
        }

        $rawContent    = is_array($module['content'] ?? null) ? $module['content'] : [];
        $moduleContent = self::handleModuleContent($code, $rawContent);
        $moduleData    = [
            'code'      => $code,
            'module_id' => $moduleId,
            'view_path' => $viewPath,
            'content'   => $moduleContent,
            'visible'   => $module['visible'] ?? true,
            'design'    => $designMode,
        ];
        $moduleData['html'] = self::wrapResponsiveModuleHtml(
            view($viewPath, $moduleData)->render(),
            $rawContent,
            $moduleId,
            (string) ($module['code'] ?? '')
        );

        return $moduleData;
    }

    /** Keep a broken draft section visible and removable inside the editor. */
    public static function previewErrorModule(mixed $moduleId, string $code, int $index = 0): array
    {
        $moduleId = is_string($moduleId) && $moduleId !== '' ? $moduleId : "failed-module-{$index}";

        return [
            'code'      => $code,
            'module_id' => $moduleId,
            'html'      => view('design.preview_error', ['module_id' => $moduleId, 'code' => $code])->render(),
            'visible'   => true,
            'design'    => true,
        ];
    }

    /** Keep page ownership identical in the editor, homepage and template slots. */
    public static function matchesPlacement(mixed $modulePlacement, string $page): bool
    {
        $modulePlacement = self::normalizePlacement($modulePlacement);

        return $modulePlacement === $page
            || $modulePlacement === 'all'
            || ($modulePlacement === 'both' && in_array($page, ['home', 'product'], true));
    }

    public static function supportedPaymentMethods(): array
    {
        return ['visa', 'mastercard', 'amex', 'paypal', 'apple_pay', 'google_pay', 'shop_pay', 'klarna', 'afterpay', 'unionpay', 'jcb', 'discover', 'diners', 'ideal', 'bancontact', 'sofort', 'giropay', 'cartes_bancaires'];
    }

    /**
     * Apply device visibility once around every storefront module, rather than
     * duplicating responsive conditions in every Blade component.
     */
    public static function wrapResponsiveModuleHtml(string $html, mixed $content, string $moduleId, string $moduleCode = ''): string
    {
        $visibility = is_array($content) && is_array($content['responsive']['visibility'] ?? null)
            ? $content['responsive']['visibility']
            : [];
        $attributes = ['class="builder-responsive-module"'];
        foreach (['desktop', 'tablet', 'mobile'] as $device) {
            if (in_array($visibility[$device] ?? true, [false, 0, '0', 'false', 'off', 'no', ''], true)) {
                $attributes[] = "data-builder-hidden-{$device}=\"true\"";
            }
        }
        if ($moduleId !== '') {
            $attributes[] = 'data-builder-module-id="' . e($moduleId) . '"';
        }
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $moduleCode)) {
            $attributes[] = 'data-builder-module-code="' . e($moduleCode) . '"';
        }

        // Layout controls live on the stable wrapper so every native and
        // plugin-contributed section gets identical spacing/anchor behavior.
        // The inner module keeps its historical DOM contract for extensions.
        $anchor = is_array($content) && is_string($content['anchor'] ?? null)
            ? trim($content['anchor'])
            : '';
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $anchor)) {
            $attributes[] = 'id="' . e($anchor) . '"';
        }
        $sectionLayout = is_array($content) && is_array($content['section_layout'] ?? null)
            ? $content['section_layout']
            : [];
        if (($sectionLayout['spacing_mode'] ?? 'inherit') === 'custom') {
            $top          = min(200, max(0, (int) ($sectionLayout['top'] ?? 64)));
            $bottom       = min(200, max(0, (int) ($sectionLayout['bottom'] ?? 64)));
            $attributes[] = 'style="--builder-section-space-top: ' . $top . 'px; --builder-section-space-bottom: ' . $bottom . 'px;"';
        }

        return '<div ' . implode(' ', $attributes) . '>' . $html . '</div>';
    }

    private static function normalizePlacement(mixed $placement): string
    {
        return in_array($placement, ['home', 'product', 'category', 'page', 'both', 'all'], true) ? $placement : 'home';
    }

    /**
     * A deliberately small Liquid-like surface for storefront section code.
     * Supported output: {{ section.settings.key }}, {{ store.name }},
     * {{ store.url }}, {{ locale }}, {{ year }}. Supported control flow:
     * {% if section.settings.key %}...{% else %}...{% endif %}.
     */
    private static function renderCustomCodeTemplate(string $template, array $settings): string
    {
        $template         = mb_substr($template, 0, 30000);
        $conditionPattern = '/\{%\s*(if|unless)\s+(section\.settings\.([A-Za-z][A-Za-z0-9_]{0,63}))\s*%\}(.*?)(?:\{%\s*else\s*%\}(.*?))?\{%\s*endif\s*%\}/s';

        // Repeat a few times so independently nested blocks are rendered,
        // while keeping this parser bounded and predictable.
        for ($pass = 0; $pass < 4; $pass++) {
            $rendered = preg_replace_callback($conditionPattern, function (array $matches) use ($settings): string {
                $value  = $settings[$matches[3]] ?? null;
                $truthy = ! in_array($value, [null, false, '', 0, '0'], true);
                if ($matches[1] === 'unless') {
                    $truthy = ! $truthy;
                }

                return $truthy ? $matches[4] : ($matches[5] ?? '');
            }, $template);
            if (! is_string($rendered) || $rendered === $template) {
                break;
            }
            $template = $rendered;
        }

        return preg_replace_callback('/\{\{\s*([^{}]{1,200}?)\s*\}\}/', function (array $matches) use ($settings): string {
            $segments = array_map('trim', explode('|', $matches[1]));
            $variable = array_shift($segments);
            $value    = self::resolveCustomCodeVariable((string) $variable, $settings);

            foreach ($segments as $filter) {
                if ($filter === 'upcase') {
                    $value = mb_strtoupper($value);
                } elseif ($filter === 'downcase') {
                    $value = mb_strtolower($value);
                } elseif (preg_match('/^default\s*:\s*(.+)$/', $filter, $default)) {
                    if ($value === '') {
                        $value = trim($default[1], " \t\n\r\0\x0B\"'");
                    }
                } elseif (preg_match('/^truncate\s*:\s*(\d{1,3})$/', $filter, $length)) {
                    $limit         = min(200, max(1, (int) $length[1]));
                    $withoutSuffix = mb_strimwidth($value, 0, $limit, '');
                    $value         = $withoutSuffix === $value ? $value : mb_strimwidth($value, 0, $limit, '…');
                }
            }

            return e($value);
        }, $template) ?? '';
    }

    private static function resolveCustomCodeVariable(string $variable, array $settings): string
    {
        return match ($variable) {
            'store.name' => (string) system_setting('base.store_name', config('app.name')),
            'store.url'  => url('/'),
            'locale'     => locale(),
            'year'       => date('Y'),
            default      => str_starts_with($variable, 'section.settings.')
                ? (string) ($settings[substr($variable, 17)] ?? '')
                : '',
        };
    }

    private static function normalizeCustomCodeSettings(mixed $settings): array
    {
        if (is_array($settings)) {
            $decoded = $settings;
        } elseif (is_string($settings) && trim($settings) !== '') {
            $decoded = json_decode($settings, true);
        } else {
            $decoded = [];
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return [];
        }

        $normalized = [];
        foreach (array_slice($decoded, 0, 30, true) as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $key) || ! is_scalar($value)) {
                continue;
            }
            $normalized[$key] = is_string($value) ? mb_substr($value, 0, 1000) : $value;
        }

        return $normalized;
    }

    private static function sanitizeCustomCodeCss(string $css): string
    {
        $css = trim(mb_substr($css, 0, 12000));
        $css = preg_replace('/\/\*.*?\*\//s', '', $css) ?? '';

        if (preg_match('/(?:@import|@namespace|expression\s*\(|url\s*\(|behavior\s*:|binding\s*:|javascript\s*:|data\s*:|<\/?style|<|>)/i', $css)) {
            return '';
        }

        return $css;
    }

    /**
     * Render-ready data for the first dedicated Shopify section renderer.
     * Source files are never executed: local clips are catalog paths and
     * remote providers are converted to fixed-provider embed URLs.
     */
    private static function handleShopifyVideo(array $content): array
    {
        $content['heading']      = self::localizedValue($content['heading'] ?? $content['title'] ?? '');
        $content['description']  = self::localizedValue($content['description'] ?? '');
        $content['heading_size'] = in_array($content['heading_size'] ?? '', ['h2', 'h1', 'h0', 'hxl', 'hxxl'], true)
            ? $content['heading_size']
            : 'h1';
        $content['enable_video_looping'] = self::normalizeBoolean($content['enable_video_looping'] ?? false, false);
        $content['full_width']           = self::normalizeBoolean($content['full_width'] ?? false, false);
        $content['padding_top']          = min(100, max(0, (int) ($content['padding_top'] ?? 36)));
        $content['padding_bottom']       = min(100, max(0, (int) ($content['padding_bottom'] ?? 36)));
        $content['video']                = self::catalogMediaPath($content['video'] ?? '', 'mp4');
        $content['cover_image']          = self::catalogMediaPath($content['cover_image'] ?? '');
        $content['external_embed']       = self::externalVideoEmbed($content['video_url'] ?? '', $content['enable_video_looping']);

        return $content;
    }

    /** Safe, responsive LONGOS representation of Shopify's image-banner section. */
    private static function handleShopifyImageBanner(array $content): array
    {
        $content['image']                    = self::safeImageSource($content['image'] ?? '');
        $content['image_2']                  = self::safeImageSource($content['image_2'] ?? '');
        $content['image_overlay_opacity']    = min(100, max(0, (int) ($content['image_overlay_opacity'] ?? 0)));
        $content['image_height']             = in_array($content['image_height'] ?? '', ['adapt', 'small', 'medium', 'large'], true) ? $content['image_height'] : 'medium';
        $content['image_behavior']           = in_array($content['image_behavior'] ?? '', ['none', 'ambient', 'fixed', 'zoom-in'], true) ? $content['image_behavior'] : 'none';
        $content['desktop_content_position'] = in_array($content['desktop_content_position'] ?? '', ['top-left', 'top-center', 'top-right', 'middle-left', 'middle-center', 'middle-right', 'bottom-left', 'bottom-center', 'bottom-right'], true)
            ? $content['desktop_content_position']
            : 'middle-center';
        $content['desktop_content_alignment'] = in_array($content['desktop_content_alignment'] ?? '', ['left', 'center', 'right'], true) ? $content['desktop_content_alignment'] : 'center';
        $content['mobile_content_alignment']  = in_array($content['mobile_content_alignment'] ?? '', ['left', 'center', 'right'], true) ? $content['mobile_content_alignment'] : 'center';
        $content['show_text_box']             = self::normalizeBoolean($content['show_text_box'] ?? true, true);
        $content['stack_images_on_mobile']    = self::normalizeBoolean($content['stack_images_on_mobile'] ?? true, true);
        $content['show_text_below']           = self::normalizeBoolean($content['show_text_below'] ?? true, true);

        $blocks = [];
        foreach (array_slice(is_array($content['blocks'] ?? null) ? $content['blocks'] : [], 0, 3) as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = $block['block_type'] ?? $block['type'] ?? '';
            if ($type === 'heading') {
                $blocks[] = [
                    'type'         => 'heading',
                    'heading'      => self::localizedValue($block['heading'] ?? ''),
                    'heading_size' => in_array($block['heading_size'] ?? '', ['h2', 'h1', 'h0', 'hxl', 'hxxl'], true) ? $block['heading_size'] : 'h1',
                ];
            } elseif ($type === 'text') {
                $blocks[] = [
                    'type'       => 'text',
                    'text'       => self::localizedValue($block['text'] ?? ''),
                    'text_style' => in_array($block['text_style'] ?? '', ['body', 'subtitle', 'caption-with-letter-spacing'], true) ? $block['text_style'] : 'body',
                ];
            } elseif ($type === 'buttons') {
                $blocks[] = [
                    'type'                     => 'buttons',
                    'button_label_1'           => self::localizedValue($block['button_label_1'] ?? ''),
                    'button_link_1'            => self::safeStorefrontUrl($block['button_link_1'] ?? ''),
                    'button_style_secondary_1' => self::normalizeBoolean($block['button_style_secondary_1'] ?? false, false),
                    'button_label_2'           => self::localizedValue($block['button_label_2'] ?? ''),
                    'button_link_2'            => self::safeStorefrontUrl($block['button_link_2'] ?? ''),
                    'button_style_secondary_2' => self::normalizeBoolean($block['button_style_secondary_2'] ?? false, false),
                ];
            }
        }
        $content['blocks'] = $blocks;

        return $content;
    }

    /**
     * A native, dependency-free replacement for Shopify's slideshow section.
     * Shopify's theme-editor script is deliberately not imported; all visitor
     * behavior is rendered from bounded local data and accessible buttons.
     */
    private static function handleShopifySlideshow(array $content): array
    {
        $plainText                      = static fn (mixed $value, int $limit = 1000): string => is_scalar($value) ? mb_substr(trim(strip_tags((string) $value)), 0, $limit) : '';
        $content['layout']              = in_array($content['layout'] ?? '', ['full_bleed', 'grid'], true) ? $content['layout'] : 'full_bleed';
        $content['slide_height']        = in_array($content['slide_height'] ?? '', ['adapt_image', 'small', 'medium', 'large'], true) ? $content['slide_height'] : 'medium';
        $content['slider_visual']       = in_array($content['slider_visual'] ?? '', ['dots', 'counter', 'numbers'], true) ? $content['slider_visual'] : 'counter';
        $content['auto_rotate']         = self::normalizeBoolean($content['auto_rotate'] ?? false, false);
        $content['change_slides_speed'] = min(9, max(3, (int) ($content['change_slides_speed'] ?? 5)));
        $content['image_behavior']      = in_array($content['image_behavior'] ?? '', ['none', 'ambient'], true) ? $content['image_behavior'] : 'none';
        $content['show_text_below']     = self::normalizeBoolean($content['show_text_below'] ?? true, true);
        $content['accessibility_info']  = $plainText(self::localizedValue($content['accessibility_info'] ?? ''), 240) ?: (locale() === 'zh_cn' ? '轮播横幅' : 'Carousel banner');

        $slides = [];
        foreach (array_slice(is_array($content['blocks'] ?? null) ? $content['blocks'] : [], 0, 5) as $block) {
            if (! is_array($block) || (($block['block_type'] ?? $block['type'] ?? '') !== 'slide')) {
                continue;
            }
            $slides[] = [
                'image'                  => self::safeImageSource($block['image'] ?? ''),
                'image_overlay_opacity'  => min(100, max(0, (int) ($block['image_overlay_opacity'] ?? 0))),
                'heading'                => $plainText(self::localizedValue($block['heading'] ?? ''), 500),
                'heading_size'           => in_array($block['heading_size'] ?? '', ['h2', 'h1', 'h0', 'hxl', 'hxxl'], true) ? $block['heading_size'] : 'h1',
                'subheading'             => $plainText(self::localizedValue($block['subheading'] ?? ''), 1000),
                'button_label'           => $plainText(self::localizedValue($block['button_label'] ?? ''), 160),
                'link'                   => self::safeStorefrontUrl($block['link'] ?? ''),
                'button_style_secondary' => self::normalizeBoolean($block['button_style_secondary'] ?? false, false),
                'show_text_box'          => self::normalizeBoolean($block['show_text_box'] ?? true, true),
                'box_align'              => in_array($block['box_align'] ?? '', ['top-left', 'top-center', 'top-right', 'middle-left', 'middle-center', 'middle-right', 'bottom-left', 'bottom-center', 'bottom-right'], true) ? $block['box_align'] : 'middle-center',
                'text_alignment'         => in_array($block['text_alignment'] ?? '', ['left', 'center', 'right'], true) ? $block['text_alignment'] : 'center',
                'text_alignment_mobile'  => in_array($block['text_alignment_mobile'] ?? '', ['left', 'center', 'right'], true) ? $block['text_alignment_mobile'] : 'center',
                // Shopify palette definitions do not exist in LONGOS, so
                // the Builder exposes three concrete local schemes instead
                // of accepting an arbitrary, non-rendering scheme key.
                'color_scheme' => in_array($block['color_scheme'] ?? '', ['scheme-1', 'scheme-2', 'scheme-3'], true) ? $block['color_scheme'] : 'scheme-1',
            ];
        }
        $content['slides'] = $slides;

        return $content;
    }

    /** A paginated, local-category replacement for Shopify's collections directory. */
    private static function handleShopifyMainListCollections(array $content): array
    {
        $plainText                  = static fn (mixed $value, int $limit = 500): string => is_scalar($value) ? mb_substr(trim(strip_tags((string) $value)), 0, $limit) : '';
        $content['title']           = $plainText(self::localizedValue($content['title'] ?? ''), 500) ?: (locale() === 'zh_cn' ? '商品分类' : 'Product collection');
        $content['sort']            = in_array($content['sort'] ?? '', ['alphabetical', 'alphabetical_reversed', 'date_reversed', 'date', 'products_high', 'products_low'], true) ? $content['sort'] : 'alphabetical';
        $content['image_ratio']     = in_array($content['image_ratio'] ?? '', ['adapt', 'portrait', 'square'], true) ? $content['image_ratio'] : 'adapt';
        $content['columns_desktop'] = min(6, max(1, (int) ($content['columns_desktop'] ?? 3)));
        $content['columns_mobile']  = in_array((string) ($content['columns_mobile'] ?? ''), ['1', '2'], true) ? (int) $content['columns_mobile'] : 2;
        $content['categories']      = [];
        $content['pagination']      = ['previous' => '', 'next' => '', 'current' => 1, 'last' => 1];

        try {
            $locale = locale();
            $query  = \Beike\Models\Category::query()->with('description')->where('categories.active', true)
                ->leftJoin('category_descriptions as builder_category_descriptions', static function ($join) use ($locale): void {
                    $join->on('builder_category_descriptions.category_id', '=', 'categories.id')->where('builder_category_descriptions.locale', '=', $locale);
                })->select('categories.*');
            if (in_array($content['sort'], ['products_high', 'products_low'], true)) {
                $query->withCount('productCategories')->orderBy('product_categories_count', $content['sort'] === 'products_high' ? 'desc' : 'asc');
            } elseif (in_array($content['sort'], ['date', 'date_reversed'], true)) {
                $query->orderBy('categories.created_at', $content['sort'] === 'date_reversed' ? 'desc' : 'asc');
            } else {
                $query->orderBy('builder_category_descriptions.name', $content['sort'] === 'alphabetical_reversed' ? 'desc' : 'asc');
            }
            $paginator             = $query->paginate(28, ['categories.*'], 'shopify_collection_page')->withQueryString();
            $content['categories'] = $paginator->getCollection()->map(static function (\Beike\Models\Category $category): array {
                $description = $category->description;

                return ['name' => is_scalar($description?->name) ? mb_substr(trim(strip_tags((string) $description->name)), 0, 500) : '', 'image' => $category->image ? self::safeImageSource(image_origin($category->image)) : '', 'url' => self::safeStorefrontUrl((string) $category->url)];
            })->all();
            $content['pagination'] = ['previous' => self::safeStorefrontUrl((string) ($paginator->previousPageUrl() ?? '')), 'next' => self::safeStorefrontUrl((string) ($paginator->nextPageUrl() ?? '')), 'current' => $paginator->currentPage(), 'last' => $paginator->lastPage()];
        } catch (\Throwable) {
            // A fresh installer has no category tables yet. The empty state is
            // a valid storefront/editor state and must not fail rendering.
        }

        return $content;
    }

    /** Controlled rich-text renderer: HTML is purified at the server boundary before Blade receives it. */
    private static function handleShopifyRichText(array $content): array
    {
        $content['desktop_content_position'] = in_array($content['desktop_content_position'] ?? '', ['left', 'center', 'right'], true) ? $content['desktop_content_position'] : 'center';
        $content['content_alignment']        = in_array($content['content_alignment'] ?? '', ['left', 'center', 'right'], true) ? $content['content_alignment'] : 'center';
        $content['full_width']               = self::normalizeBoolean($content['full_width'] ?? true, true);
        $content['padding_top']              = min(100, max(0, (int) ($content['padding_top'] ?? 40)));
        $content['padding_bottom']           = min(100, max(0, (int) ($content['padding_bottom'] ?? 52)));
        $plainText                           = static fn (mixed $value, int $limit = 1000): string => is_scalar($value) ? mb_substr(trim(strip_tags((string) $value)), 0, $limit) : '';
        $blocks                              = [];
        foreach (array_slice(is_array($content['blocks'] ?? null) ? $content['blocks'] : [], 0, 8) as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = $block['block_type'] ?? $block['type'] ?? '';
            if ($type === 'heading') {
                $blocks[] = ['type' => 'heading', 'heading' => $plainText(self::localizedValue($block['heading'] ?? '')), 'heading_size' => in_array($block['heading_size'] ?? '', ['h2', 'h1', 'h0', 'hxl', 'hxxl'], true) ? $block['heading_size'] : 'h1'];
            } elseif ($type === 'caption') {
                $blocks[] = ['type' => 'caption', 'caption' => $plainText(self::localizedValue($block['caption'] ?? ''), 300), 'text_style' => in_array($block['text_style'] ?? '', ['subtitle', 'caption-with-letter-spacing'], true) ? $block['text_style'] : 'caption-with-letter-spacing', 'text_size' => in_array($block['text_size'] ?? '', ['small', 'medium', 'large'], true) ? $block['text_size'] : 'medium'];
            } elseif ($type === 'text') {
                $blocks[] = ['type' => 'text', 'html' => self::sanitizeShopifyRichText(self::localizedValue($block['text'] ?? ''))];
            } elseif ($type === 'button') {
                $blocks[] = [
                    'type'                     => 'button',
                    'button_label'             => $plainText(self::localizedValue($block['button_label'] ?? ''), 160),
                    'button_link'              => self::safeStorefrontUrl($block['button_link'] ?? ''),
                    'button_style_secondary'   => self::normalizeBoolean($block['button_style_secondary'] ?? false, false),
                    'button_label_2'           => $plainText(self::localizedValue($block['button_label_2'] ?? ''), 160),
                    'button_link_2'            => self::safeStorefrontUrl($block['button_link_2'] ?? ''),
                    'button_style_secondary_2' => self::normalizeBoolean($block['button_style_secondary_2'] ?? false, false),
                ];
            }
        }
        $content['blocks'] = $blocks;

        return $content;
    }

    /**
     * Purpose-built data boundary for Shopify's image-with-text section.
     * It retains its independently configurable media/layout/content settings
     * rather than aliasing the unit to LONGOS's older image-text module.
     */
    private static function handleShopifyImageWithText(array $content): array
    {
        $content['image']                     = self::safeImageSource($content['image'] ?? '');
        $content['height']                    = in_array($content['height'] ?? '', ['adapt', 'small', 'medium', 'large'], true) ? $content['height'] : 'adapt';
        $content['desktop_image_width']       = in_array($content['desktop_image_width'] ?? '', ['small', 'medium', 'large'], true) ? $content['desktop_image_width'] : 'medium';
        $content['layout']                    = in_array($content['layout'] ?? '', ['image_first', 'text_first'], true) ? $content['layout'] : 'image_first';
        $content['image_behavior']            = in_array($content['image_behavior'] ?? '', ['none', 'ambient', 'zoom-in'], true) ? $content['image_behavior'] : 'none';
        $content['content_layout']            = in_array($content['content_layout'] ?? '', ['no-overlap', 'overlap'], true) ? $content['content_layout'] : 'no-overlap';
        $content['desktop_content_position']  = in_array($content['desktop_content_position'] ?? '', ['top', 'middle', 'bottom'], true) ? $content['desktop_content_position'] : 'top';
        $content['desktop_content_alignment'] = in_array($content['desktop_content_alignment'] ?? '', ['left', 'center', 'right'], true) ? $content['desktop_content_alignment'] : 'left';
        $content['mobile_content_alignment']  = in_array($content['mobile_content_alignment'] ?? '', ['left', 'center', 'right'], true) ? $content['mobile_content_alignment'] : 'left';
        $content['padding_top']               = min(100, max(0, (int) ($content['padding_top'] ?? 36)));
        $content['padding_bottom']            = min(100, max(0, (int) ($content['padding_bottom'] ?? 36)));
        $plainText                            = static fn (mixed $value, int $limit = 1000): string => is_scalar($value) ? mb_substr(trim(strip_tags((string) $value)), 0, $limit) : '';
        $blocks                               = [];
        foreach (array_slice(is_array($content['blocks'] ?? null) ? $content['blocks'] : [], 0, 4) as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = $block['block_type'] ?? $block['type'] ?? '';
            if ($type === 'heading') {
                $blocks[] = ['type' => 'heading', 'heading' => $plainText(self::localizedValue($block['heading'] ?? '')), 'heading_size' => in_array($block['heading_size'] ?? '', ['h2', 'h1', 'h0', 'hxl', 'hxxl'], true) ? $block['heading_size'] : 'h1'];
            } elseif ($type === 'caption') {
                $blocks[] = ['type' => 'caption', 'caption' => $plainText(self::localizedValue($block['caption'] ?? ''), 300), 'text_style' => in_array($block['text_style'] ?? '', ['subtitle', 'caption-with-letter-spacing'], true) ? $block['text_style'] : 'caption-with-letter-spacing', 'text_size' => in_array($block['text_size'] ?? '', ['small', 'medium', 'large'], true) ? $block['text_size'] : 'medium'];
            } elseif ($type === 'text') {
                $blocks[] = ['type' => 'text', 'html' => self::sanitizeShopifyRichText(self::localizedValue($block['text'] ?? '')), 'text_style' => in_array($block['text_style'] ?? '', ['body', 'subtitle'], true) ? $block['text_style'] : 'body'];
            } elseif ($type === 'button' && ! array_filter($blocks, static fn (array $item): bool => $item['type'] === 'button')) {
                $blocks[] = ['type' => 'button', 'button_label' => $plainText(self::localizedValue($block['button_label'] ?? ''), 160), 'button_link' => self::safeStorefrontUrl($block['button_link'] ?? ''), 'button_style_secondary' => self::normalizeBoolean($block['button_style_secondary'] ?? false, false)];
            }
        }
        $content['blocks'] = $blocks;

        return $content;
    }

    /** Server-normalized storefront model for Shopify's multicolumn section. */
    private static function handleShopifyMulticolumn(array $content): array
    {
        $plainText                   = static fn (mixed $value, int $limit = 1000): string => is_scalar($value) ? mb_substr(trim(strip_tags((string) $value)), 0, $limit) : '';
        $content['title']            = $plainText(self::localizedValue($content['title'] ?? ''), 500);
        $content['heading_size']     = in_array($content['heading_size'] ?? '', ['h2', 'h1', 'h0', 'hxl', 'hxxl'], true) ? $content['heading_size'] : 'h1';
        $content['image_width']      = in_array($content['image_width'] ?? '', ['third', 'half', 'full'], true) ? $content['image_width'] : 'full';
        $content['image_ratio']      = in_array($content['image_ratio'] ?? '', ['adapt', 'portrait', 'square', 'circle'], true) ? $content['image_ratio'] : 'adapt';
        $content['columns_desktop']  = min(6, max(1, (int) ($content['columns_desktop'] ?? 3)));
        $content['columns_mobile']   = in_array((string) ($content['columns_mobile'] ?? ''), ['1', '2'], true) ? (int) $content['columns_mobile'] : 1;
        $content['column_alignment'] = in_array($content['column_alignment'] ?? '', ['left', 'center'], true) ? $content['column_alignment'] : 'left';
        $content['background_style'] = in_array($content['background_style'] ?? '', ['none', 'primary'], true) ? $content['background_style'] : 'primary';
        $content['swipe_on_mobile']  = self::normalizeBoolean($content['swipe_on_mobile'] ?? false, false);
        $content['button_label']     = $plainText(self::localizedValue($content['button_label'] ?? ''), 160);
        $content['button_link']      = self::safeStorefrontUrl($content['button_link'] ?? '');
        $content['padding_top']      = min(100, max(0, (int) ($content['padding_top'] ?? 36)));
        $content['padding_bottom']   = min(100, max(0, (int) ($content['padding_bottom'] ?? 36)));
        $blocks                      = [];
        foreach (array_slice(is_array($content['blocks'] ?? null) ? $content['blocks'] : [], 0, 12) as $block) {
            if (! is_array($block) || (($block['block_type'] ?? $block['type'] ?? '') !== 'column')) {
                continue;
            }
            $blocks[] = [
                'type'       => 'column',
                'image'      => self::safeImageSource($block['image'] ?? ''),
                'title'      => $plainText(self::localizedValue($block['title'] ?? ''), 500),
                'html'       => self::sanitizeShopifyRichText(self::localizedValue($block['text'] ?? '')),
                'link_label' => $plainText(self::localizedValue($block['link_label'] ?? ''), 160),
                'link'       => self::safeStorefrontUrl($block['link'] ?? ''),
            ];
        }
        $content['blocks'] = $blocks;

        return $content;
    }

    /** Accessible, native-details model for Shopify's collapsible-content section. */
    private static function handleShopifyCollapsibleContent(array $content): array
    {
        $plainText                             = static fn (mixed $value, int $limit = 1000): string => is_scalar($value) ? mb_substr(trim(strip_tags((string) $value)), 0, $limit) : '';
        $content['caption']                    = $plainText(self::localizedValue($content['caption'] ?? ''), 300);
        $content['heading']                    = $plainText(self::localizedValue($content['heading'] ?? ''), 500);
        $content['heading_size']               = in_array($content['heading_size'] ?? '', ['h2', 'h1', 'h0', 'hxl', 'hxxl'], true) ? $content['heading_size'] : 'h1';
        $content['heading_alignment']          = in_array($content['heading_alignment'] ?? '', ['left', 'center', 'right'], true) ? $content['heading_alignment'] : 'center';
        $content['layout']                     = in_array($content['layout'] ?? '', ['none', 'row', 'section'], true) ? $content['layout'] : 'none';
        $content['image']                      = self::safeImageSource($content['image'] ?? '');
        $content['image_ratio']                = in_array($content['image_ratio'] ?? '', ['adapt', 'small', 'large'], true) ? $content['image_ratio'] : 'adapt';
        $content['desktop_layout']             = in_array($content['desktop_layout'] ?? '', ['image_first', 'image_second'], true) ? $content['desktop_layout'] : 'image_second';
        $content['open_first_collapsible_row'] = self::normalizeBoolean($content['open_first_collapsible_row'] ?? false, false);
        $content['padding_top']                = min(100, max(0, (int) ($content['padding_top'] ?? 36)));
        $content['padding_bottom']             = min(100, max(0, (int) ($content['padding_bottom'] ?? 36)));
        $blocks                                = [];
        foreach (array_slice(is_array($content['blocks'] ?? null) ? $content['blocks'] : [], 0, 20) as $block) {
            if (! is_array($block) || (($block['block_type'] ?? $block['type'] ?? '') !== 'collapsible_row')) {
                continue;
            }
            $page     = self::shopifyPageSnippet($block['page'] ?? null);
            $icon     = is_scalar($block['icon'] ?? null) ? (string) $block['icon'] : 'none';
            $blocks[] = [
                'type'    => 'collapsible_row',
                'heading' => $plainText(self::localizedValue($block['heading'] ?? ''), 500) ?: $page['title'],
                // The icon is a data attribute only; the native disclosure
                // indicator remains the accessible control affordance.
                'icon'      => preg_match('/^[a-z_]{1,40}$/', $icon) ? $icon : 'none',
                'html'      => self::sanitizeShopifyRichText(self::localizedValue($block['row_content'] ?? '')),
                'page_html' => $page['html'],
                'page_url'  => $page['url'],
            ];
        }
        $content['blocks'] = $blocks;

        return $content;
    }

    /** Resolve Shopify collection-list blocks against active LONGOS categories. */
    private static function handleShopifyCollectionList(array $content): array
    {
        $plainText                  = static fn (mixed $value, int $limit = 1000): string => is_scalar($value) ? mb_substr(trim(strip_tags((string) $value)), 0, $limit) : '';
        $content['title']           = $plainText(self::localizedValue($content['title'] ?? ''), 500);
        $content['heading_size']    = in_array($content['heading_size'] ?? '', ['h2', 'h1', 'h0', 'hxl', 'hxxl'], true) ? $content['heading_size'] : 'h1';
        $content['image_ratio']     = in_array($content['image_ratio'] ?? '', ['adapt', 'portrait', 'square'], true) ? $content['image_ratio'] : 'square';
        $content['columns_desktop'] = min(6, max(1, (int) ($content['columns_desktop'] ?? 3)));
        $content['columns_mobile']  = in_array((string) ($content['columns_mobile'] ?? ''), ['1', '2'], true) ? (int) $content['columns_mobile'] : 1;
        $content['show_view_all']   = self::normalizeBoolean($content['show_view_all'] ?? false, false);
        $content['swipe_on_mobile'] = self::normalizeBoolean($content['swipe_on_mobile'] ?? false, false);
        $content['padding_top']     = min(100, max(0, (int) ($content['padding_top'] ?? 36)));
        $content['padding_bottom']  = min(100, max(0, (int) ($content['padding_bottom'] ?? 36)));
        $blocks                     = [];
        $ids                        = [];
        foreach (array_slice(is_array($content['blocks'] ?? null) ? $content['blocks'] : [], 0, 15) as $block) {
            if (! is_array($block) || (($block['block_type'] ?? $block['type'] ?? '') !== 'featured_collection')) {
                continue;
            }
            $id       = filter_var($block['collection'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $blocks[] = ['type' => 'featured_collection', 'collection' => $id ?: null];
            if ($id) {
                $ids[] = $id;
            }
        }
        $content['blocks']       = $blocks;
        $content['categories']   = self::shopifyCategoryCards($ids);
        $content['view_all_url'] = self::shopifyCategoryIndexUrl();

        return $content;
    }

    /** Dedicated data model for Shopify's repeated, alternating image-text rows. */
    private static function handleShopifyMultirow(array $content): array
    {
        $plainText                            = static fn (mixed $value, int $limit = 1000): string => is_scalar($value) ? mb_substr(trim(strip_tags((string) $value)), 0, $limit) : '';
        $content['image_height']              = in_array($content['image_height'] ?? '', ['adapt', 'small', 'medium', 'large'], true) ? $content['image_height'] : 'medium';
        $content['desktop_image_width']       = in_array($content['desktop_image_width'] ?? '', ['small', 'medium', 'large'], true) ? $content['desktop_image_width'] : 'medium';
        $content['image_layout']              = in_array($content['image_layout'] ?? '', ['alternate-left', 'alternate-right', 'align-left', 'align-right'], true) ? $content['image_layout'] : 'alternate-left';
        $content['heading_size']              = in_array($content['heading_size'] ?? '', ['h2', 'h1', 'h0', 'hxl', 'hxxl'], true) ? $content['heading_size'] : 'h1';
        $content['text_style']                = in_array($content['text_style'] ?? '', ['body', 'subtitle'], true) ? $content['text_style'] : 'body';
        $content['button_style']              = in_array($content['button_style'] ?? '', ['primary', 'secondary'], true) ? $content['button_style'] : 'secondary';
        $content['desktop_content_position']  = in_array($content['desktop_content_position'] ?? '', ['top', 'middle', 'bottom'], true) ? $content['desktop_content_position'] : 'middle';
        $content['desktop_content_alignment'] = in_array($content['desktop_content_alignment'] ?? '', ['left', 'center', 'right'], true) ? $content['desktop_content_alignment'] : 'left';
        $content['mobile_content_alignment']  = in_array($content['mobile_content_alignment'] ?? '', ['left', 'center', 'right'], true) ? $content['mobile_content_alignment'] : 'left';
        $content['padding_top']               = min(100, max(0, (int) ($content['padding_top'] ?? 36)));
        $content['padding_bottom']            = min(100, max(0, (int) ($content['padding_bottom'] ?? 36)));
        $blocks                               = [];
        foreach (array_slice(is_array($content['blocks'] ?? null) ? $content['blocks'] : [], 0, 15) as $index => $block) {
            if (! is_array($block) || (($block['block_type'] ?? $block['type'] ?? '') !== 'row')) {
                continue;
            }
            $imageFirst = match ($content['image_layout']) {
                'align-left'      => true,
                'align-right'     => false,
                'alternate-right' => $index % 2 !== 0,
                default           => $index % 2 === 0,
            };
            $blocks[] = [
                'type'           => 'row',
                'image'          => self::safeImageSource($block['image'] ?? ''),
                'caption'        => $plainText(self::localizedValue($block['caption'] ?? ''), 300),
                'heading'        => $plainText(self::localizedValue($block['heading'] ?? ''), 500),
                'html'           => self::sanitizeShopifyRichText(self::localizedValue($block['text'] ?? '')),
                'button_label'   => $plainText(self::localizedValue($block['button_label'] ?? ''), 160),
                'button_link'    => self::safeStorefrontUrl($block['button_link'] ?? ''),
                'image_position' => $imageFirst ? 'first' : 'second',
            ];
        }
        $content['blocks'] = $blocks;

        return $content;
    }

    /** Map Shopify's blog setting to a LONGOS content category and its active pages. */
    private static function handleShopifyFeaturedBlog(array $content): array
    {
        $plainText                  = static fn (mixed $value, int $limit = 1000): string => is_scalar($value) ? mb_substr(trim(strip_tags((string) $value)), 0, $limit) : '';
        $content['blog']            = filter_var($content['blog'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $content['post_limit']      = min(4, max(2, (int) ($content['post_limit'] ?? 3)));
        $content['heading']         = $plainText(self::localizedValue($content['heading'] ?? ''), 500);
        $content['heading_size']    = in_array($content['heading_size'] ?? '', ['h2', 'h1', 'h0', 'hxl', 'hxxl'], true) ? $content['heading_size'] : 'h1';
        $content['columns_desktop'] = min(4, max(1, (int) ($content['columns_desktop'] ?? 3)));
        $content['show_view_all']   = self::normalizeBoolean($content['show_view_all'] ?? true, true);
        $content['show_image']      = self::normalizeBoolean($content['show_image'] ?? true, true);
        $content['show_date']       = self::normalizeBoolean($content['show_date'] ?? true, true);
        $content['show_author']     = self::normalizeBoolean($content['show_author'] ?? false, false);
        $content['padding_top']     = min(100, max(0, (int) ($content['padding_top'] ?? 36)));
        $content['padding_bottom']  = min(100, max(0, (int) ($content['padding_bottom'] ?? 36)));
        $content['articles']        = self::shopifyFeaturedPages($content['blog'], $content['post_limit']);
        $content['view_all_url']    = self::shopifyPageCategoryUrl($content['blog']);

        return $content;
    }

    /**
     * Bind Shopify's implicit runtime page object to an explicit, active
     * LONGOS page selection. This makes the section usable in the builder
     * without leaking raw page HTML into its Blade renderer.
     */
    private static function handleShopifyMainPage(array $content): array
    {
        $content['page']           = filter_var($content['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $content['padding_top']    = min(100, max(0, (int) ($content['padding_top'] ?? 36)));
        $content['padding_bottom'] = min(100, max(0, (int) ($content['padding_bottom'] ?? 36)));
        $content['page_content']   = self::shopifyPageSnippet($content['page']);

        return $content;
    }

    /** Bind Shopify's implicit collection context to one active local category. */
    private static function handleShopifyMainCollectionBanner(array $content): array
    {
        $content['collection']                  = filter_var($content['collection'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $content['show_collection_description'] = self::normalizeBoolean($content['show_collection_description'] ?? true, true);
        $content['show_collection_image']       = self::normalizeBoolean($content['show_collection_image'] ?? false, false);
        $content['collection_content']          = self::shopifyCollectionSnippet($content['collection']);

        return $content;
    }

    /** Render a selected LONGOS page as Shopify's narrow page section. */
    private static function handleShopifyPage(array $content): array
    {
        $content['page']           = filter_var($content['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $content['heading_size']   = in_array($content['heading_size'] ?? '', ['h2', 'h1', 'h0', 'hxl', 'hxxl'], true) ? $content['heading_size'] : 'h1';
        $content['padding_top']    = min(100, max(0, (int) ($content['padding_top'] ?? 36)));
        $content['padding_bottom'] = min(100, max(0, (int) ($content['padding_bottom'] ?? 36)));
        $content['page_content']   = self::shopifyPageSnippet($content['page']);

        return $content;
    }

    /** Return a presentation-safe article card shape, scoped to one active page category. */
    private static function shopifyFeaturedPages(?int $categoryId, int $limit): array
    {
        if (! $categoryId) {
            return [];
        }
        $pages = \Beike\Models\Page::query()->with('description')->where('active', true)->where('page_category_id', $categoryId)->orderByDesc('id')->limit($limit)->get();

        return $pages->map(static function (\Beike\Models\Page $page): array {
            $description = $page->description;
            $summary     = is_scalar($description?->summary) && trim((string) $description->summary) !== ''
                ? (string) $description->summary
                : (is_scalar($description?->content) ? (string) $description->content : '');

            return [
                'title'   => is_scalar($description?->title) ? mb_substr(trim(strip_tags((string) $description->title)), 0, 500) : '',
                'summary' => mb_substr(trim(strip_tags($summary)), 0, 320),
                'image'   => $page->image ? self::safeImageSource(image_origin($page->image)) : '',
                'url'     => self::safeStorefrontUrl((string) $page->url),
                'author'  => is_scalar($page->author) ? mb_substr(trim(strip_tags((string) $page->author)), 0, 120) : '',
                'date'    => $page->created_at?->format('Y-m-d') ?? '',
            ];
        })->all();
    }

    /** Resolve a local content-category archive without letting an invalid id reach routing. */
    private static function shopifyPageCategoryUrl(?int $categoryId): string
    {
        if (! $categoryId) {
            return '';
        }
        $category = \Beike\Models\PageCategory::query()->where('active', true)->find($categoryId);
        if (! $category) {
            return '';
        }

        try {
            return self::safeStorefrontUrl((string) $category->url);
        } catch (\Throwable) {
            return '/page_categories/' . $categoryId;
        }
    }

    /** Keep configured order and expose only active, local category fields to Blade. */
    private static function shopifyCategoryCards(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_int($id) && $id > 0)));
        if (! $ids) {
            return [];
        }
        $categories = \Beike\Models\Category::query()->with('description')->where('active', true)->whereIn('id', $ids)->get()->keyBy('id');
        $cards      = [];
        foreach ($ids as $id) {
            $category = $categories->get($id);
            if (! $category) {
                continue;
            }
            $description = $category->description;
            $image       = $category->image ? self::safeImageSource(image_origin($category->image)) : '';
            $cards[]     = [
                'id'    => $category->id,
                'name'  => is_scalar($description?->name) ? mb_substr(trim(strip_tags((string) $description->name)), 0, 500) : '',
                'image' => $image,
                'url'   => self::safeStorefrontUrl((string) $category->url),
            ];
        }

        return $cards;
    }

    /** Resolve a single active local category without exposing its raw content. */
    private static function shopifyCollectionSnippet(mixed $categoryId): array
    {
        $id = filter_var($categoryId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $id) {
            return ['id' => null, 'name' => '', 'html' => '', 'image' => '', 'url' => ''];
        }
        $category = BeikeModelsCategory::query()->with('description')->where('active', true)->find($id);
        if (! $category) {
            return ['id' => null, 'name' => '', 'html' => '', 'image' => '', 'url' => ''];
        }
        $description = $category->description;

        return [
            'id'    => $category->id,
            'name'  => is_scalar($description?->name) ? mb_substr(trim(strip_tags((string) $description->name)), 0, 500) : '',
            'html'  => self::sanitizeShopifyRichText(is_scalar($description?->content) ? (string) $description->content : ''),
            'image' => $category->image ? self::safeImageSource(image_origin($category->image)) : '',
            'url'   => self::safeStorefrontUrl((string) $category->url),
        ];
    }

    /** Use the localized shop route when the Laravel container is available. */
    private static function shopifyCategoryIndexUrl(): string
    {
        try {
            return self::safeStorefrontUrl(shop_route('categories.index'));
        } catch (\Throwable) {
            // The schema normalizer is also exercised in a framework-light
            // migration assertion; this relative route remains safe there.
            return '/categories';
        }
    }

    /** Resolve a selected LONGOS page in the active locale, never raw Shopify page data. */
    private static function shopifyPageSnippet(mixed $pageId): array
    {
        $id = filter_var($pageId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $id) {
            return ['title' => '', 'html' => '', 'url' => ''];
        }
        $page = \Beike\Models\Page::query()->with('description')->where('active', true)->find($id);
        if (! $page) {
            return ['title' => '', 'html' => '', 'url' => ''];
        }
        $description = $page->description;

        return [
            'title' => is_scalar($description?->title) ? mb_substr(trim(strip_tags((string) $description->title)), 0, 500) : '',
            'html'  => self::sanitizeShopifyRichText(is_scalar($description?->content) ? (string) $description->content : ''),
            'url'   => self::safeStorefrontUrl((string) $page->url),
        ];
    }

    /** Only catalog paths emitted by the file manager are accepted here. */
    private static function catalogMediaPath(mixed $value, ?string $extension = null): string
    {
        if (! is_scalar($value)) {
            return '';
        }
        $value   = trim((string) $value);
        $pattern = '#^/?image/catalog/(?:tenants/[a-z0-9-]{1,64}/)?[A-Za-z0-9._% /-]{1,500}$#';
        $decoded = rawurldecode($value);
        if (! preg_match($pattern, $value) || str_contains($decoded, '..') || str_contains($decoded, '//')) {
            return '';
        }
        if ($extension !== null && ! str_ends_with(strtolower($value), '.' . $extension)) {
            return '';
        }

        return '/' . ltrim($value, '/');
    }

    /** Preserve valid legacy HTTPS/HTTP image sources without permitting executable URI schemes. */
    private static function safeImageSource(mixed $value): string
    {
        $catalogPath = self::catalogMediaPath($value);
        if ($catalogPath !== '') {
            return $catalogPath;
        }
        if (! is_scalar($value)) {
            return '';
        }
        $value  = trim(mb_substr((string) $value, 0, 2048));
        $parts  = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return in_array($scheme, ['http', 'https'], true) && filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
    }

    /** Resolve legacy catalog identifiers without sending hostile URI schemes through the image service. */
    private static function resolveImageSource(mixed $value): string
    {
        $resolved = self::safeImageSource($value);
        if ($resolved !== '' || ! is_scalar($value)) {
            return $resolved;
        }

        $raw    = trim((string) $value);
        $parts  = $raw === '' ? [] : parse_url($raw);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if ($raw === '' || $scheme !== '' || str_starts_with($raw, '//')) {
            return '';
        }

        return self::safeImageSource(image_origin($raw));
    }

    /** A compact rich-text allowlist for migrated Shopify content blocks. */
    private static function sanitizeShopifyRichText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $config = HtmlPurifierConfig::create();
        // Match the visual rich-text field: headings and quotes must survive
        // the server boundary instead of being silently flattened on save.
        $config->set('HTML.Allowed', 'a[href|title|target|rel],blockquote,br,b,em,h2,h3,h4,i,strong,p,ul,ol,li');
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
        $config->set('URI.DisableExternalResources', true);
        $config->set('CSS.AllowedProperties', []);

        return (new \HTMLPurifier($config))->purify(mb_substr($html, 0, 12000));
    }

    /** Allow navigation schemes that the Builder UI exposes; block scripts and protocol-relative URLs. */
    private static function safeStorefrontUrl(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }
        $value = trim(mb_substr((string) $value, 0, 2048));
        if ($value === '') {
            return '';
        }
        if (preg_match('#^/(?!/)[^\s\\\\]*$#', $value) || preg_match('/^#[^\s]*$/', $value)) {
            return $value;
        }
        $parts  = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (in_array($scheme, ['http', 'https'], true) && filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }
        if ($scheme === 'mailto' && preg_match('/^mailto:[^\s@]+@[^\s@]+$/i', $value)) {
            return $value;
        }
        if ($scheme === 'tel' && preg_match('/^tel:[0-9+(). -]{3,40}$/', $value)) {
            return $value;
        }

        return '';
    }

    /** Translate a validated provider URL into a fixed, non-user-controlled iframe source. */
    private static function externalVideoEmbed(mixed $value, bool $loop): string
    {
        if (! is_scalar($value) || ! filter_var((string) $value, FILTER_VALIDATE_URL)) {
            return '';
        }
        $parts = parse_url((string) $value);
        $host  = strtolower((string) ($parts['host'] ?? ''));
        $host  = preg_replace('/^www\./', '', $host) ?? '';
        $path  = trim((string) ($parts['path'] ?? ''), '/');
        parse_str((string) ($parts['query'] ?? ''), $query);
        $id = '';
        if (in_array($host, ['youtube.com', 'm.youtube.com', 'youtu.be'], true)) {
            $id = $host === 'youtu.be' ? $path : (is_scalar($query['v'] ?? null) ? (string) $query['v'] : '');
            if ($id === '' && preg_match('#^(?:embed|shorts)/([^/]+)#', $path, $matches)) {
                $id = $matches[1];
            }
            if (! preg_match('/^[A-Za-z0-9_-]{6,32}$/', $id)) {
                return '';
            }

            return 'https://www.youtube-nocookie.com/embed/' . $id . '?rel=0' . ($loop ? '&loop=1&playlist=' . $id : '');
        }
        if (in_array($host, ['vimeo.com', 'player.vimeo.com'], true) && preg_match('#(?:^|/)video/?([0-9]+)$|^([0-9]+)$#', $path, $matches)) {
            $id = $matches[1] ?: $matches[2];

            return 'https://player.vimeo.com/video/' . $id . ($loop ? '?loop=1' : '');
        }

        return '';
    }

    /**
     * 处理 SlideShow 模块
     *
     * @param $content
     * @return array
     * @throws \Exception
     */
    private static function handleSlideShow($content): array
    {
        $images            = $content['images'] ?? [];
        $content['images'] = self::handleImages($images);
        // The catalog uses localized title maps, whereas the legacy Blade
        // carousel consumes a scalar aria label. Normalize it at the same
        // boundary as every other legacy section so a fresh module can never
        // pass an array to Blade's HTML escaping helper.
        $content['title']       = self::localizedValue($content['title'] ?? '') ?: site_brand_name();
        $content['module_size'] = is_string($content['module_size'] ?? null)
            ? $content['module_size']
            : 'w-100';

        return $content;
    }

    /**
     * 处理 ImgTextSlideShow 模块
     *
     * @param $content
     * @return array
     * @throws \Exception
     */
    private static function handleImgTextSlideShow($content): array
    {
        $images             = $content['images']      ?? [];
        $scrollText         = $content['scroll_text'] ?? [];
        $scrollText         = is_array($scrollText) ? $scrollText : [];
        $scrollText['text'] = self::localizedValue($scrollText['text'] ?? '');
        foreach (['bg' => '#000000', 'color' => '#FFFFFF'] as $key => $fallback) {
            $color            = $scrollText[$key] ?? '';
            $scrollText[$key] = is_string($color) && preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : $fallback;
        }
        $scrollText['font_size']   = min(32, max(12, (int) ($scrollText['font_size'] ?? 14)));
        $scrollText['padding']     = min(48, max(0, (int) ($scrollText['padding'] ?? 10)));
        $content['scroll_text']    = $scrollText;
        $content['autoplay']       = self::normalizeBoolean($content['autoplay'] ?? false, false);
        $content['autoplay_delay'] = min(15000, max(2000, (int) ($content['autoplay_delay'] ?? 5000)));
        $content['title']          = self::localizedValue($content['title'] ?? '') ?: site_brand_name();

        $content['images'] = self::handleImages($images);

        return $content;
    }

    /**
     * 处理 ImgTextBanner 模块
     *
     * @param $content
     * @return array
     * @throws \Exception
     */
    private static function handleImgTextBanner($content): array
    {
        $image     = $content['image'] ?? '';
        $imageData = is_array($image) ? $image : [];
        $imageSrc  = $imageData['src'] ?? $image;

        // Empty images are a normal state while a merchant is adding a new
        // section. Normalize the rest of the payload anyway; returning early
        // used to leak locale arrays into Blade and could white-screen preview.
        $content['image']            = self::localizedValue($imageSrc);
        $content['image_alt']        = self::localizedValue($imageData['alt'] ?? ($content['image_alt'] ?? ''));
        $content['title']            = self::localizedValue($content['title'] ?? '');
        $content['image_position']   = in_array($content['image_position'] ?? '', ['left', 'right'], true) ? $content['image_position'] : 'right';
        $content['text_position']    = in_array($content['text_position'] ?? '', ['left', 'center', 'right'], true) ? $content['text_position'] : 'left';
        $content['sub_title']        = self::localizedValue($content['sub_title'] ?? '');
        $content['description']      = self::localizedValue($content['description'] ?? '');
        $content['button_text']      = self::localizedValue($content['button_text'] ?? '') ?: trans('common.view_more');
        $backgroundColor             = is_string($content['bg_color'] ?? null) ? trim($content['bg_color']) : '';
        $textColor                   = is_string($content['text_color'] ?? null) ? trim($content['text_color']) : '';
        $content['bg_color']         = preg_match('/^#[0-9A-Fa-f]{6}$/', $backgroundColor) ? strtoupper($backgroundColor) : '#F5F5F3';
        $content['text_color']       = preg_match('/^#[0-9A-Fa-f]{6}$/', $textColor) ? strtoupper($textColor) : '#202223';
        $content['text_max_width']   = min(1200, max(280, (int) ($content['text_max_width'] ?? 620)));
        $link                        = is_array($content['link'] ?? null) ? $content['link'] : [];
        $content['link']             = ! empty($link['type']) && array_key_exists('value', $link)
            ? self::handleLink($link['type'], $link['value'])
            : '';

        return $content;
    }

    /**
     * 处理 brand 模块
     *
     * @param $content
     * @return array
     * @throws \Exception
     */
    private static function handleBrand($content): array
    {
        $brandIds = self::sanitizeIds($content['brands'] ?? [], 24);
        $brands   = $brandIds ? BrandDetail::collection(BrandRepo::getListByIds($brandIds))->jsonSerialize() : [];

        $content['brands']          = $brands;
        $content['title']           = self::localizedValue($content['title'] ?? '');
        $content                    = self::normalizeResponsiveColumns($content);
        $content['show_view_all']   = self::normalizeBoolean($content['show_view_all'] ?? true, true);

        return $content;
    }

    /**
     * 处理 SlideShow 模块
     *
     * @param $content
     * @return array
     * @throws \Exception
     */
    private static function handleBanner($content): array
    {
        $images                = $content['images'] ?? [];
        $content['images']     = self::handleImages($images);
        $content['full']       = self::normalizeBoolean($content['full'] ?? false, false);
        $content['title']      = self::localizedValue($content['title'] ?? '');
        $content['sub_title']  = self::localizedValue($content['sub_title'] ?? '');

        return $content;
    }

    /**
     * 处理 icons 模块
     *
     * @param $content
     * @return array
     * @throws \Exception
     */
    private static function handleIcons($content): array
    {
        $content['title']           = self::localizedValue($content['title'] ?? '');
        $content['sub_title']       = self::localizedValue($content['sub_title'] ?? '');
        $content                    = self::normalizeIconColumns($content);
        $content['style_variant']   = in_array($content['style_variant'] ?? '', ['minimal', 'cards'], true) ? $content['style_variant'] : 'minimal';

        if (! is_array($content['images'] ?? null) || empty($content['images'])) {
            $content['images'] = [];

            return $content;
        }

        $images = [];
        foreach (array_slice($content['images'], 0, 10) as $image) {
            if (! is_array($image)) {
                continue;
            }
            $imageData     = $image['image'] ?? '';
            $linkData      = is_array($image['link'] ?? null) ? $image['link'] : [];
            $imageSource   = is_array($imageData) ? ($imageData['src'] ?? '') : $imageData;
            $resolvedImage = self::resolveImageSource($imageSource);
            $normalized    = [
                'image'      => $resolvedImage,
                'image_alt'  => self::localizedValue($image['image_alt'] ?? (is_array($imageData) ? ($imageData['alt'] ?? '') : '')),
                'text'       => self::localizedValue($image['text'] ?? ''),
                'sub_text'   => self::localizedValue($image['sub_text'] ?? ''),
                'link'       => $linkData,
                'url'        => self::handleLink($linkData['type'] ?? '', $linkData['value'] ?? ''),
                'new_window' => self::normalizeBoolean($linkData['new_window'] ?? false, false),
            ];
            if ($normalized['image'] !== '' || $normalized['text'] !== '' || $normalized['sub_text'] !== '') {
                $images[] = $normalized;
            }
        }

        $content['images'] = $images;

        return $content;
    }

    /**
     * 处理 rich_text 模块
     *
     * @param $content
     * @return array
     * @throws \Exception
     */
    private static function handleRichText($content): array
    {
        $content['module_size'] = $content['module_size'] ?? 'container';
        $content['data']        = self::sanitizeShopifyRichText(self::localizedValue($content['text'] ?? ''));

        return $content;
    }

    /**
     * 处理选项卡商品列表模块
     *
     * @param $content
     * @return array
     */
    private static function handleTabProducts($content): array
    {
        $content                    = self::normalizeResponsiveColumns($content);
        $tabs                       = is_array($content['tabs'] ?? null) ? $content['tabs'] : [];
        if (empty($tabs)) {
            $content['tabs']  = [];
            $content['title'] = self::localizedValue($content['title'] ?? '');

            return $content;
        }

        $normalizedTabs = [];
        foreach (array_slice($tabs, 0, 8) as $tab) {
            if (! is_array($tab)) {
                continue;
            }
            $tab['title']    = self::localizedValue($tab['title'] ?? '');
            $productsIds     = self::sanitizeIds($tab['products'] ?? [], 24);
            if ($productsIds) {
                $tab['products'] = ProductRepo::getProductsByIds($productsIds)->jsonSerialize();
            } else {
                $tab['products'] = [];
            }
            $normalizedTabs[] = $tab;
        }
        $content['tabs']            = $normalizedTabs;
        $content['title']           = self::localizedValue($content['title'] ?? '');

        return $content;
    }

    /**
     * 处理文章模块
     *
     * @param $content
     * @return array
     */
    private static function handlePage($content): array
    {
        $content['title'] = self::localizedValue($content['title'] ?? '');
        $pageIds          = self::sanitizeIds($content['items'] ?? [], 24);
        // A newly added page module has no selected records yet. Avoid a
        // needless database round-trip so the section still renders safely
        // in the builder and on a freshly installed store.
        $items                      = $pageIds ? PageRepo::getPagesByIds($pageIds)->jsonSerialize() : [];
        $items                      = hook_filter('service.design.module.page.handle', $items);
        $content['items']           = $items;
        $content                    = self::normalizeResponsiveColumns($content, 'columns_desktop', 4, 5, 2, 1);
        $content['show_view_all']   = self::normalizeBoolean($content['show_view_all'] ?? true, true);

        return $content;
    }

    /**
     * 处理商品模块
     *
     * @param $content
     * @return array
     * @throws \Exception
     */
    private static function handleProducts($content): array
    {
        $productIds                 = self::sanitizeIds($content['products'] ?? [], 24);
        $content['products']        = $productIds ? ProductRepo::getProductsByIds($productIds)->jsonSerialize() : [];
        $content['title']           = self::localizedValue($content['title'] ?? '');
        $content                    = self::normalizeResponsiveColumns($content);

        return $content;
    }

    /** Normalize and resolve the native latest/bestseller Builder sections. */
    private static function handleAutomaticProducts(string $code, array $content): array
    {
        $limit                       = min(24, max(1, (int) ($content['limit'] ?? 8)));
        $content['title']            = self::localizedValue($content['title'] ?? '');
        $content['sub_title']        = self::localizedValue($content['sub_title'] ?? '');
        $content['limit']            = $limit;
        $content['layout']           = in_array($content['layout'] ?? '', ['grid', 'scroll'], true) ? $content['layout'] : 'grid';
        $content                     = self::normalizeResponsiveColumns($content);
        $content['module_size']      = in_array($content['module_size'] ?? '', ['container-fluid', 'container', 'w-100'], true)
            ? $content['module_size']
            : 'container-fluid';

        $feeds               = app(ProductFeedService::class);
        $content['products'] = $code === 'bestseller' ? $feeds->bestsellers($limit) : $feeds->latest($limit);

        return $content;
    }

    /**
     * 处理图片以及链接
     * @throws \Exception
     */
    private static function handleImages($images): array
    {
        if (! is_array($images) || empty($images)) {
            return [];
        }

        $normalizedImages = [];
        foreach (array_slice($images, 0, 12) as $image) {
            if (! is_array($image)) {
                continue;
            }
            $imagePath  = '';
            $imageValue = $image['image'] ?? '';
            if (is_string($imageValue)) {
                $imagePath = $imageValue;
            } elseif (is_array($imageValue) && isset($imageValue['src'])) {
                $imagePath = is_array($image['image']['src']) ? $image['image']['src'][locale()] ?? '' : $image['image']['src'];
            } elseif (is_array($imageValue)) {
                $imagePath = self::localizedValue($imageValue['url'] ?? $imageValue);
            }

            $resolvedImage           = self::resolveImageSource($imagePath);
            $mediaPath               = (string) (parse_url($resolvedImage, PHP_URL_PATH) ?? '');
            $image['image']          = $resolvedImage;
            // Templates use image_path only to decide whether a published
            // slideshow has renderable media. Never retain an unsafe/raw path
            // here or an invalid URL can create a visible empty carousel.
            $image['image_path']     = $resolvedImage;
            $image['image_alt']      = self::localizedValue($image['image_alt'] ?? (is_array($imageValue) ? ($imageValue['alt'] ?? '') : ''));
            $image['type']           = preg_match('/\.(?:mp4|webm|ogg)$/i', $mediaPath) ? 'video' : 'image';

            $mobileImageValue = $image['mobile_image'] ?? '';
            if (is_string($mobileImageValue)) {
                $mobileImagePath = $mobileImageValue;
            } elseif (is_array($mobileImageValue) && isset($mobileImageValue['src'])) {
                $mobileImagePath = is_array($mobileImageValue['src'])
                    ? ($mobileImageValue['src'][locale()] ?? '')
                    : $mobileImageValue['src'];
            } elseif (is_array($mobileImageValue)) {
                $mobileImagePath = self::localizedValue($mobileImageValue['url'] ?? $mobileImageValue);
            } else {
                $mobileImagePath = '';
            }
            $resolvedMobileImage   = self::resolveImageSource($mobileImagePath);
            $mobileMediaPath       = (string) (parse_url($resolvedMobileImage, PHP_URL_PATH) ?? '');
            $image['mobile_image'] = preg_match('/\.(?:mp4|webm|ogg)$/i', $mobileMediaPath)
                ? ''
                : $resolvedMobileImage;
            $image['sub_title']      = self::localizedValue($image['sub_title'] ?? '');
            $image['title']          = self::localizedValue($image['title'] ?? '');
            $image['description']    = self::localizedValue($image['description'] ?? '');
            $image['text_position']  = in_array($image['text_position'] ?? '', ['start', 'center', 'end'], true) ? $image['text_position'] : 'start';

            $link = is_array($image['link'] ?? null) ? $image['link'] : [];
            if (is_array($link) && ! empty($link)) {
                $type           = $link['type'] ?? '';
                $value          = ($type === 'custom' || $type === 'static')
                    ? ($link['value'] ?? '')
                    : (int) ($link['value'] ?? 0);
                $image['link']['link'] = self::handleLink($type, $value);
            }
            $image['link']               = is_array($image['link'] ?? null) ? $image['link'] : [];
            $image['link']['link']       = is_string($image['link']['link'] ?? null) ? $image['link']['link'] : '';
            $image['link']['new_window'] = self::normalizeBoolean($image['link']['new_window'] ?? false, false);
            if ($image['image'] !== '' || $image['title'] !== '' || $image['sub_title'] !== '' || $image['description'] !== '') {
                $normalizedImages[] = $image;
            }
        }

        return $normalizedImages;
    }

    /**
     * 处理链接
     *
     * @param $type
     * @param $value
     * @return string
     * @throws \Exception
     */
    private static function handleLink($type, $value): string
    {
        if (! is_string($type) || $type === '' || ! is_scalar($value)) {
            return '';
        }
        $value = trim((string) $value);
        // An empty destination is a normal intermediate editor state. Route
        // resolution on that value can emit framework deprecations and, with
        // debug-to-exception handlers, turn a draft preview into a 500.
        if ($value === '') {
            return '';
        }

        try {
            return type_route($type, $value);
        } catch (\Throwable $exception) {
            // Links can refer to records removed after a design was saved.
            // Leaving the block visible is more useful than failing the page.
            logger()->notice('Ignoring a stale visual-builder link.', [
                'type'      => $type,
                'value'     => $value,
                'exception' => $exception->getMessage(),
            ]);

            return '';
        }
    }

    /** Removed module identifiers are rejected before they reach any renderer. */
    private static function isRetiredBuilderModule(string $code): bool
    {
        return in_array($code, ['newsletter', 'shopify-email-signup-banner'], true);
    }

    private static function sanitizeModuleData(array $moduleData): array
    {
        $code    = $moduleData['code']    ?? '';
        $content = $moduleData['content'] ?? [];

        if (! is_array($content)) {
            return $moduleData;
        }

        $style                     = is_array($content['style'] ?? null) ? $content['style'] : [];
        $background                = is_string($style['background_color'] ?? null) ? strtoupper(trim($style['background_color'])) : '';
        $style['background_color'] = preg_match('/^#[0-9A-F]{6}$/', $background) ? $background : '';
        $content['style']          = $style;
        // The editor only presents these options, but imports and Advanced
        // JSON can bypass the UI. Persist a canonical layout payload so a
        // malformed class or placement cannot make a storefront template
        // unexpectedly disappear.
        $content['module_size'] = in_array($content['module_size'] ?? '', ['container-fluid', 'container', 'w-100'], true)
            ? $content['module_size']
            : 'container-fluid';
        $content['placement'] = self::normalizePlacement($content['placement'] ?? 'home');

        $sectionLayout             = is_array($content['section_layout'] ?? null) ? $content['section_layout'] : [];
        $content['section_layout'] = [
            'spacing_mode' => ($sectionLayout['spacing_mode'] ?? 'inherit') === 'custom' ? 'custom' : 'inherit',
            'top'          => min(200, max(0, (int) ($sectionLayout['top'] ?? 64))),
            'bottom'       => min(200, max(0, (int) ($sectionLayout['bottom'] ?? 64))),
        ];
        $anchor            = is_string($content['anchor'] ?? null) ? trim($content['anchor']) : '';
        $content['anchor'] = preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $anchor) ? $anchor : '';

        if (array_key_exists('link', $content)) {
            $link            = is_array($content['link']) ? $content['link'] : [];
            $type            = is_string($link['type'] ?? null) ? trim($link['type']) : '';
            $value           = $link['value'] ?? '';
            $content['link'] = [
                // Custom plugin destinations are supported, but type names
                // remain data identifiers rather than arbitrary route input.
                'type'       => preg_match('/^[a-z][a-z0-9_]{0,63}$/', $type) ? $type : 'custom',
                'value'      => is_scalar($value) ? mb_substr(trim((string) $value), 0, 2048) : '',
                'new_window' => in_array($link['new_window'] ?? false, [true, 1, '1', 'true'], true),
            ];
        }
        $content = self::sanitizeStructuredLinks($content);

        if ($code == 'brand') {
            $content['brands'] = self::sanitizeIds($content['brands'] ?? [], 24);
        } elseif ($code == 'product') {
            $content['products'] = self::sanitizeIds($content['products'] ?? [], 24);
        } elseif ($code == 'tab_product') {
            $tabs            = array_slice(is_array($content['tabs'] ?? null) ? $content['tabs'] : [], 0, 8);
            $content['tabs'] = array_values(array_filter(array_map(function ($tab) {
                if (! is_array($tab)) {
                    return null;
                }

                $tab['products'] = self::sanitizeIds($tab['products'] ?? [], 24);

                return $tab;
            }, $tabs)));
        } elseif ($code == 'page') {
            $content['items'] = self::sanitizeIds($content['items'] ?? [], 24);
        }

        $boundedCollections = [
            'img_text_slideshow'   => ['images', 12],
            'img_text_slideshow_2' => ['images', 12],
            'icons'                => ['images', 10],
            'trust_cards'          => ['items', 6],
            'testimonials'         => ['items', 8],
            'campaign_offer'       => ['offers', 4],
            'payment_trust'        => ['benefits', 4],
            'social_proof'         => ['items', 5],
            'faq'                  => ['items', 12],
            'feature_grid'         => ['items', 8],
        ];
        if (isset($boundedCollections[$code])) {
            [$collectionKey, $limit] = $boundedCollections[$code];
            $content[$collectionKey] = array_slice(is_array($content[$collectionKey] ?? null) ? $content[$collectionKey] : [], 0, $limit);
        }

        // Keep layout controls canonical at the durable-data boundary as well
        // as during rendering. Imports, plugin writes and Advanced JSON can
        // otherwise persist values that the visual controls could never emit.
        if (in_array($code, ['tab_product', 'product', 'brand', 'page'], true)) {
            $content = self::normalizeResponsiveColumns($content, 'columns_desktop', 4, 5, $code === 'page' ? 2 : 3, $code === 'page' ? 1 : 2);
        }
        if (in_array($code, ['brand', 'page'], true)) {
            $content['show_view_all'] = self::normalizeBoolean($content['show_view_all'] ?? true, true);
        }
        if ($code === 'icons') {
            $content                  = self::normalizeIconColumns($content);
            $content['style_variant'] = in_array($content['style_variant'] ?? '', ['minimal', 'cards'], true)
                ? $content['style_variant']
                : 'minimal';
        } elseif ($code === 'faq') {
            $content['style_variant'] = in_array($content['style_variant'] ?? '', ['divided', 'cards'], true)
                ? $content['style_variant']
                : 'divided';
            $content['open_first'] = self::normalizeBoolean($content['open_first'] ?? true, true);
        } elseif ($code === 'trust_cards') {
            $content                  = self::normalizeResponsiveColumns($content, 'columns', 3, 4, 2, 1);
            $content['style_variant'] = in_array($content['style_variant'] ?? '', ['soft', 'outline', 'contrast'], true)
                ? $content['style_variant']
                : 'soft';
        } elseif ($code === 'testimonials') {
            $content                  = self::normalizeResponsiveColumns($content, 'columns', 3, 4, 2, 1);
            $content['style_variant'] = in_array($content['style_variant'] ?? '', ['cards', 'soft', 'minimal'], true)
                ? $content['style_variant']
                : 'cards';
        } elseif ($code === 'feature_grid') {
            $content                = self::normalizeResponsiveColumns($content, 'columns', 3, 4, 2, 1);
            $content['image_ratio'] = in_array($content['image_ratio'] ?? '', ['landscape', 'square', 'portrait'], true)
                ? $content['image_ratio']
                : 'landscape';
            $content['card_style'] = in_array($content['card_style'] ?? '', ['editorial', 'bordered', 'minimal'], true)
                ? $content['card_style']
                : 'editorial';
        }

        if ($code === 'payment_trust') {
            $content['payments'] = array_slice(is_array($content['payments'] ?? null) ? $content['payments'] : [], 0, 16);
        } elseif ($code === 'custom_code') {
            foreach (['html' => 80000, 'css' => 40000, 'settings' => 20000] as $key => $limit) {
                $content[$key] = is_scalar($content[$key] ?? null) ? mb_substr((string) $content[$key], 0, $limit) : '';
            }
            $content['height'] = min(1200, max(160, (int) ($content['height'] ?? 360)));
        } elseif (in_array($code, ['latest_products', 'bestseller'], true)) {
            $content['limit']           = min(24, max(1, (int) ($content['limit'] ?? 8)));
            $content['layout']          = in_array($content['layout'] ?? '', ['grid', 'scroll'], true) ? $content['layout'] : 'grid';
            $content                    = self::normalizeResponsiveColumns($content);
        }

        $moduleData['content'] = $content;

        return $moduleData;
    }

    private static function sanitizeIds($ids, int $limit = 48): array
    {
        if (! is_array($ids)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        })));

        return array_slice($ids, 0, max(1, $limit));
    }

    /** Icons may be much denser than product or editorial cards on phones. */
    private static function normalizeIconColumns(array $content): array
    {
        $requestedMobile = min(4, max(1, (int) ($content['columns_mobile'] ?? 3)));
        $content         = self::normalizeResponsiveColumns($content);

        $content['mobile_columns_mode'] = in_array($content['mobile_columns_mode'] ?? '', ['auto', 'fixed'], true)
            ? $content['mobile_columns_mode']
            : 'auto';
        $content['columns_mobile'] = min($content['columns_desktop'], $requestedMobile);

        return $content;
    }

    /**
     * Keep grid density monotonic across desktop, tablet and mobile. This is
     * shared by persisted Builder payloads and runtime rendering so legacy or
     * imported sections cannot force a five-column layout onto a tablet.
     */
    private static function normalizeResponsiveColumns(
        array $content,
        string $desktopKey = 'columns_desktop',
        int $desktopDefault = 4,
        int $desktopMax = 5,
        int $tabletDefault = 3,
        int $mobileDefault = 2,
    ): array {
        $desktop                   = min($desktopMax, max(2, (int) ($content[$desktopKey] ?? $desktopDefault)));
        $tablet                    = min(3, $desktop, max(1, (int) ($content['columns_tablet'] ?? $tabletDefault)));
        $mobile                    = min(2, $tablet, max(1, (int) ($content['columns_mobile'] ?? $mobileDefault)));
        $content[$desktopKey]      = $desktop;
        $content['columns_tablet'] = $tablet;
        $content['columns_mobile'] = $mobile;

        return $content;
    }

    /** Sanitize every typed link object, including links nested in cards/slides. */
    private static function sanitizeStructuredLinks(mixed $value, int $depth = 0): mixed
    {
        if (! is_array($value) || $depth > 8) {
            return $value;
        }

        foreach ($value as $key => $item) {
            if ($key === 'link' && is_array($item) && array_key_exists('value', $item)) {
                $type = is_string($item['type'] ?? null) && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $item['type'])
                    ? $item['type']
                    : 'custom';
                $raw       = is_scalar($item['value']) ? trim((string) $item['value']) : '';
                $sanitized = match ($type) {
                    'custom' => self::safeStorefrontUrl($raw),
                    'product', 'category', 'page', 'brand' => (string) max(0, (int) $raw),
                    'static' => preg_match('/^[a-z][a-z0-9_.-]{0,127}$/i', $raw) ? $raw : '',
                    default  => mb_substr($raw, 0, 2048),
                };
                $value[$key] = [
                    'type'       => $type,
                    'value'      => $sanitized === '0' ? '' : $sanitized,
                    'new_window' => self::normalizeBoolean($item['new_window'] ?? false, false),
                ];

                continue;
            }
            $value[$key] = self::sanitizeStructuredLinks($item, $depth + 1);
        }

        return $value;
    }

    /** Only enabled plugins may provide a namespaced storefront section view. */
    private static function isActiveDesignPlugin(string $pluginCode): bool
    {
        try {
            return app()->bound('plugin') && app('plugin')->checkActive($pluginCode);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function localizedValue(mixed $value): string
    {
        if (is_array($value)) {
            $localized = $value[locale()] ?? (reset($value) ?: '');

            return is_scalar($localized) ? (string) $localized : '';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
