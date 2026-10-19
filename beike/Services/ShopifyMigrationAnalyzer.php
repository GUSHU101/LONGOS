<?php

namespace Beike\Services;

/**
 * Converts a scanner manifest into an editor-neutral Shopify component IR.
 *
 * This is deliberately analysis-only: it never executes Liquid or source
 * scripts, and it never labels a component as migrated merely because it was
 * discovered. The resulting artifact is safe to review, diff and feed into a
 * later code generator.
 */
class ShopifyMigrationAnalyzer
{
    /** Source sections intentionally outside this store's Builder scope. */
    private const EXCLUDED_UNITS = ['newsletter'];

    /**
     * These units describe business data that this installation does not own.
     * They must never be elevated to an addable storefront component until a
     * real LONGOS adapter exists.
     */
    private const REQUIRED_NATIVE_ADAPTERS = [
        'pickup-availability' => 'requires a LONGOS pickup-location and per-SKU inventory adapter',
    ];

    /**
     * These Shopify runtime requirements have a dedicated, dependency-free
     * LONGOS renderer (native carousel or local category pagination), so
     * they are not an external runtime boundary.
     */
    private const REPLACED_EDITOR_RUNTIME = ['slideshow', 'main-list-collections', 'cart-icon-bubble'];

    private const COMMERCE_OBJECTS = [
        'product', 'collection', 'collections', 'cart', 'customer', 'recommendations',
        'predictive_search', 'search', 'blog', 'article', 'page', 'routes', 'shop',
    ];

