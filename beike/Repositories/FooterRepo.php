<?php

/**
 * FooterRepo.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-08-11 18:16:06
 * @modified   2022-08-11 18:16:06
 */

namespace Beike\Repositories;

use App\Services\PlatformPayment\PlatformPaymentSessionService;
use App\Services\PlatformPayment\PlatformPaymentSettings;
use Beike\Services\BuilderStateService;
use Beike\Services\HtmlPurifierConfig;

class FooterRepo
{
    private const PAYMENT_MARKS_REQUEST_KEY = '_longos_storefront_payment_marks';

    /**
     * The editor and storefront share one shape. Keeping the default in one
     * place lets older, partial footer settings be opened and saved safely.
     */
    public static function defaultFooterSetting(): array
    {
        return [
            'services' => [
                'enable' => true,
                'items'  => [
                    ['image' => '', 'title' => ['en' => 'Secure checkout', 'zh_cn' => '安全结账'], 'sub_title' => ['en' => 'Encrypted payment processing', 'zh_cn' => '加密付款处理'], 'show' => true],
                    ['image' => '', 'title' => ['en' => 'Protected payment', 'zh_cn' => '付款保护'], 'sub_title' => ['en' => 'Trusted checkout providers', 'zh_cn' => '可信的结账服务'], 'show' => true],
                    ['image' => '', 'title' => ['en' => 'Tracked delivery', 'zh_cn' => '物流追踪'], 'sub_title' => ['en' => 'Follow your order after dispatch', 'zh_cn' => '发货后追踪订单'], 'show' => true],
                ],
            ],
            'content'  => [
                'intro'   => ['logo' => '', 'text' => [], 'social_network' => []],
                'link1'   => ['title' => [], 'links' => []],
                'link2'   => ['title' => [], 'links' => []],
                'link3'   => ['title' => [], 'links' => []],
                'contact' => ['telephone' => '', 'address' => [], 'email' => false],
            ],
            'bottom' => [
                'copyright'       => [],
                'image'           => '',
                'payments_enable' => true,
                'payments'        => ['visa', 'mastercard', 'amex', 'paypal', 'apple_pay', 'google_pay', 'klarna', 'unionpay'],
            ],
        ];
    }

