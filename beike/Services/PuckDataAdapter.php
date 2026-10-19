<?php

namespace Beike\Services;

/**
 * Translates the durable Builder schema at the Puck boundary.
 *
 * This class has no Puck package dependency by design: package upgrades stay
 * in the client bundle while the persisted LONGOS compatibility contract stays stable.
 */
class PuckDataAdapter
{
    /** @return array{content: array, root: array} */
    public static function toPuck(array $page): array
    {
        $page    = DesignService::normalizeDesignSetting($page);
        $content = [];
        foreach ($page['modules'] as $module) {
            $props             = is_array($module['content'] ?? null) ? $module['content'] : [];
            $props['_builder'] = [
                'module_id'         => $module['module_id'],
                'code'              => $module['code'],
                'name'              => $module['name']              ?? $module['code'],
                'view_path'         => $module['view_path']         ?? '',
                'visible'           => $module['visible']           ?? true,
                'component_version' => $module['component_version'] ?? 1,
            ];
            $props['id'] = $module['module_id'];
            $content[]   = ['type' => $module['code'], 'props' => $props];
        }

        return [
            'content' => $content,
            'root'    => [
                'props' => [
                    'schemaVersion' => $page['schema_version'],
                    'pageType'      => $page['page_type'],
                    'globalStyle'   => $page['global_style'],
                    'appEmbeds'     => $page['app_embeds'],
                    'pageMetadata'  => $page['page_metadata'] ?? [],
                ],
            ],
        ];
    }

    /** Convert Puck-shaped data to the Builder payload before server validation. */
    public static function fromPuck(array $data): array
    {
        $modules = [];
        foreach ($data['content'] ?? [] as $item) {
            if (! is_array($item) || ! is_string($item['type'] ?? null) || ! is_array($item['props'] ?? null)) {
                continue;
            }
            $props = $item['props'];
            $meta  = is_array($props['_builder'] ?? null) ? $props['_builder'] : [];
            // Native sections expose their uncommon legacy settings through
            // one guarded advanced JSON object in the Puck inspector. Expand
            // it only at the durable schema boundary; malformed or oversized
            // content is ignored, and reserved editor metadata cannot be
            // overridden from the text field.
            $advancedJson = $props['_advanced_json'] ?? '';
            unset($props['id'], $props['_builder'], $props['_advanced_json']);
            if (is_string($advancedJson) && strlen($advancedJson) <= 60000) {
                try {
                    $advanced = json_decode($advancedJson, true, 32, JSON_THROW_ON_ERROR);
                    if (is_array($advanced) && ! array_is_list($advanced)) {
                        // Advanced JSON fills legacy or uncommon settings, but
                        // an explicit inspector control is the latest source
                        // of truth. Letting a stale JSON copy win here made a
                        // merchant's visible edit appear to save and then
                        // silently revert after the server round-trip.
                        $props = array_replace($advanced, $props);
                    }
                } catch (\JsonException) {
                    // The browser field reports parse failures inline. Keep
                    // the previously valid quick-field configuration intact.
                }
            }
            if ($item['type'] === 'img_text_banner' && is_scalar($props['desktop_image'] ?? null) && (string) $props['desktop_image'] !== '') {
                // Legacy Blade uses `image`; retain a synchronized fallback
                // until its responsive picture renderer is introduced.
                $props['image'] = (string) $props['desktop_image'];
            }
            $modules[] = [
                'code' => $item['type'],
                // Puck assigns a new props.id when duplicating. Metadata can
                // still contain the source id until the next server round-trip.
                'module_id'         => $item['props']['id']       ?? ($meta['module_id'] ?? ''),
                'name'              => $meta['name']              ?? $item['type'],
                'view_path'         => $meta['view_path']         ?? '',
                'visible'           => $meta['visible']           ?? true,
                'component_version' => $meta['component_version'] ?? 1,
                'content'           => $props,
            ];
        }
        $root = is_array($data['root']['props'] ?? null) ? $data['root']['props'] : [];

        return DesignService::handleRequestModules([
            'schema_version' => $root['schemaVersion'] ?? BuilderPageSchema::VERSION,
            'engine'         => 'puck',
            'page_type'      => $root['pageType'] ?? 'storefront',
            'modules'        => $modules,
            'global_style'   => $root['globalStyle']  ?? [],
            'app_embeds'     => $root['appEmbeds']    ?? [],
            'page_metadata'  => $root['pageMetadata'] ?? [],
        ]);
    }
}
