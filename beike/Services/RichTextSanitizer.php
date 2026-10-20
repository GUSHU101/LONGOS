<?php

namespace Beike\Services;

class RichTextSanitizer
{
    private static ?\HTMLPurifier $purifier = null;

    /** @var array<string, string> */
    private static array $cache = [];

    public static function sanitize(mixed $value): string
    {
        $html = is_scalar($value) ? mb_substr((string) $value, 0, 2_000_000) : '';
        if (trim($html) === '') {
            return '';
        }

        $key = hash('sha256', $html);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $clean = self::addSafeBlankTargetRel(self::purifier()->purify($html));
        if (count(self::$cache) >= 32) {
            array_shift(self::$cache);
        }
        self::$cache[$key] = $clean;

        return $clean;
    }

    private static function purifier(): \HTMLPurifier
    {
        if (self::$purifier !== null) {
            return self::$purifier;
        }

        $config = HtmlPurifierConfig::create();
        $config->set('HTML.DefinitionID', 'longos-commerce-rich-text');
        $config->set('HTML.DefinitionRev', 1);
        $config->set('HTML.Allowed', implode(',', [
            'a[href|title|target|rel|class]',
            'blockquote[cite|class]',
            'br',
            'code[class]',
            'div[class]',
            'em[class]',
            'figcaption[class]',
            'figure[class]',
            'h1[class]',
            'h2[class]',
            'h3[class]',
            'h4[class]',
            'h5[class]',
            'h6[class]',
            'hr',
            'img[src|alt|title|width|height|class]',
            'li[class]',
            'ol[class]',
            'p[class]',
            'pre[class]',
            's[class]',
            'small[class]',
            'source[src|type]',
            'span[class]',
            'strong[class]',
            'sub',
            'sup',
            'table[class]',
            'tbody[class]',
            'td[colspan|rowspan|class]',
            'tfoot[class]',
            'th[colspan|rowspan|scope|class]',
            'thead[class]',
            'tr[class]',
            'u[class]',
            'ul[class]',
            'video[src|poster|controls|preload|width|height|loop|muted|autoplay|playsinline|class]',
        ]));
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Attr.AllowedRel', ['nofollow', 'noopener', 'noreferrer', 'sponsored', 'ugc']);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
        $config->set('CSS.AllowedProperties', []);

        $definition = $config->maybeGetRawHTMLDefinition();
        if ($definition !== null) {
            $definition->addElement('figure', 'Block', 'Flow', 'Common');
            $definition->addElement('figcaption', 'Block', 'Flow', 'Common');
            $definition->addElement('source', 'Inline', 'Empty', 'Common', [
                'src'  => 'URI',
                'type' => 'Text',
            ]);
            $definition->addElement('video', 'Block', 'Flow', 'Common', [
                'src'         => 'URI',
                'poster'      => 'URI',
                'controls'    => 'Bool',
                'preload'     => 'Enum#auto,metadata,none',
                'width'       => 'Length',
                'height'      => 'Length',
                'loop'        => 'Bool',
                'muted'       => 'Bool',
                'autoplay'    => 'Bool',
                'playsinline' => 'Bool',
            ]);
        }

        return self::$purifier = new \HTMLPurifier($config);
    }

    private static function addSafeBlankTargetRel(string $html): string
    {
        return preg_replace_callback('/<a\b[^>]*\btarget="_blank"[^>]*>/i', static function (array $match): string {
            $tag = $match[0];
            if (preg_match('/\brel="([^"]*)"/i', $tag, $relMatch)) {
                $tokens = preg_split('/\s+/', strtolower(trim($relMatch[1]))) ?: [];
                $tokens = array_values(array_diff(array_unique(array_filter($tokens)), ['noopener', 'noreferrer']));
                $tokens = [...$tokens, 'noopener', 'noreferrer'];

                return preg_replace('/\brel="[^"]*"/i', 'rel="' . implode(' ', $tokens) . '"', $tag, 1) ?? $tag;
            }

            return preg_replace('/>$/', ' rel="noopener noreferrer">', $tag, 1) ?? $tag;
        }, $html) ?? '';
    }
}