    /**
     * Normalize legacy data before it reaches Blade or Vue. Settings are
     * user-editable JSON, so a partial record must never take down a page.
     */
    public static function normalizeFooterSetting(mixed $footerSetting): array
    {
        $default = self::defaultFooterSetting();
        if (! is_array($footerSetting)) {
            return $default;
        }

        $setting = array_replace_recursive($default, $footerSetting);
        // Editor collections are complete selections. Recursive replacement
        // merges numeric keys and would otherwise resurrect items the user
        // removed (most visibly payment methods) after a save.
        $sourceServices = is_array($footerSetting['services'] ?? null) ? $footerSetting['services'] : [];
        $sourceContent  = is_array($footerSetting['content'] ?? null) ? $footerSetting['content'] : [];
        $sourceBottom   = is_array($footerSetting['bottom'] ?? null) ? $footerSetting['bottom'] : [];
        if (array_key_exists('items', $sourceServices)) {
            $setting['services']['items'] = $sourceServices['items'];
        }
        foreach (['link1', 'link2', 'link3'] as $key) {
            if (is_array($sourceContent[$key] ?? null) && array_key_exists('links', $sourceContent[$key])) {
                $setting['content'][$key]['links'] = $sourceContent[$key]['links'];
            }
        }
        if (is_array($sourceContent['intro'] ?? null) && array_key_exists('social_network', $sourceContent['intro'])) {
            $setting['content']['intro']['social_network'] = $sourceContent['intro']['social_network'];
        }
        if (array_key_exists('payments', $sourceBottom)) {
            $setting['bottom']['payments'] = $sourceBottom['payments'];
        }
        foreach (['services', 'content', 'bottom'] as $key) {
            if (! is_array($setting[$key] ?? null)) {
                $setting[$key] = $default[$key];
            }
        }

        $setting['services']['enable'] = self::normalizeBoolean($setting['services']['enable'] ?? false, false);
        $serviceItems                  = is_array($setting['services']['items'] ?? null) ? $setting['services']['items'] : [];
        if (self::isDeprecatedDemoServices($serviceItems)) {
            $setting['services'] = $default['services'];
            $serviceItems        = $default['services']['items'];
        }
        $setting['services']['items']  = self::normalizeServiceItems($serviceItems);

        foreach (['link1', 'link2', 'link3'] as $key) {
            $setting['content'][$key] = is_array($setting['content'][$key] ?? null)
                ? array_replace_recursive($default['content'][$key], $setting['content'][$key])
                : $default['content'][$key];
            $setting['content'][$key]['title'] = is_array($setting['content'][$key]['title'] ?? null)
                ? $setting['content'][$key]['title'] : [];
            if (is_array($sourceContent[$key] ?? null) && array_key_exists('links', $sourceContent[$key])) {
                $setting['content'][$key]['links'] = $sourceContent[$key]['links'];
            }
            $setting['content'][$key]['links'] = is_array($setting['content'][$key]['links'] ?? null)
                ? self::normalizeLinks($setting['content'][$key]['links']) : [];
        }

        // Existing stores may still contain the exact demo-only group shipped
        // by earlier releases. Remove that known signature at read time so an
        // upgrade takes effect immediately, while preserving merchant-created
        // third columns and any customized links.
        if (self::isDeprecatedDemoOtherGroup($setting['content']['link3'])) {
            $setting['content']['link3'] = $default['content']['link3'];
        }

        $setting['content']['intro'] = is_array($setting['content']['intro'] ?? null)
            ? array_replace_recursive($default['content']['intro'], $setting['content']['intro'])
            : $default['content']['intro'];
        $setting['content']['intro']['logo'] = is_scalar($setting['content']['intro']['logo'] ?? null)
            ? (string) $setting['content']['intro']['logo'] : '';
        $setting['content']['intro']['text'] = is_array($setting['content']['intro']['text'] ?? null)
            ? $setting['content']['intro']['text'] : [];
        $socialItems = is_array($setting['content']['intro']['social_network'] ?? null)
            ? $setting['content']['intro']['social_network'] : [];
        $setting['content']['intro']['social_network'] = self::normalizeSocialItems($socialItems);
        $setting['content']['contact']                 = is_array($setting['content']['contact'] ?? null)
            ? array_replace_recursive($default['content']['contact'], $setting['content']['contact'])
            : $default['content']['contact'];
        $setting['content']['contact']['telephone'] = is_scalar($setting['content']['contact']['telephone'] ?? null)
            ? (string) $setting['content']['contact']['telephone'] : '';
        $setting['content']['contact']['address'] = is_array($setting['content']['contact']['address'] ?? null)
            ? $setting['content']['contact']['address'] : [];
        $setting['content']['contact']['email'] = self::normalizeBoolean($setting['content']['contact']['email'] ?? false, false);
        $setting['bottom']                      = is_array($setting['bottom'] ?? null)
            ? array_replace_recursive($default['bottom'], $setting['bottom'])
            : $default['bottom'];
        if (array_key_exists('payments', $sourceBottom)) {
            $setting['bottom']['payments'] = $sourceBottom['payments'];
        }
        $setting['bottom']['copyright'] = is_array($setting['bottom']['copyright'] ?? null)
            ? $setting['bottom']['copyright'] : [];
        $setting['bottom']['image'] = is_scalar($setting['bottom']['image'] ?? null)
            ? (string) $setting['bottom']['image'] : '';
        $setting['bottom']['payments_enable'] = self::normalizeBoolean($setting['bottom']['payments_enable'] ?? true, true);
        $supportedPayments                    = ['visa', 'mastercard', 'amex', 'paypal', 'apple_pay', 'google_pay', 'shop_pay', 'klarna', 'afterpay', 'unionpay', 'jcb', 'discover', 'diners', 'ideal', 'bancontact', 'sofort', 'giropay', 'cartes_bancaires'];
        $payments                             = is_array($setting['bottom']['payments'] ?? null) ? $setting['bottom']['payments'] : [];
        $setting['bottom']['payments']        = array_values(array_intersect($supportedPayments, array_map('strval', $payments)));

        return $setting;
    }

    /**
     * Earlier releases seeded four placeholder claims that did not describe
     * real commerce protections. Replace only that exact demo signature so
     * merchants keep every service item they have edited themselves.
     */
    private static function isDeprecatedDemoServices(array $items): bool
    {
        if (count($items) !== 4) {
            return false;
        }

        $titles = collect($items)->map(static function (mixed $item): string {
            if (! is_array($item) || ! is_array($item['title'] ?? null)) {
                return '';
            }

            return strtolower(trim((string) (($item['title']['en'] ?? '') ?: ($item['title']['zh_cn'] ?? ''))));
        })->values()->all();

        return $titles === [
            'with reduced activity',
            'delicate service',
            'material world',
            'return all',
        ];
    }

