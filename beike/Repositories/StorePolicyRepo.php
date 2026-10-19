<?php

namespace Beike\Repositories;

/**
 * Store policies are system settings rather than ordinary content pages.
 * This gives the footer stable URLs and keeps every policy in one admin form.
 */
class StorePolicyRepo
{
    private static ?\HTMLPurifier $purifier = null;

    public const DEFINITIONS = [
        'refund' => [
            'slug'          => 'refund-policy',
            'default_title' => 'Refund Policy',
        ],
        'shipping' => [
            'slug'          => 'shipping-policy',
            'default_title' => 'Shipping Policy',
        ],
        'privacy' => [
            'slug'          => 'privacy-policy',
            'default_title' => 'Privacy Policy',
        ],
        'terms' => [
            'slug'          => 'terms-of-service',
            'default_title' => 'Terms of Service',
        ],
        'contact' => [
            'slug'          => 'contact-us',
            'default_title' => 'Contact Us',
        ],
        'payment' => [
            'slug'          => 'payment-policy',
            'default_title' => 'Payment Policy',
        ],
        'cancellation' => [
            'slug'          => 'cancellation-policy',
            'default_title' => 'Order Cancellation Policy',
        ],
        'cookies' => [
            'slug'          => 'cookie-policy',
            'default_title' => 'Cookie Policy',
        ],
    ];

    public static function settings(): array
    {
        return self::normalizeSettings(system_setting('base.store_policies', []));
    }

    /**
     * Normalize both saved data and request data at a single trust boundary.
     */
    public static function normalizeSettings(mixed $settings): array
    {
        $settings = is_array($settings) ? $settings : [];
        $result   = [];

        foreach (self::DEFINITIONS as $key => $definition) {
            $policy  = is_array($settings[$key] ?? null) ? $settings[$key] : [];
            $content = is_scalar($policy['content'] ?? null) ? (string) $policy['content'] : '';

            $result[$key] = [
                'enabled' => self::normalizeBoolean($policy['enabled'] ?? false),
                // Footer labels and document titles form a fixed legal
                // navigation contract. Do not let stale or crafted settings
                // silently rename those standard destinations.
                'title'   => $definition['default_title'],
                'content' => self::normalizeContent(mb_substr($content, 0, 200000)),
            ];
        }

        return $result;
    }

    /**
     * Policies exposed to the footer. Empty policies never produce dead links.
     */
    public static function visiblePolicies(): array
    {
        $policies = [];

        foreach (self::settings() as $key => $policy) {
            if (! $policy['enabled'] || ! self::hasMeaningfulContent($policy['content'])) {
                continue;
            }

            $slug       = self::DEFINITIONS[$key]['slug'];
            $policies[] = [
                'key'   => $key,
                'slug'  => $slug,
                'title' => $policy['title'],
                'url'   => shop_route('policies.show', ['policy' => $slug]),
            ];
        }

        return $policies;
    }

    public static function findBySlug(string $slug): ?array
    {
        $key = null;
        foreach (self::DEFINITIONS as $candidateKey => $definition) {
            if ($definition['slug'] === $slug) {
                $key = $candidateKey;

                break;
            }
        }

        if ($key === null) {
            return null;
        }

        $policy = self::settings()[$key];
        if (! $policy['enabled'] || ! self::hasMeaningfulContent($policy['content'])) {
            return null;
        }

        return array_merge($policy, ['key' => $key, 'slug' => $slug]);
    }

    public static function slugs(): array
    {
        return array_column(self::DEFINITIONS, 'slug');
    }

    public static function hasMeaningfulContent(string $content): bool
    {
        $text = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\x{00A0}\x{200B}]+/u', '', $text) ?? '';

        return $text !== '';
    }

    private static function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Text pasted without markup becomes semantic paragraphs and line breaks.
     * Content entered through TinyMCE's HTML source dialog keeps its markup.
     */
    private static function normalizeContent(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", trim($content));
        if ($content === '') {
            return '';
        }

        if (! preg_match('/<\/?[a-z][^>]*>/i', $content)) {
            $paragraphs = preg_split('/\n{2,}/', $content) ?: [];
            $content    = implode('', array_map(static function (string $paragraph): string {
                $escaped = htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

                return $escaped === '' ? '' : '<p>' . nl2br($escaped) . '</p>';
            }, $paragraphs));
        }

        return self::sanitizeRichText($content);
    }

    /**
     * Plain text passes through unchanged, while authored HTML keeps common
     * document markup. Scripts, inline styles, and unsafe URLs are discarded.
     */
    private static function sanitizeRichText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        if (self::$purifier === null) {
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed', 'a[href|title|target|rel|class],blockquote[class],br,code[class],dd[class],div[class],dl[class],dt[class],em[class],h2[class],h3[class],h4[class],h5[class],h6[class],hr,img[src|alt|width|height|class],li[class],ol[class],p[class],pre[class],s[class],span[class],strong[class],sub,sup,table[class],tbody[class],td[colspan|rowspan|class],tfoot[class],th[colspan|rowspan|scope|class],thead[class],tr[class],u[class],ul[class]');
            $config->set('Attr.AllowedFrameTargets', ['_blank']);
            $config->set('Attr.AllowedRel', ['nofollow', 'noopener', 'noreferrer']);
            $config->set('URI.DisableExternalResources', true);
            $config->set('CSS.AllowedProperties', []);
            self::$purifier = new \HTMLPurifier($config);
        }

        $purified = self::$purifier->purify($html);

        return str_replace(["\r\n", "\r"], "\n", $purified);
    }
}
