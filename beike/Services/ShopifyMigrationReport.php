<?php

namespace Beike\Services;

/** Renders a concise, reviewable Markdown report from the migration IR. */
class ShopifyMigrationReport
{
    public function render(array $manifest): string
    {
        $summary  = is_array($manifest['summary'] ?? null) ? $manifest['summary'] : [];
        $status   = is_array($summary['migration_status'] ?? null) ? $summary['migration_status'] : [];
        $coverage = is_array($manifest['coverage'] ?? null) ? $manifest['coverage'] : [];
        $lines    = [
            '# Shopify template migration report', '',
            '## Inventory', '',
            '- Source: `' . ($manifest['source'] ?? '') . '`',
            '- Files discovered: ' . (int) ($summary['discovered'] ?? 0),
            '- Component units: ' . (int) ($summary['component_units'] ?? 0),
            '- Scanner warnings: ' . (int) ($summary['warnings'] ?? 0), '',
            '### Files by type', '',
        ];
        foreach (is_array($summary['by_type'] ?? null) ? $summary['by_type'] : [] as $type => $count) {
            $lines[] = '- ' . $type . ': ' . $count;
        }
        $lines = array_merge($lines, ['', '## Migration status', '']);
        foreach ($status as $name => $count) {
            $lines[] = '- ' . $name . ': ' . $count;
        }
        $reviewReasons = is_array($summary['manual_review_reasons'] ?? null) ? $summary['manual_review_reasons'] : [];
        if ($reviewReasons) {
            $lines = array_merge($lines, ['', '### Withheld runtime boundaries', '']);
            foreach ($reviewReasons as $reason => $count) {
                $lines[] = '- ' . (int) $count . ' × ' . $this->cell($reason);
            }
        }
        $lines = array_merge($lines, ['', '## Coverage', '',
            '- Schema settings discovered: ' . (int) ($coverage['settings_discovered'] ?? 0),
            '- Blocks discovered: ' . (int) ($coverage['blocks_discovered'] ?? 0),
            '- Liquid tags: ' . $this->list($coverage['liquid_tags'] ?? []),
            '- Liquid filters: ' . $this->list($coverage['liquid_filters'] ?? []),
            '- Shopify objects: ' . $this->list($coverage['shopify_objects'] ?? []),
            '- Commerce bindings: ' . $this->list($coverage['commerce_bindings'] ?? []), '',
            '### Field mapping', '',
        ]);
        foreach (is_array($coverage['field_mapping'] ?? null) ? $coverage['field_mapping'] : [] as $mapping => $count) {
            $lines[] = '- ' . $mapping . ': ' . $count;
        }
        $lines = array_merge($lines, ['', '### Shopify setting types', '']);
        foreach (is_array($coverage['setting_types'] ?? null) ? $coverage['setting_types'] : [] as $type => $count) {
            $lines[] = '- ' . $type . ': ' . $count;
        }
        $lines = array_merge($lines, ['',
            '## Component review queue', '',
            '| Component | Complexity | Status | Runtime boundary / review reason | Settings | Blocks |',
            '| --- | --- | --- | --- | ---: | ---: |',
        ]);
        foreach (is_array($manifest['components'] ?? null) ? $manifest['components'] : [] as $component) {
            $reasons = is_array($component['manual_review_reasons'] ?? null) ? $component['manual_review_reasons'] : [];
            $reason  = $reasons ? implode('<br>', array_map([$this, 'cell'], $reasons)) : '—';
            $lines[] = '| ' . $this->cell($component['id'] ?? '') . ' | ' . $this->cell($component['complexity'] ?? '') . ' | ' . $this->cell($component['status'] ?? '') . ' | ' . $reason . ' | ' . count($component['settings'] ?? []) . ' | ' . count($component['blocks'] ?? []) . ' |';
        }
        $lines[] = '';
        $lines[] = 'Status is evidence-based: `ANALYZED` means the scanner/parser/IR completed; it does not claim storefront visual parity. `MANUAL_REVIEW` identifies a listed runtime-dependent or security boundary that must not be auto-executed.';

        return implode("\n", $lines) . "\n";
    }

    private function list(mixed $values): string
    {
        return is_array($values) && $values ? '`' . implode('`, `', array_map([$this, 'cell'], $values)) . '`' : 'none';
    }

    private function cell(mixed $value): string
    {
        return str_replace('|', '\\|', trim((string) $value));
    }
}
