<?php

namespace Beike\Services;

/** Validates scan → IR → Puck registry invariants without executing source code. */
class ShopifyMigrationValidator
{
    /** @return array{valid: bool, checks: array, errors: array} */
    public function validate(array $scan, array $ir, array $registry): array
    {
        $errors    = [];
        $scanFiles = is_array($scan['files'] ?? null) ? $scan['files'] : [];
        $irFiles   = is_array($ir['files'] ?? null) ? $ir['files'] : [];
        if (count($scanFiles) === 0) {
            $errors[] = 'Scanner inventory is empty.';
        }
        if (count($scanFiles) !== count($irFiles)) {
            $errors[] = 'IR file inventory does not match scanner inventory.';
        }
        $componentIds = [];
        foreach (is_array($ir['components'] ?? null) ? $ir['components'] : [] as $component) {
            if (! is_array($component) || ! is_string($component['id'] ?? null)) {
                $errors[] = 'IR contains a component without a stable id.';

                continue;
            }
            if (isset($componentIds[$component['id']])) {
                $errors[] = 'IR contains duplicate component id: ' . $component['id'];
            }
            $componentIds[$component['id']] = true;
            if (($component['status'] ?? null) === 'MANUAL_REVIEW' && (! is_array($component['manual_review_reasons'] ?? null) || $component['manual_review_reasons'] === [])) {
                $errors[] = 'Manual-review component has no declared runtime boundary: ' . $component['id'];
            }
            foreach ($component['source_files'] ?? [] as $source) {
                if (! is_string($source) || ! $this->containsPath($scanFiles, $source)) {
                    $errors[] = 'IR component references a source outside the scanner inventory: ' . (string) $source;
                }
            }
        }
        $registryTypes = [];
        foreach (is_array($registry['components'] ?? null) ? $registry['components'] : [] as $component) {
            $type = is_array($component) && is_string($component['type'] ?? null) ? $component['type'] : '';
            if (! preg_match('/^shopify-[a-z0-9-]+$/', $type)) {
                $errors[] = 'Registry contains an invalid generated type: ' . $type;

                continue;
            }
            if (isset($registryTypes[$type])) {
                $errors[] = 'Registry contains duplicate component type: ' . $type;
            }
            $registryTypes[$type] = true;
        }
        foreach (array_keys($componentIds) as $id) {
            if (! isset($registryTypes['shopify-' . $id])) {
                $errors[] = 'IR component has no Puck registry definition: ' . $id;
            }
        }

        return [
            'valid'  => $errors === [],
            'checks' => [
                'scan_files'          => count($scanFiles),
                'ir_files'            => count($irFiles),
                'ir_components'       => count($componentIds),
                'registry_components' => count($registryTypes),
            ],
            'errors' => array_values(array_unique($errors)),
        ];
    }

    private function containsPath(array $files, string $path): bool
    {
        foreach ($files as $file) {
            if (is_array($file) && ($file['relative_path'] ?? null) === $path) {
                return true;
            }
        }

        return false;
    }
}