    private static function isDeprecatedDemoOtherGroup(array $group): bool
    {
        $title   = is_array($group['title'] ?? null) ? $group['title'] : [];
        $english = strtolower(trim((string) ($title['en'] ?? '')));
        $chinese = trim((string) ($title['zh_cn'] ?? ''));
        if ($english !== 'other' && $chinese !== '其他') {
            return false;
        }

        $links     = is_array($group['links'] ?? null) ? $group['links'] : [];
        $signature = array_map(static fn (array $link): string => sprintf(
            '%s:%s',
            (string) ($link['type'] ?? ''),
            (string) ($link['value'] ?? '')
        ), $links);

        $hasCustomizedText = collect($links)->contains(static function (array $link): bool {
            $text = is_array($link['text'] ?? null) ? $link['text'] : [];

            return collect($text)->contains(static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '');
        });

        return ! $hasCustomizedText && $signature === [
            'static:brands.index',
            'static:account.index',
            'page:20',
            'page:21',
        ];
    }

    private static function normalizeServiceItems(array $items): array
    {
        $normalized = [];
        foreach (array_slice($items, 0, 12) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $normalized[] = [
                'image'     => is_scalar($item['image'] ?? null) ? (string) $item['image'] : '',
                'title'     => is_array($item['title'] ?? null) ? $item['title'] : [],
                'sub_title' => is_array($item['sub_title'] ?? null) ? $item['sub_title'] : [],
                // Older settings predate per-item switches and were always
                // rendered. Keep those items visible on upgrade; only an
                // explicit editor switch may hide one.
                'show'      => self::normalizeBoolean($item['show'] ?? true, true),
            ];
        }

        return $normalized;
    }

    private static function normalizeSocialItems(array $items): array
    {
        $normalized = [];
        foreach (array_slice($items, 0, 12) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $normalized[] = [
                'image' => is_scalar($item['image'] ?? null) ? (string) $item['image'] : '',
                'link'  => self::normalizeExternalUrl($item['link'] ?? null),
                'show'  => self::normalizeBoolean($item['show'] ?? true, true),
            ];
        }

        return $normalized;
    }

    private static function normalizeLinks(array $links): array
    {
        $normalized = [];
        foreach (array_slice($links, 0, 30) as $link) {
            if (! is_array($link)) {
                continue;
            }
            $normalized[] = [
                'type'       => is_scalar($link['type'] ?? null) ? (string) $link['type'] : '',
                'value'      => is_scalar($link['value'] ?? null) ? (string) $link['value'] : '',
                'text'       => is_array($link['text'] ?? null) ? $link['text'] : [],
                'new_window' => self::normalizeBoolean($link['new_window'] ?? false, false),
            ];
        }

        return $normalized;
    }

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
     * 处理页尾编辑器数据
     *
     * @return array|mixed
     */
    public static function handleFooterData($footerSetting = []): array
    {
        if (empty($footerSetting)) {
            $footerSetting = BuilderStateService::storefrontState()['footer'];
        }

        $footerSetting = self::normalizeFooterSetting($footerSetting);

        $content                                        = $footerSetting['content'];
        $footerSetting['content']['intro']['text']      = self::sanitizeRichText(self::localizedText($content['intro']['text'] ?? ''));
        $footerSetting['content']['contact']['address'] = self::localizedText($content['contact']['address'] ?? '');
        $contentLinkKeys                                = ['link1', 'link2', 'link3'];
        foreach ($contentLinkKeys as $contentLinkKey) {
            $links                                              = $content[$contentLinkKey]['links'] ?? [];
            $footerSetting['content'][$contentLinkKey]['title'] = self::localizedText($content[$contentLinkKey]['title'] ?? '');
            $links                                              = collect($links)->map(function ($link) {
                $fallbackText = self::localizedText($link['text'] ?? '');

                try {
                    $link = handle_link($link);
                } catch (\Throwable $exception) {
                    // Footer links are editable references. Deleted pages and
                    // products should disappear from navigation, not 500 the
                    // storefront or the integrated builder preview.
                    logger()->notice('Ignoring a stale footer link.', [
                        'type'      => $link['type']  ?? '',
                        'value'     => $link['value'] ?? '',
                        'exception' => $exception->getMessage(),
                    ]);
                    $link['link'] = '';
                    $link['text'] = $fallbackText;
                }

                $link['link'] = is_string($link['link'] ?? null) ? $link['link'] : '';
                $link['text'] = is_scalar($link['text'] ?? null) ? (string) $link['text'] : $fallbackText;

                return $link;
            })->toArray();
            $footerSetting['content'][$contentLinkKey]['links'] = $links;
        }

        foreach ($footerSetting['services']['items'] as $index => $item) {
            $footerSetting['services']['items'][$index]['title']     = self::localizedText($item['title'] ?? '');
            $footerSetting['services']['items'][$index]['sub_title'] = self::localizedText($item['sub_title'] ?? '');
        }

        $copyright = self::sanitizeRichText(self::localizedText($footerSetting['bottom']['copyright'] ?? ''));
        // Do not revive historical vendor wording from an old footer setting.
        $footerSetting['bottom']['copyright']        = preg_replace('/beikeshop(?:\.com)?|powered\s+by\s+beikeshop/i', 'LONGOS', $copyright) ?: '';
        $footerSetting['bottom']['enabled_payments'] = self::enabledPaymentMarks(
            $footerSetting['bottom']['payments'],
            $footerSetting['bottom']['payments_enable']
        );

        return $footerSetting;
    }

