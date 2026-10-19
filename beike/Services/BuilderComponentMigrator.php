<?php

namespace Beike\Services;

/** Component-local migrations, isolated from root page schema migrations. */
class BuilderComponentMigrator
{
    /** @var array<string, int> */
    private const CURRENT_VERSIONS = ['img_text_banner' => 2];

    /** Upgrade a component while retaining legacy renderer input. */
    public static function migrate(array $module): array
    {
        $code    = is_string($module['code'] ?? null) ? $module['code'] : '';
        $current = self::CURRENT_VERSIONS[$code] ?? 1;
        $version = max(1, (int) ($module['component_version'] ?? 1));
        $content = is_array($module['content'] ?? null) ? $module['content'] : [];
        if ($code === 'img_text_banner' && $version < 2) {
            // Keep `image` because the existing Blade section still consumes
            // it. New editor clients use the explicit desktop/mobile fields.
            $content['desktop_image'] = is_scalar($content['desktop_image'] ?? null) ? (string) $content['desktop_image'] : (is_scalar($content['image'] ?? null) ? (string) $content['image'] : '');
            $content['mobile_image']  = is_scalar($content['mobile_image'] ?? null) ? (string) $content['mobile_image'] : '';
            $version                  = 2;
        }
        $module['component_version'] = min(99, max($version, $current));
        $module['content']           = $content;

        return $module;
    }
}
