<?php

namespace Beike\Admin\Services;

/**
 * Native, serializable definitions for the modern Store Builder sections.
 *
 * A definition deliberately contains data only: no Vue component, Blade
 * fragment, callback, or executable script. Plugins can use the same shape
 * through the admin.design.index.data hook.
 */
class DesignEditorCatalog
{
    /**
     * Legacy sections intentionally retired from the insert inventory.
     *
     * Their renderers remain only so historic storefront data can still be
     * displayed during an upgrade; each capability has a more complete,
     * schema-backed replacement in the migrated component registry.
     */
    public const RETIRED_DUPLICATE_CODES = [
        'img_text_banner', 'slideshow', 'image100',
        'image200', 'image300', 'image301', 'image400', 'image401', 'image402', 'image403',
        'img_text_banner_multiple',
    ];

    /** Validate data-only app-embed definitions supplied by installed plugins. */
    public static function normalizeAppEmbeds(array $definitions): array
    {
        $normalized = [];
        $seen       = [];
        foreach ($definitions as $definition) {
            if (! is_array($definition)) {
                continue;
            }
            $code = trim((string) ($definition['code'] ?? ''));
            if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) || isset($seen[$code])) {
                continue;
            }
            $name         = trim(strip_tags((string) ($definition['name'] ?? $code)));
            $description  = trim(strip_tags((string) ($definition['description'] ?? '')));
            $normalized[] = [
                'code'            => $code,
                'name'            => mb_substr($name ?: $code, 0, 100),
                'description'     => mb_substr($description, 0, 280),
                'default_enabled' => ! in_array($definition['default_enabled'] ?? true, [false, 0, '0', 'false', 'off', 'no', ''], true),
            ];
            $seen[$code] = true;
        }

        return $normalized;
    }

    /**
     * Normalize core and plugin contributions before they reach the browser.
     * The editor accepts data-only definitions; malformed plugin metadata is
     * ignored instead of making the entire design route fail to serialize.
     */
    public static function normalize(array $definitions): array
    {
        $normalized = [];
        $seen       = [];
        foreach ($definitions as $definition) {
            if (! is_array($definition)) {
                continue;
            }
            $code = trim((string) ($definition['code'] ?? ''));
            if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) || isset($seen[$code])) {
                continue;
            }
            $viewPath = trim((string) ($definition['view_path'] ?? "design.{$code}"));
            // A plugin view namespace may only be registered after its boot
            // provider runs. Validate the path shape here; preview() remains
            // the authoritative runtime guard for actual view availability.
            if (str_contains($viewPath, '..') || ! preg_match('/^(?:[A-Za-z][A-Za-z0-9_]*::)?[A-Za-z][A-Za-z0-9_.\/-]{0,191}$/', $viewPath)) {
                continue;
            }
            $make = $definition['make'] ?? [];
            if (! is_array($make)) {
                $make = [];
            }

            try {
                // A JSON round-trip guarantees values are browser-safe and
                // strips objects, closures, and invalid string encodings.
                $make = json_decode(json_encode($make, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $make = [];
            }
            $fields       = self::normalizeFields($definition['fields'] ?? []);
            $name         = trim(strip_tags((string) ($definition['name'] ?? $code)));
            $description  = mb_substr(trim(strip_tags((string) ($definition['description'] ?? ''))), 0, 180);
            $category     = trim((string) ($definition['category'] ?? 'extension'));
            $availableOn  = is_array($definition['available_on'] ?? null) ? $definition['available_on'] : ['home', 'product', 'category'];
            $availableOn  = array_values(array_unique(array_filter($availableOn, static fn ($page): bool => in_array($page, ['home', 'product', 'category', 'page'], true))));
            $keywords     = is_array($definition['keywords'] ?? null) ? $definition['keywords'] : [];
            $keywords     = array_values(array_unique(array_filter(array_map(static fn ($keyword): string => mb_substr(trim(strip_tags((string) $keyword)), 0, 40), $keywords))));
            $capabilities = is_array($definition['capabilities'] ?? null) ? $definition['capabilities'] : [];
            $capabilities = array_intersect_key($capabilities, array_flip(['supports_responsive', 'supports_animation', 'supports_commerce', 'supports_slots', 'supports_visibility', 'supports_custom_class']));
            $capabilities = array_map(static fn ($value): bool => ! in_array($value, [false, 0, '0', 'false', 'off', 'no', ''], true), $capabilities);
            $normalized[] = [
                'code'              => $code,
                'name'              => mb_substr($name ?: $code, 0, 100),
                'description'       => $description,
                'view_path'         => $viewPath,
                'category'          => preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $category) ? $category : 'extension',
                'make'              => $make,
                'fields'            => $fields,
                'component_version' => min(99, max(1, (int) ($definition['component_version'] ?? 1))),
                'keywords'          => array_slice($keywords, 0, 8),
                'capabilities'      => array_merge([
                    'supports_responsive' => true,
                    'supports_visibility' => true,
                ], $capabilities),
                // This mirrors a theme section schema: plugins may declare
                // the page templates and maximum number of instances they
                // support without sending executable editor code.
                'available_on'  => $availableOn ?: ['home'],
                'max_instances' => min(30, max(1, (int) ($definition['max_instances'] ?? 12))),
            ];
            $seen[$code] = true;
        }

        return $normalized;
    }

    /**
     * Keep plugin inspector fields data-only and bounded. This gives plugins
     * useful native controls without importing executable React components
     * into the core Builder bundle.
     */
    private static function normalizeFields(mixed $fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        $normalized = [];
        $seen       = [];
        $types      = ['text', 'textarea', 'number', 'range', 'checkbox', 'select', 'radio', 'url', 'image_picker', 'video', 'video_url', 'color'];
        foreach (array_slice($fields, 0, 40) as $field) {
            if (! is_array($field)) {
                continue;
            }
            $id   = trim((string) ($field['id'] ?? ''));
            $type = trim((string) ($field['type'] ?? ''));
            if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $id) || isset($seen[$id]) || ! in_array($type, $types, true)) {
                continue;
            }

            $label = mb_substr(trim(strip_tags((string) ($field['label'] ?? $id))), 0, 100);
            $item  = ['id' => $id, 'type' => $type, 'label' => $label ?: $id];
            foreach (['min', 'max', 'step'] as $bound) {
                if (is_numeric($field[$bound] ?? null)) {
                    $item[$bound] = max(-1000000, min(1000000, (float) $field[$bound]));
                }
            }
            if (is_scalar($field['default'] ?? null) || is_array($field['default'] ?? null)) {
                try {
                    $item['default'] = json_decode(json_encode($field['default'], JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    // Invalid defaults are omitted; the plugin's make payload
                    // remains the authoritative initial state.
                }
            }
            if (in_array($type, ['select', 'radio'], true) && is_array($field['options'] ?? null)) {
                $options = [];
                foreach (array_slice($field['options'], 0, 50) as $option) {
                    if (! is_array($option) || ! is_scalar($option['value'] ?? null)) {
                        continue;
                    }
                    $value     = mb_substr((string) $option['value'], 0, 160);
                    $text      = mb_substr(trim(strip_tags((string) ($option['label'] ?? $value))), 0, 100);
                    $options[] = ['label' => $text ?: $value, 'value' => $value];
                }
                if ($options === []) {
                    continue;
                }
                $item['options'] = $options;
            }
            $normalized[] = $item;
            $seen[$id]    = true;
        }

        return $normalized;
    }

    public static function core(): array
    {
        $text = static function (string $value): array {
            return array_fill_keys(array_column(locales(), 'code'), $value);
        };
        $descriptions = [
            'promo_bar'            => '在页面顶部展示活动文案和跳转入口。',
            'trust_cards'          => '用服务承诺卡片建立购物信任。',
            'testimonials'         => '展示顾客评价和评分。',
            'campaign_offer'       => '配置优惠码、倒计时和阶梯优惠。',
            'payment_trust'        => '用于商品详情页的支付与售后保障。',
            'story_block'          => '以图片和文案讲述品牌或活动故事。',
            'rich_text'            => '使用可视化富文本编辑品牌、政策或活动内容。',
            'faq'                  => '以可折叠问答解决顾客的常见疑问。',
            'feature_grid'         => '用图片卡片展示分类、系列或品牌卖点。',
            'social_proof'         => '以数据和承诺展示社会信任。',
            'custom_code'          => '添加经过安全过滤的自定义 HTML 与 CSS。',
            'img_text_slideshow'   => '全宽图文轮播，可附加滚动公告。',
            'img_text_slideshow_2' => '图片叠加文案的图文轮播，可附加滚动公告。',
            'tab_product'          => '按标签分组展示已选择的真实商品。',
            'product'              => '展示手动选择的真实商品。',
            'brand'                => '展示手动选择的真实品牌。',
            'icons'                => '展示图标、权益文案和对应跳转。',
            'page'                 => '展示手动选择的真实内容页。',
            'latest_products'      => '自动展示最新上架且可购买的商品。',
            'bestseller'           => '按真实销量自动展示店铺热卖商品。',
        ];
        $module = static function (string $code, string $name, array $make) use ($descriptions): array {
            return [
                'code'              => $code,
                'name'              => $name,
                'description'       => $descriptions[$code] ?? '',
                'view_path'         => "design.{$code}",
                'make'              => $make,
                'component_version' => 1,
                'keywords'          => [$code],
                'capabilities'      => [
                    'supports_responsive' => true,
                    'supports_visibility' => true,
                ],
                'available_on'  => ['home', 'product', 'category', 'page'],
                'max_instances' => 12,
            ];
        };
        $legacy = [
            'img_text_slideshow'   => trans('admin/design_builder.module_img_text_slideshow'),
            'img_text_slideshow_2' => trans('admin/design_builder.module_img_text_slideshow_2'),
            'tab_product'          => trans('admin/design_builder.module_tab_products'),
            'product'              => trans('admin/design_builder.module_product'),
            'brand'                => trans('admin/design_builder.module_brand'),
            'icons'                => trans('admin/design_builder.module_icons'),
            'page'                 => trans('admin/design_builder.module_page'),
            'latest_products'      => trans('admin/design_builder.module_latest_products'),
            'bestseller'           => trans('admin/design_builder.module_bestseller'),
        ];

        $catalog = [
            $module('promo_bar', trans('admin/design_builder.module_promo_bar'), [
                'style'       => ['background_color' => '#000000'], 'module_size' => 'container-fluid',
                'title'       => $text('限时优惠'), 'sub_title' => $text('让顾客的第一笔订单更有惊喜'),
                'button_text' => $text('立即选购'), 'link' => ['type' => 'product', 'value' => ''],
            ]),
            $module('trust_cards', trans('admin/design_builder.module_trust_cards'), [
                'style'     => ['background_color' => ''], 'module_size' => 'container-fluid', 'title' => $text('安心选购'), 'columns' => 3, 'columns_tablet' => 2, 'columns_mobile' => 1, 'style_variant' => 'soft',
                'sub_title' => $text('向顾客清晰传达最重要的服务承诺'),
                'items'     => [['icon' => '✦', 'title' => $text('精选品质'), 'description' => $text('每件商品都提供清晰信息与可靠的品质标准。'), 'link' => ['type' => 'custom', 'value' => '', 'new_window' => false]], ['icon' => '↗', 'title' => $text('快速服务'), 'description' => $text('让订单、物流和售后进度一目了然。'), 'link' => ['type' => 'custom', 'value' => '', 'new_window' => false]], ['icon' => '♥', 'title' => $text('贴心支持'), 'description' => $text('需要帮助时可快速联系店铺服务团队。'), 'link' => ['type' => 'custom', 'value' => '', 'new_window' => false]]],
            ]),
            $module('testimonials', trans('admin/design_builder.module_testimonials'), [
                'style'     => ['background_color' => ''], 'module_size' => 'container-fluid', 'title' => $text('顾客真实评价'), 'columns' => 3, 'columns_tablet' => 2, 'columns_mobile' => 1, 'style_variant' => 'cards',
                'sub_title' => $text('用真实的购物体验建立顾客信任'),
                'items'     => [['quote' => $text('商品信息很清楚，物流的每一步都可以追踪。'), 'name' => $text('林女士'), 'detail' => $text('已验证购买'), 'rating' => 5], ['quote' => $text('客服回复很快，商品与描述完全一致。'), 'name' => $text('周先生'), 'detail' => $text('已验证购买'), 'rating' => 5], ['quote' => $text('结账过程很顺畅，我还会再次购买。'), 'name' => $text('陈女士'), 'detail' => $text('回购顾客'), 'rating' => 5]],
            ]),
            $module('campaign_offer', trans('admin/design_builder.module_campaign_offer'), [
                'style'      => ['background_color' => ''], 'module_size' => 'container-fluid', 'placement' => 'both', 'title' => $text('多买多省'),
                'sub_title'  => $text('选择最适合你的优惠组合'), 'timer_mode' => 'daily', 'ends_at' => '', 'code' => 'SAVE15',
                'code_label' => $text('复制优惠码并在结账时使用'), 'terms' => $text('优惠不可叠加，最终资格以结账页显示为准。'),
                'offers'     => [['quantity' => 2, 'condition' => $text('购买 2 件或以上'), 'benefit' => $text('立减 15%'), 'description' => $text('结账时输入优惠码'), 'badge' => $text(''), 'best' => false, 'free_shipping' => false], ['quantity' => 3, 'condition' => $text('购买 3 件或以上'), 'benefit' => $text('85 折 + 免运费'), 'description' => $text('最超值的组合'), 'badge' => $text('推荐'), 'best' => true, 'free_shipping' => true]],
            ]),
            $module('payment_trust', trans('admin/design_builder.module_payment_trust'), [
                'style'     => ['background_color' => ''], 'module_size' => 'container-fluid', 'placement' => 'product', 'title' => $text('安心购买'),
                'sub_title' => $text('安全结账与购买保障'), 'status_text' => $text('安全支付'), 'payments_title' => $text('支持的支付方式'), 'payments_sub' => $text('可在结账时选择'),
                'benefits'  => [['icon' => '✓', 'title' => $text('安全结账'), 'text' => $text('加密支付保障'), 'gold' => false], ['icon' => '✓', 'title' => $text('物流可追踪'), 'text' => $text('随时查看订单进度'), 'gold' => false], ['icon' => '30', 'title' => $text('30 天退换'), 'text' => $text('符合条件的商品可申请退换'), 'gold' => true]],
                'payments'  => ['visa', 'mastercard', 'amex', 'paypal', 'apple_pay', 'google_pay', 'klarna', 'afterpay', 'unionpay', 'jcb', 'discover', 'ideal'],
            ]),
            $module('story_block', '故事横幅', [
                'style'   => ['background_color' => ''], 'module_size' => 'container-fluid', 'placement' => 'home', 'theme' => 'light', 'image_position' => 'right', 'image' => '',
                'eyebrow' => $text('为每一个日常时刻而设计'), 'title' => $text('用动人的方式讲述品牌故事'), 'description' => $text('用一张有感染力的图片和简洁文案，介绍分类、品牌价值或当季活动。'), 'button_text' => $text('探索商品分类'), 'link' => ['type' => 'custom', 'value' => '', 'new_window' => false],
            ]),
            $module('feature_grid', '精选卡片网格', [
                'style' => ['background_color' => ''], 'module_size' => 'container-fluid', 'placement' => 'home', 'columns' => 3, 'columns_tablet' => 2, 'columns_mobile' => 1, 'image_ratio' => 'landscape', 'card_style' => 'editorial',
                'title' => $text('按自己的方式探索'), 'sub_title' => $text('用清晰的视觉入口帮助顾客快速找到内容。'),
                'items' => [
                    ['image' => '', 'image_alt' => $text(''), 'eyebrow' => $text('新品'), 'title' => $text('新品上新'), 'text' => $text('展示最新系列或本季主推商品。'), 'link_label' => $text('立即探索'), 'link' => ['type' => 'custom', 'value' => '', 'new_window' => false]],
                    ['image' => '', 'image_alt' => $text(''), 'eyebrow' => $text('热门'), 'title' => $text('顾客喜爱'), 'text' => $text('展示店铺中最受欢迎的分类。'), 'link_label' => $text('查看系列'), 'link' => ['type' => 'custom', 'value' => '', 'new_window' => false]],
                    ['image' => '', 'image_alt' => $text(''), 'eyebrow' => $text('品牌'), 'title' => $text('了解我们'), 'text' => $text('让品牌故事成为购买决策的一部分。'), 'link_label' => $text('阅读故事'), 'link' => ['type' => 'custom', 'value' => '', 'new_window' => false]],
                ],
            ]),
            $module('rich_text', '富文本内容', [
                'style' => ['background_color' => ''], 'module_size' => 'container', 'placement' => 'home',
                'text'  => $text('<h2>讲述你的故事</h2><p>在这里编辑一段清晰、可读的图文内容。</p>'),
            ]),
            $module('faq', '常见问题', [
                'style' => ['background_color' => ''], 'module_size' => 'container', 'placement' => 'home', 'style_variant' => 'divided', 'open_first' => true,
                'title' => $text('常见问题'), 'sub_title' => $text('购买前可能想了解的信息。'),
                'items' => [
                    ['question' => $text('如何查看物流轨迹？'), 'answer' => $text('订单发货后，可在订单详情中实时查看物流进度。')],
                    ['question' => $text('支持哪些付款方式？'), 'answer' => $text('结账页会展示当前店铺已启用的安全付款方式。')],
                    ['question' => $text('如何联系店铺？'), 'answer' => $text('可使用页脚中的联系方式获取订单和售后帮助。')],
                ],
            ]),
            $module('social_proof', '数据与信任背书', [
                'style' => ['background_color' => ''], 'module_size' => 'container-fluid', 'placement' => 'both', 'theme' => 'ink', 'title' => $text('打造更好的购物体验'), 'sub_title' => $text('展示准确的数据和顾客可以信赖的服务承诺。'),
                'items' => [['value' => $text('4.9 / 5'), 'label' => $text('平均顾客评分'), 'detail' => $text('来自已验证的店铺反馈')], ['value' => $text('24 / 7'), 'label' => $text('客户服务'), 'detail' => $text('在需要时及时提供帮助')], ['value' => $text('安全'), 'label' => $text('受保护的结账'), 'detail' => $text('你的信息始终受到保护')]],
            ]),
            $module('custom_code', trans('admin/design_builder.module_custom_code'), [
                'style'    => ['background_color' => ''], 'module_size' => 'container-fluid', 'title' => '自定义区块', 'height' => 360,
                'settings' => "{\n  \"heading\": \"创建属于你的模块\",\n  \"description\": \"可在编辑器中添加安全的 HTML 与 CSS。\"\n}",
                'html'     => '<div class="custom-section"><h2>{{ section.settings.heading }}</h2><p>{{ section.settings.description }}</p></div>', 'css' => '.custom-section { padding: 48px 24px; text-align: center; background: #f7f7f7; border-radius: 16px; }',
            ]),
        ];

        // Keep the former native sections as real, task-specific modules.
        // Their full payloads are still backward-compatible, but a fresh
        // section must start with the same shape its Blade renderer consumes;
        // otherwise unrelated modules collapse into the same generic title
        // form in the Puck inspector.
        foreach ($legacy as $code => $name) {
            $make = [
                'style'       => ['background_color' => ''],
                'module_size' => 'container-fluid',
                'placement'   => 'home',
                'title'       => $text('新模块'),
            ];
            if (in_array($code, ['img_text_slideshow', 'img_text_slideshow_2'], true)) {
                $make['autoplay']       = false;
                $make['autoplay_delay'] = 5000;
                $make['images']         = [[
                    'image'       => '', 'image_alt' => $text(''), 'sub_title' => $text('新品推荐'), 'title' => $text('添加轮播图片和文案'),
                    'description' => $text('在右侧添加图片、链接和文字内容。'), 'text_position' => 'start', 'link' => ['type' => 'custom', 'value' => '', 'new_window' => false],
                ]];
                $make['scroll_text'] = ['text' => $text(''), 'bg' => '#000000', 'color' => '#FFFFFF', 'font_size' => 14, 'padding' => 10];
                $make['module_size'] = 'w-100';
            } elseif ($code === 'tab_product') {
                $make['tabs']            = [['title' => $text('精选商品'), 'products' => []]];
                $make['columns_desktop'] = 4;
                $make['columns_tablet']  = 3;
                $make['columns_mobile']  = 2;
            } elseif ($code === 'product') {
                $make['products']        = [];
                $make['columns_desktop'] = 4;
                $make['columns_tablet']  = 3;
                $make['columns_mobile']  = 2;
            } elseif ($code === 'brand') {
                $make['brands']          = [];
                $make['columns_desktop'] = 4;
                $make['columns_tablet']  = 3;
                $make['columns_mobile']  = 2;
                $make['show_view_all']   = true;
            } elseif ($code === 'icons') {
                $make['images']          = [['image' => '', 'image_alt' => $text(''), 'text' => $text('服务保障'), 'sub_text' => $text('填写权益说明'), 'link' => ['type' => 'custom', 'value' => '', 'new_window' => false]]];
                $make['columns_desktop'] = 4;
                $make['columns_tablet']  = 2;
                $make['columns_mobile']  = 2;
                $make['style_variant']   = 'minimal';
            } elseif ($code === 'page') {
                $make['items']           = [];
                $make['columns_desktop'] = 4;
                $make['columns_tablet']  = 2;
                $make['columns_mobile']  = 1;
                $make['show_view_all']   = true;
            } elseif (in_array($code, ['latest_products', 'bestseller'], true)) {
                $make['title']           = $text($code === 'bestseller' ? '热卖商品' : '最新商品');
                $make['sub_title']       = $text($code === 'bestseller' ? '顾客正在购买的热门精选' : '探索刚刚上架的新品');
                $make['limit']           = 8;
                $make['layout']          = 'grid';
                $make['columns_desktop'] = 4;
                $make['columns_tablet']  = 3;
                $make['columns_mobile']  = 2;
            }
            $catalog[] = $module($code, $name, $make);
        }

        $categories = [
            'hero'      => ['img_text_slideshow', 'img_text_slideshow_2'],
            'commerce'  => ['product', 'tab_product', 'brand', 'page', 'latest_products', 'bestseller'],
            'content'   => ['story_block', 'feature_grid', 'rich_text', 'faq'],
            'marketing' => ['promo_bar', 'campaign_offer', 'custom_code'],
            'trust'     => ['icons', 'trust_cards', 'testimonials', 'payment_trust', 'social_proof'],
        ];
        foreach ($catalog as &$definition) {
            $definition['category'] = 'content';
            foreach ($categories as $category => $codes) {
                if (in_array($definition['code'], $codes, true)) {
                    $definition['category'] = $category;

                    break;
                }
            }

            // Product modules render inside the purchase column, not in a
            // full-width page slot. Keep oversized editorial and carousel
            // sections out of that narrow context while preserving all four
            // destinations for genuinely compact commerce/trust blocks.
            $definition['available_on'] = match ($definition['code']) {
                'payment_trust' => ['product'],
                'campaign_offer', 'social_proof', 'icons', 'faq' => ['home', 'product', 'category', 'page'],
                default => ['home', 'category', 'page'],
            };
            $definition['max_instances'] = match ($definition['code']) {
                'payment_trust' => 1,
                'campaign_offer', 'social_proof', 'faq' => 2,
                'promo_bar' => 3,
                'img_text_slideshow', 'img_text_slideshow_2' => 6,
                'latest_products', 'bestseller' => 3,
                default => 8,
            };
            if (in_array($definition['code'], ['latest_products', 'bestseller'], true)) {
                $definition['component_version'] = 2;
                $definition['keywords']          = $definition['code'] === 'bestseller'
                    ? ['热卖', '热销', '销量', '商品']
                    : ['最新', '新品', '上新', '商品'];
                $definition['capabilities']['supports_commerce'] = true;
            }
        }
        unset($definition);

        return self::normalize($catalog);
    }
}