    /**
     * Payment marks are claims about what checkout can really accept. Keep
     * the merchant's display selection, but intersect it with payment plugins
     * that are enabled for the current tenant instead of showing decorative
     * brands that cannot be used at checkout.
     */
    private static function enabledPaymentMarks(array $selected, bool $enabled): array
    {
        if (! $enabled || $selected === []) {
            return [];
        }

        return array_values(array_intersect($selected, self::availablePaymentMarks()));
    }

    /**
     * Return only brands backed by a payment method that checkout can expose
     * for the current tenant. Product and footer claims share this source of
     * truth so an unavailable provider is never advertised to customers.
     *
     * @return list<string>
     */
    public static function availablePaymentMarks(): array
    {
        $requestAttributes = null;

        try {
            $requestAttributes = app()->bound('request') ? request()->attributes : null;
        } catch (\Throwable) {
            // Repository normalization is also exercised by isolated CLI
            // tooling where Laravel has not installed a request container.
        }
        if ($requestAttributes?->has(self::PAYMENT_MARKS_REQUEST_KEY)) {
            $cached = $requestAttributes->get(self::PAYMENT_MARKS_REQUEST_KEY);

            return is_array($cached) ? $cached : [];
        }

        $pluginCodes = [];

        try {
            $pluginCodes = PluginRepo::getPaymentMethods()
                ->pluck('code')
                ->filter(fn (mixed $code): bool => is_scalar($code) && trim((string) $code) !== '')
                ->map(fn (mixed $code): string => strtolower(trim((string) $code)))
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable $exception) {
            logger()->notice('Unable to discover enabled storefront payment marks.', [
                'exception' => $exception::class,
                'message'   => $exception->getMessage(),
            ]);

        }

        $available = [];
        foreach ($pluginCodes as $code) {
            if ($code === 'paypal') {
                $available = array_merge(
                    $available,
                    PlatformPaymentSettings::paymentMarksForProviders(['paypal'])
                );
            } elseif ($code === 'stripe') {
                // The bundled Stripe checkout currently mounts card Elements,
                // not wallet buttons. Never advertise Apple Pay or Google Pay
                // until those checkout methods are actually available.
                $available = array_merge($available, ['visa', 'mastercard', 'amex', 'discover', 'jcb', 'diners', 'unionpay', 'cartes_bancaires']);
            } elseif ($code === PlatformPaymentSessionService::PAYMENT_CODE) {
                try {
                    $providers = app(PlatformPaymentSettings::class)->availableProviders();
                    $available = array_merge($available, PlatformPaymentSettings::paymentMarksForProviders($providers));
                } catch (\Throwable $exception) {
                    logger()->notice('Unable to resolve hosted payment marks for the footer.', [
                        'exception' => $exception::class,
                    ]);
                }
            } else {
                $available[] = str_replace('-', '_', $code);
            }
        }

        $available = array_values(array_unique($available));
        $requestAttributes?->set(self::PAYMENT_MARKS_REQUEST_KEY, $available);

        return $available;
    }

    private static function localizedText(mixed $value): string
    {
        if (! is_array($value)) {
            return is_scalar($value) ? (string) $value : '';
        }

        $localized = $value[locale()] ?? $value['en'] ?? reset($value) ?: '';

        return is_scalar($localized) ? (string) $localized : '';
    }

    /**
     * Social links are rendered as direct anchors rather than routed links.
     * Restrict them to absolute HTTP(S) URLs so legacy JSON cannot introduce
     * a javascript: or data: URL into the storefront.
     */
    private static function normalizeExternalUrl(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $url = trim((string) $value);
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : '';
    }

    /**
     * Footer introduction and copyright are intentionally rich text. Purify
     * them at render time as well as normalizing their JSON shape, which keeps
     * existing stores compatible while making stale editor data safe.
     */
    private static function sanitizeRichText(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $config = HtmlPurifierConfig::create();
        $config->set('HTML.Allowed', 'a[href|title|target|rel|class],br,div[class],em[class],p[class],span[class],strong[class],b[class],i[class],ul[class],ol[class],li[class]');
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('URI.DisableExternalResources', true);
        $config->set('CSS.AllowedProperties', []);

        return (new \HTMLPurifier($config))->purify($html);
    }
}
