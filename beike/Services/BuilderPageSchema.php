<?php

namespace Beike\Services;

/**
 * The persistent, editor-neutral contract for Store Builder data.
 *
 * The browser editor (and a future Puck adapter) are deliberately consumers
 * of this schema rather than owners of it. That keeps an editor upgrade from
 * becoming a database migration.
 */
class BuilderPageSchema
{
    public const VERSION = 5;

    /** Return bounded metadata which may safely travel with a page payload. */
    public static function metadata(mixed $payload): array
    {
        $payload  = is_array($payload) ? $payload : [];
        $pageType = is_string($payload['page_type'] ?? null) ? $payload['page_type'] : 'storefront';

        return [
            'schema_version' => self::VERSION,
            // The current editor is native JavaScript. Keeping this explicit
            // prevents a future Puck client from assuming its wire format is
            // the database format.
            'engine'    => in_array($payload['engine'] ?? null, ['native', 'puck'], true) ? $payload['engine'] : 'native',
            'page_type' => in_array($pageType, ['storefront', 'home', 'product', 'category', 'page'], true) ? $pageType : 'storefront',
        ];
    }

    /** Give every module a stable version and a bounded responsive contract. */
    public static function normalizeModule(array $module): array
    {
        $version                     = (int) ($module['component_version'] ?? 1);
        $module['component_version'] = min(99, max(1, $version));

        $content    = is_array($module['content'] ?? null) ? $module['content'] : [];
        $responsive = is_array($content['responsive'] ?? null) ? $content['responsive'] : [];
        $visibility = is_array($responsive['visibility'] ?? null) ? $responsive['visibility'] : [];
        // Preserve forward-compatible responsive settings owned by a theme
        // or plugin; the builder owns only the visibility sub-contract.
        $responsive['visibility'] = [
            'desktop' => self::boolean($visibility['desktop'] ?? true),
            'tablet'  => self::boolean($visibility['tablet'] ?? true),
            'mobile'  => self::boolean($visibility['mobile'] ?? true),
        ];
        $content['responsive'] = $responsive;
        $module['content']     = $content;

        return $module;
    }

    /** Keep the persisted root envelope independent from a UI library. */
    public static function envelope(array $payload, mixed $original = []): array
    {
        return array_merge(self::metadata($original), $payload);
    }

    private static function boolean(mixed $value): bool
    {
        return ! in_array($value, [false, 0, '0', 'false', 'off', 'no', ''], true);
    }
}
