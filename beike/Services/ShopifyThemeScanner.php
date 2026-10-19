<?php

namespace Beike\Services;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Builds a deterministic inventory and dependency graph for a Shopify theme. */
class ShopifyThemeScanner
{
    public function __construct(private readonly ShopifyLiquidParser $liquid = new ShopifyLiquidParser) {}

    /** @return array{source: string, summary: array, files: array, component_units: array} */
    public function scan(?string $source = null): array
    {
        $source   = $this->sourceDirectory($source);
        $files    = [];
        $paths    = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $absolute = $file->getPathname();
            $relative = str_replace('\\', '/', substr($absolute, strlen(rtrim($source, DIRECTORY_SEPARATOR)) + 1));
            $contents = file_get_contents($absolute);
            $contents = is_string($contents) ? $contents : '';
            $record   = [
                'path'          => $absolute,
                'relative_path' => $relative,
                'filename'      => $file->getFilename(),
                'extension'     => strtolower((string) pathinfo($absolute, PATHINFO_EXTENSION)),
                'file_type'     => $this->classify($relative, $contents),
                'size'          => $file->getSize(),
                'hash'          => hash_file('sha256', $absolute),
                'dependencies'  => [],
                'referenced_by' => [],
                'warnings'      => [],
            ];
            if ($record['extension'] === 'liquid') {
                $analysis               = $this->liquid->inspect($contents);
                $record['dependencies'] = $analysis['dependencies'];
                $record['warnings']     = $analysis['warnings'];
                $record['liquid']       = [
                    'tags'    => $analysis['tags'],
                    'filters' => $analysis['filters'],
                    'objects' => $analysis['objects'],
                    'schema'  => $analysis['schema'],
                ];
            }
            $paths[$relative] = count($files);
            $files[]          = $record;
        }
        usort($files, static fn (array $left, array $right): int => $left['relative_path'] <=> $right['relative_path']);
        $paths = array_flip(array_column($files, 'relative_path'));
        foreach ($files as $index => $record) {
            foreach ($record['dependencies'] as $dependency) {
                if (isset($paths[$dependency])) {
                    $files[$paths[$dependency]]['referenced_by'][] = $record['relative_path'];
                } else {
                    $files[$index]['warnings'][] = "Missing dependency: {$dependency}";
                }
            }
            $files[$index]['referenced_by'] = array_values(array_unique($files[$index]['referenced_by']));
            $files[$index]['warnings']      = array_values(array_unique($files[$index]['warnings']));
        }

        return [
            'source'          => $source,
            'summary'         => $this->summary($files),
            'files'           => $files,
            'component_units' => $this->componentUnits($files),
        ];
    }

    private function sourceDirectory(?string $source): string
    {
        $candidates = array_filter([$source, base_path('shopify模板组件文件内容'), base_path('shopify模板组件')]);
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_dir($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        throw new \InvalidArgumentException('Shopify theme source directory was not found.');
    }

    private function classify(string $relative, string $contents): string
    {
        $directory = strtolower(strtok($relative, '/'));
        $extension = strtolower((string) pathinfo($relative, PATHINFO_EXTENSION));
        if ($directory === 'sections' && $extension === 'liquid') {
            return 'section';
        }
        if ($directory === 'snippets' && $extension === 'liquid') {
            return 'snippet';
        }
        if ($directory === 'templates') {
            return 'template';
        }
        if ($directory === 'layout') {
            return 'layout';
        }
        if ($directory === 'config') {
            return 'config';
        }
        if ($directory === 'locales') {
            return 'locale';
        }
        if ($extension === 'css') {
            return 'style';
        }
        if ($extension === 'js') {
            return 'script';
        }
        if ($extension === 'svg') {
            return 'svg';
        }
        if ($extension === 'json') {
            return 'json';
        }
        if ($directory === 'assets') {
            return 'asset';
        }
        if ($extension === 'liquid' && str_contains($contents, '{% schema %}')) {
            return 'section';
        }

        return 'unknown';
    }

    private function summary(array $files): array
    {
        $types      = [];
        $extensions = [];
        $warnings   = 0;
        foreach ($files as $file) {
            $types[$file['file_type']]                = ($types[$file['file_type']] ?? 0)                + 1;
            $extensions[$file['extension'] ?: 'none'] = ($extensions[$file['extension'] ?: 'none'] ?? 0) + 1;
            $warnings += count($file['warnings']);
        }
        ksort($types);
        ksort($extensions);

        return ['discovered' => count($files), 'by_type' => $types, 'by_extension' => $extensions, 'warnings' => $warnings];
    }

    /** Group each Shopify section with its direct transitive dependencies. */
    private function componentUnits(array $files): array
    {
        $byPath = [];
        foreach ($files as $file) {
            $byPath[$file['relative_path']] = $file;
        }
        $units = [];
        foreach ($files as $file) {
            if ($file['file_type'] !== 'section') {
                continue;
            }
            $queue    = $file['dependencies'];
            $included = [$file['relative_path'] => true];
            while ($queue) {
                $dependency = array_shift($queue);
                if (isset($included[$dependency]) || ! isset($byPath[$dependency])) {
                    continue;
                }
                $included[$dependency] = true;
                foreach ($byPath[$dependency]['dependencies'] as $nested) {
                    $queue[] = $nested;
                }
            }
            $schema  = $file['liquid']['schema'] ?? null;
            $units[] = [
                'id'           => pathinfo($file['filename'], PATHINFO_FILENAME),
                'name'         => is_array($schema) ? ($schema['name'] ?? pathinfo($file['filename'], PATHINFO_FILENAME)) : pathinfo($file['filename'], PATHINFO_FILENAME),
                'source_files' => array_keys($included),
                'settings'     => is_array($schema['settings'] ?? null) ? $schema['settings'] : [],
                'blocks'       => is_array($schema['blocks'] ?? null) ? $schema['blocks'] : [],
                'presets'      => is_array($schema['presets'] ?? null) ? $schema['presets'] : [],
                'status'       => 'discovered',
            ];
        }

        return $units;
    }
}
