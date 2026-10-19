<?php

namespace Beike\Services;

/** Converts scanner component units into an editor-neutral Puck registry artifact. */
class ShopifyPuckRegistryGenerator
{
    /** @return array{format: string, version: int, components: array} */
    public function generate(array $manifest): array
    {
        $components = [];
        $hashes     = [];
        foreach (is_array($manifest['files'] ?? null) ? $manifest['files'] : [] as $file) {
            if (is_array($file) && is_string($file['relative_path'] ?? null) && is_string($file['hash'] ?? null)) {
                $hashes[$file['relative_path']] = $file['hash'];
            }
        }
        // Prefer the analyzed IR. Keep scanner-manifest support so existing
        // installations can upgrade without a flag-day artifact migration.
        $units = is_array($manifest['components'] ?? null) ? $manifest['components'] : ($manifest['component_units'] ?? []);
        foreach ($units as $unit) {
            if (! is_array($unit) || ! preg_match('/^[a-z0-9-]+$/', (string) ($unit['id'] ?? ''))) {
                continue;
            }
            $components[] = [
                'type'         => 'shopify-' . $unit['id'],
                'source'       => 'shopify-migrated',
                'version'      => 1,
                'label'        => is_string($unit['name'] ?? null) ? $unit['name'] : $unit['id'],
                'source_files' => array_values(array_filter($unit['source_files'] ?? [], 'is_string')),
                'source_hash'  => $this->sourceHash($unit['source_files'] ?? [], $hashes),
                'fields'       => $this->fields($unit['settings'] ?? []),
                'blocks'       => $this->blocks($unit['blocks'] ?? []),
                'status'       => is_string($unit['status'] ?? null) ? $unit['status'] : 'GENERATED',
                // Keep withholding evidence beside every generated schema.
                // The controller exposes only MIGRATED definitions to Puck,
                // but reports and CI can still explain every excluded unit.
                'manual_review_reasons' => array_values(array_filter($unit['manual_review_reasons'] ?? [], 'is_string')),
            ];
        }

        return ['format' => 'beikeshop-puck-registry', 'version' => 2, 'components' => $components];
    }

    /** Stable combined hash enables change detection without source execution. */
    private function sourceHash(mixed $sourceFiles, array $hashes): string
    {
        $entries = [];
        foreach (is_array($sourceFiles) ? $sourceFiles : [] as $path) {
            if (is_string($path)) {
                $entries[] = $path . ':' . ($hashes[$path] ?? 'missing');
            }
        }
        sort($entries);

        return hash('sha256', implode("\n", $entries));
    }

    private function fields(mixed $settings): array
    {
        $fields = [];
        foreach (is_array($settings) ? $settings : [] as $setting) {
            if (! is_array($setting) || ! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', (string) ($setting['id'] ?? ''))) {
                continue;
            }
            $type  = (string) ($setting['type'] ?? 'text');
            $field = [
                'id' => $setting['id'],
                // Keep the original Shopify type on the generated artifact.
                // The Puck configuration consumes this field directly while
                // `puck_type` remains useful for audits and future adapters.
                'type'         => $type,
                'shopify_type' => $type,
                'puck_type'    => match ($type) {
                    'textarea', 'richtext', 'html' => 'textarea',
                    'range', 'number' => 'number',
                    'select', 'radio' => 'select',
                    'checkbox' => 'radio',
                    'image_picker', 'image', 'video', 'video_url', 'url', 'product', 'product_list', 'collection', 'collection_list', 'page', 'blog', 'color', 'color_background', 'color_scheme' => 'custom',
                    default => 'text',
                },
                'default' => $setting['default'] ?? null,
                'options' => is_array($setting['options'] ?? null) ? $setting['options'] : [],
                // A value here means that no safe dedicated Builder field is
                // available. Do not label the real media/commerce/color
                // adapters as a native-editor fallback.
                'requires_native_picker' => in_array($type, ['link_list'], true),
            ];
            // Preserve the complete setting IR used by real Builder fields.
            // Omitting bounds and labels turns a Shopify range into an
            // unbounded number input, which is a silent loss of editability.
            foreach (['label', 'info', 'placeholder', 'unit'] as $key) {
                if (is_scalar($setting[$key] ?? null)) {
                    $field[$key] = mb_substr(trim((string) $setting[$key]), 0, 1000);
                }
            }
            foreach (['min', 'max', 'step', 'limit'] as $key) {
                if (is_int($setting[$key] ?? null) || is_float($setting[$key] ?? null)) {
                    $field[$key] = $setting[$key];
                }
            }
            $fields[] = $field;
        }

        return $fields;
    }

    private function blocks(mixed $blocks): array
    {
        $result = [];
        foreach (is_array($blocks) ? $blocks : [] as $block) {
            if (! is_array($block) || ! is_string($block['type'] ?? null)) {
                continue;
            }
            // `settings` deliberately mirrors Shopify's schema shape. This
            // lets the Puck array-field adapter render each block's controls
            // rather than exposing only a block-type selector.
            $result[] = ['type' => $block['type'], 'settings' => $this->fields($block['settings'] ?? [])];
        }

        return $result;
    }
}
