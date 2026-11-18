<?php

namespace Plugin\LongosBotShield\Services;

use Illuminate\Http\Request;

/**
 * Turns a control-site document into a safe, static storefront document.
 *
 * Scripts remain sandboxed by the middleware response. Lazy media is promoted
 * to native HTML attributes so the complete visual page does not depend on
 * JavaScript, while public control-site navigation is rewritten onto the
 * visitor's current origin. Assets deliberately retain the control-site base.
 */
class ShieldedDocumentPresenter
{
    public function canonicalize(string $html, string $controlOrigin): string
    {
        $html = $this->removeMetaRefresh($html);
        $html = $this->hydrateLazyAssets($html);

        if (preg_match('/<base\b[^>]*\bdata-longos-bot-shield=(["\'])1\1/i', $html) === 1) {
            return $html;
        }

        $base = '<base data-longos-bot-shield="1" href="'
            . htmlspecialchars(rtrim($controlOrigin, '/') . '/', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '">';
        if (preg_match('/<head\b[^>]*>/i', $html) === 1) {
            return (string) preg_replace('/<head\b[^>]*>/i', '$0' . $base, $html, 1);
        }

        return $base . $html;
    }

    public function forVisitor(
        string $html,
        string $controlOrigin,
        string $controlDocumentUrl,
        Request $request,
    ): string {
        $visitorOrigin = $request->getSchemeAndHttpHost();

        return (string) preg_replace_callback(
            '/(<(?:a|area)\b[^>]*?\bhref\s*=\s*)(["\'])(.*?)\2/is',
            function (array $match) use ($controlOrigin, $controlDocumentUrl, $visitorOrigin): string {
                $rewritten = $this->visitorNavigationUrl(
                    html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    $controlOrigin,
                    $controlDocumentUrl,
                    $visitorOrigin,
                );

                if ($rewritten === null) {
                    return $match[0];
                }

                return $match[1] . $match[2]
                    . htmlspecialchars($rewritten, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')
                    . $match[2];
            },
            $html,
        );
    }

    private function removeMetaRefresh(string $html): string
    {
        return (string) preg_replace(
            '/<meta\b(?=[^>]*\bhttp-equiv\s*=\s*(["\'])?refresh\1?)[^>]*>/i',
            '',
            $html,
        );
    }

    private function hydrateLazyAssets(string $html): string
    {
        return (string) preg_replace_callback(
            '/<(?:img|source|video|iframe|link)\b[^>]*>/i',
            function (array $match): string {
                $tag = $match[0];
                foreach ([
                    'data-src'      => 'src',
                    'data-lazy-src' => 'src',
                    'data-srcset'   => 'srcset',
                    'data-poster'   => 'poster',
                    'data-href'     => 'href',
                ] as $source => $target) {
                    $value = $this->attribute($tag, $source);
                    if ($value !== null && trim($value) !== '') {
                        $tag = $this->setAttribute($tag, $target, $value);
                    }
                }

                $class = $this->attribute($tag, 'class');
                if ($class !== null) {
                    $class = trim((string) preg_replace('/(?:^|\s)lazy(?:load|loading)(?=\s|$)/i', ' lazyloaded', $class));
                    $tag   = $this->setAttribute($tag, 'class', $class);
                }

                return $tag;
            },
            $html,
        );
    }

    private function attribute(string $tag, string $name): ?string
    {
        $quoted = '/(?<![\w:-])' . preg_quote($name, '/') . '\s*=\s*(["\'])(.*?)\1/is';
        if (preg_match($quoted, $tag, $match) === 1) {
            return $match[2];
        }

        $unquoted = '/(?<![\w:-])' . preg_quote($name, '/') . '\s*=\s*([^\s>]+)/i';

        return preg_match($unquoted, $tag, $match) === 1 ? $match[1] : null;
    }

    private function setAttribute(string $tag, string $name, string $value): string
    {
        $escaped = htmlspecialchars(
            html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        );
        $pattern = '/(?<![\w:-])' . preg_quote($name, '/') . '\s*=\s*(?:(["\']).*?\1|[^\s>]+)/is';
        if (preg_match($pattern, $tag) === 1) {
            return (string) preg_replace($pattern, $name . '="' . $escaped . '"', $tag, 1);
        }

        return (string) preg_replace('/\s*\/?>$/', ' ' . $name . '="' . $escaped . '"$0', $tag, 1);
    }

    private function visitorNavigationUrl(
        string $href,
        string $controlOrigin,
        string $controlDocumentUrl,
        string $visitorOrigin,
    ): ?string {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }
        if (preg_match('/^(?:mailto|tel|sms|javascript|data):/i', $href) === 1) {
            return null;
        }

        $absolute = $this->absoluteUrl($href, $controlOrigin, $controlDocumentUrl);
        $parts    = parse_url($absolute);
        $control  = parse_url($controlOrigin);
        if (! is_array($parts) || ! is_array($control)
                               || ! isset($parts['scheme'], $parts['host'], $control['scheme'], $control['host'])
                               || strtolower((string) $parts['scheme']) !== strtolower((string) $control['scheme'])
                               || strtolower((string) $parts['host'])   !== strtolower((string) $control['host'])
                               || $this->effectivePort($parts)          !== $this->effectivePort($control)) {
            return null;
        }

        $path     = (string) ($parts['path'] ?? '/');
        $query    = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return rtrim($visitorOrigin, '/') . ($path === '' ? '/' : $path) . $query . $fragment;
    }

    private function absoluteUrl(string $href, string $controlOrigin, string $controlDocumentUrl): string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return (string) parse_url($controlOrigin, PHP_URL_SCHEME) . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return rtrim($controlOrigin, '/') . $href;
        }
        if (str_starts_with($href, '?')) {
            return preg_replace('/[?#].*$/', '', $controlDocumentUrl) . $href;
        }

        $documentPath = (string) parse_url($controlDocumentUrl, PHP_URL_PATH);
        $directory    = rtrim(str_replace('\\', '/', dirname($documentPath)), '/');
        $path         = $this->normalizePath(($directory === '' ? '' : $directory) . '/' . $href);

        return rtrim($controlOrigin, '/') . $path;
    }

    private function normalizePath(string $path): string
    {
        $suffix   = '';
        $position = strcspn($path, '?#');
        if ($position < strlen($path)) {
            $suffix = substr($path, $position);
            $path   = substr($path, 0, $position);
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments) . $suffix;
    }

    /** @param array<string, mixed> $parts */
    private function effectivePort(array $parts): int
    {
        return isset($parts['port']) ? (int) $parts['port'] : (strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80);
    }
}