    /** @return array{format: string, version: int, source: string, summary: array, files: array, components: array, coverage: array} */
    public function analyze(array $scan): array
    {
        $files  = is_array($scan['files'] ?? null) ? $scan['files'] : [];
        $byPath = [];
        foreach ($files as $file) {
            if (is_array($file) && is_string($file['relative_path'] ?? null)) {
                $byPath[$file['relative_path']] = $file;
            }
        }
        $components = [];
        foreach (is_array($scan['component_units'] ?? null) ? $scan['component_units'] : [] as $unit) {
            if (! is_array($unit) || ! is_string($unit['id'] ?? null)) {
                continue;
            }
            if (in_array($unit['id'], self::EXCLUDED_UNITS, true)) {
                continue;
            }
            $components[] = $this->component($unit, $byPath);
        }
        usort($components, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return [
            'format'  => 'beikeshop-shopify-migration-manifest',
            'version' => 2,
            'source'  => (string) ($scan['source'] ?? ''),
            'summary' => array_merge(is_array($scan['summary'] ?? null) ? $scan['summary'] : [], [
                'component_units'       => count($components),
                'migration_status'      => $this->statusSummary($components),
                'manual_review_reasons' => $this->manualReviewSummary($components),
            ]),
            'files'      => $files,
            'components' => $components,
            'coverage'   => $this->coverage($components),
        ];
    }

    /** @return array<string, mixed> */
    private function component(array $unit, array $byPath): array
    {
        $sourceFiles = array_values(array_filter($unit['source_files'] ?? [], 'is_string'));
        $tags        = [];
        $filters     = [];
        $objects     = [];
        $assets      = [];
        $styles      = [];
        $scripts     = [];
        $warnings    = [];
        foreach ($sourceFiles as $path) {
            $file = $byPath[$path] ?? null;
            if (! is_array($file)) {
                $warnings[] = "Missing source file: {$path}";

                continue;
            }
            $liquid   = is_array($file['liquid'] ?? null) ? $file['liquid'] : [];
            $tags     = array_merge($tags, is_array($liquid['tags'] ?? null) ? $liquid['tags'] : []);
            $filters  = array_merge($filters, is_array($liquid['filters'] ?? null) ? $liquid['filters'] : []);
            $objects  = array_merge($objects, is_array($liquid['objects'] ?? null) ? $liquid['objects'] : []);
            $warnings = array_merge($warnings, is_array($file['warnings'] ?? null) ? $file['warnings'] : []);
            if (in_array($file['file_type'] ?? '', ['asset', 'svg'], true)) {
                $assets[] = $path;
            }
            if (($file['file_type'] ?? '') === 'style') {
                $styles[] = $path;
            }
            if (($file['file_type'] ?? '') === 'script') {
                $scripts[] = $path;
            }
        }
        $tags                     = $this->strings($tags);
        $filters                  = $this->strings($filters);
        $objects                  = $this->strings($objects);
        $settings                 = is_array($unit['settings'] ?? null) ? $unit['settings'] : [];
        $blocks                   = is_array($unit['blocks'] ?? null) ? $unit['blocks'] : [];
        $complexity               = $this->complexity($settings, $blocks, $tags, $objects, $scripts);
        $containsRestrictedLiquid = collect($settings)->contains(static fn (mixed $setting): bool => is_array($setting) && ($setting['type'] ?? null) === 'liquid');
        $requiresNativeAdapter    = self::REQUIRED_NATIVE_ADAPTERS[$unit['id']] ?? null;
        if ($requiresNativeAdapter) {
            $warnings[] = $requiresNativeAdapter;
        }
        $hasOnlyReplacedEditorRuntime = in_array((string) $unit['id'], self::REPLACED_EDITOR_RUNTIME, true);
        $status                       = (($complexity === 'CRITICAL' && ! $hasOnlyReplacedEditorRuntime) || $containsRestrictedLiquid || $requiresNativeAdapter) ? 'MANUAL_REVIEW' : 'ANALYZED';
        // A dedicated renderer is stronger evidence than a generated schema
        // bridge. Runtime-dependent/restricted units still remain review-only
        // even if a future renderer happens to share their name.
        if ($status === 'ANALYZED' && ShopifyRendererRegistry::isMigratedUnit((string) $unit['id'])) {
            $status = 'MIGRATED';
        }
        $manualReviewReasons = $status === 'MANUAL_REVIEW'
            ? $this->manualReviewReasons((string) $unit['id'], $tags, $objects, $scripts, $containsRestrictedLiquid, $requiresNativeAdapter)
            : [];

        return [
            'id'           => $unit['id'],
            'name'         => is_string($unit['name'] ?? null) ? $unit['name'] : $unit['id'],
            'type'         => 'section',
            'source_files' => $sourceFiles,
            'schema'       => [
                'settings' => $settings,
                'blocks'   => $blocks,
                'presets'  => is_array($unit['presets'] ?? null) ? $unit['presets'] : [],
            ],
            'settings'          => $settings,
            'blocks'            => $blocks,
            'presets'           => is_array($unit['presets'] ?? null) ? $unit['presets'] : [],
            'liquid'            => ['tags' => $tags, 'filters' => $filters, 'objects' => $objects],
            'assets'            => $this->strings($assets),
            'styles'            => $this->strings($styles),
            'scripts'           => $this->strings($scripts),
            'commerce_bindings' => array_values(array_intersect($objects, self::COMMERCE_OBJECTS)),
            'warnings'          => $this->strings($warnings),
            'restricted_liquid' => $containsRestrictedLiquid,
            'complexity'        => $complexity,
            // Review-only units need an actionable runtime boundary, not a
            // vague complexity label. This prevents a future importer from
            // treating a withheld Shopify unit as a harmless static block.
            'manual_review_reasons' => $manualReviewReasons,
            // Generated Puck definitions are an editable schema bridge, not
            // evidence of a visual React/storefront parity conversion.
            'status' => $status,
        ];
    }

    private function complexity(array $settings, array $blocks, array $tags, array $objects, array $scripts): string
    {
        if ($scripts || array_intersect($objects, ['cart', 'customer', 'predictive_search', 'recommendations']) || array_intersect($tags, ['form', 'paginate'])) {
            return 'CRITICAL';
        }
        if (count($settings) > 18 || count($blocks) > 4 || array_intersect($tags, ['case', 'for', 'capture', 'liquid']) || array_intersect($objects, self::COMMERCE_OBJECTS)) {
            return 'HIGH';
        }
        if (count($settings) > 6 || $blocks || array_intersect($tags, ['if', 'unless'])) {
            return 'MEDIUM';
        }

        return 'LOW';
    }

    private function statusSummary(array $components): array
    {
        $summary = ['DISCOVERED' => 0, 'ANALYZED' => 0, 'AUTO_MIGRATABLE' => 0, 'MIGRATED' => 0, 'VERIFIED' => 0, 'MANUAL_REVIEW' => 0, 'BLOCKED' => 0];
        foreach ($components as $component) {
            $status = $component['status'] ?? 'DISCOVERED';
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        return $summary;
    }

    /** @return array<int, string> */
    private function manualReviewReasons(string $unitId, array $tags, array $objects, array $scripts, bool $containsRestrictedLiquid, ?string $requiresNativeAdapter): array
    {
        $reasons = [];
        if ($containsRestrictedLiquid) {
            $reasons[] = 'contains a Shopify liquid setting; source Liquid is stored for review and is never executed';
        }
        if ($requiresNativeAdapter) {
            $reasons[] = $requiresNativeAdapter;
        }
        if ($scripts) {
            $reasons[] = 'requires a LONGOS replacement for Shopify browser runtime (' . implode(', ', $scripts) . ')';
        }

        $bindingReasons = [
            'cart'              => 'requires LONGOS cart/session data and transactional cart-interaction adapters',
            'customer'          => 'requires a verified LONGOS customer or contact-form endpoint',
            'product'           => 'requires page-bound LONGOS product, variant, price, and availability context',
            'collection'        => 'requires route-bound LONGOS category context with pagination/filter mapping',
            'recommendations'   => 'requires a LONGOS related-product recommendation adapter',
            'predictive_search' => 'requires a LONGOS predictive-search service adapter',
            'search'            => 'requires a LONGOS search and facet-query adapter',
            'blog'              => 'requires a LONGOS localized article-listing adapter',
            'article'           => 'requires a LONGOS localized article/detail adapter',
            'shop'              => 'requires an explicit mapping for Shopify global shop policies and navigation',
            'routes'            => 'requires an explicit mapping from Shopify route helpers to LONGOS storefront routes',
        ];
        foreach ($bindingReasons as $object => $reason) {
            if (in_array($object, $objects, true)) {
                $reasons[] = $reason;
            }
        }
        if (in_array('form', $tags, true)) {
            $reasons[] = 'requires a CSRF-protected LONGOS form action and server-side validation mapping';
        }
        if (in_array('paginate', $tags, true)) {
            $reasons[] = 'requires a LONGOS query, pagination, and URL-state adapter';
        }
        if ($reasons === []) {
            // This branch is intentionally explicit: manual review may only
            // represent a real conversion boundary, never an unexamined
            // scanner result. It is retained for future CRITICAL rules.
            $reasons[] = "requires a dedicated LONGOS runtime conversion for {$unitId}";
        }

        return $this->strings($reasons);
    }

    /** @return array<string, int> */
    private function manualReviewSummary(array $components): array
    {
        $summary = [];
        foreach ($components as $component) {
            if (($component['status'] ?? null) !== 'MANUAL_REVIEW') {
                continue;
            }
            foreach (is_array($component['manual_review_reasons'] ?? null) ? $component['manual_review_reasons'] : [] as $reason) {
                if (is_string($reason)) {
                    $summary[$reason] = ($summary[$reason] ?? 0) + 1;
                }
            }
        }
        ksort($summary);

        return $summary;
    }

    private function coverage(array $components): array
    {
        $settings     = 0;
        $blocks       = 0;
        $tags         = [];
        $filters      = [];
        $objects      = [];
        $settingTypes = [];
        $fieldMapping = [];
        foreach ($components as $component) {
            $settings += count($component['settings']);
            $blocks   += count($component['blocks']);
            $blockSettings = [];
            foreach ($component['blocks'] as $block) {
                if (is_array($block) && is_array($block['settings'] ?? null)) {
                    $blockSettings = array_merge($blockSettings, $block['settings']);
                }
            }
            foreach (array_merge($component['settings'], $blockSettings) as $setting) {
                if (! is_array($setting) || ! is_string($setting['type'] ?? null)) {
                    continue;
                }
                $settingTypes[$setting['type']] = ($settingTypes[$setting['type']] ?? 0) + 1;
                $mapping                        = $this->fieldMapping($setting['type']);
                $fieldMapping[$mapping]         = ($fieldMapping[$mapping] ?? 0) + 1;
            }
            $tags    = array_merge($tags, $component['liquid']['tags']);
            $filters = array_merge($filters, $component['liquid']['filters']);
            $objects = array_merge($objects, $component['liquid']['objects']);
        }

        return [
            'settings_discovered' => $settings,
            'blocks_discovered'   => $blocks,
            'liquid_tags'         => $this->strings($tags),
            'liquid_filters'      => $this->strings($filters),
            'shopify_objects'     => $this->strings($objects),
            'commerce_bindings'   => array_values(array_intersect($this->strings($objects), self::COMMERCE_OBJECTS)),
            'setting_types'       => $settingTypes,
            'field_mapping'       => $fieldMapping,
        ];
    }

    private function fieldMapping(string $type): string
    {
        return match ($type) {
            'header', 'paragraph' => 'inspector_presentation',
            'image_picker', 'image' => 'puck_media_field',
            'video'     => 'puck_video_media_field',
            'video_url' => 'puck_external_video_field',
            'url'       => 'puck_safe_url_field',
            'product', 'product_list', 'collection', 'collection_list', 'page', 'blog' => 'puck_commerce_field',
            'color', 'color_background' => 'puck_color_field',
            'liquid' => 'restricted_never_executed',
            'text', 'textarea', 'richtext', 'inline_richtext', 'html', 'number', 'range', 'checkbox', 'select', 'radio' => 'puck_builtin_field',
            default => 'native_recovery_required',
        };
    }

    /** @return array<int, string> */
    private function strings(array $values): array
    {
        return array_values(array_unique(array_filter($values, 'is_string')));
    }
}
