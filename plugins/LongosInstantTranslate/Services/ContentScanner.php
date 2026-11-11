<?php

namespace Plugin\LongosInstantTranslate\Services;

use Beike\Services\BuilderStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes only code-owned UI strings and explicitly public merchant fields.
 * Customer, checkout, order, payment and account records are never queried.
 */
final class ContentScanner
{
    /** @var array<string, array{text:string,kind:string}> */
    private array $buffer = [];

    private const DATABASE_SOURCES = [
        'product'       => ['product_descriptions', 'products', 'product_id', ['name'], ['content']],
        'category'      => ['category_descriptions', 'categories', 'category_id', ['name'], ['content']],
        'page'          => ['page_descriptions', 'pages', 'page_id', ['title', 'summary'], ['content']],
        'page_category' => ['page_category_descriptions', 'page_categories', 'page_category_id', ['title', 'summary'], []],
    ];

    private const BUILDER_TEXT_KEYS = [
        'title', 'heading', 'sub_title', 'subtitle', 'description', 'text', 'caption',
        'label', 'button_text', 'button_label', 'button_label_1', 'button_label_2',
        'link_label', 'name', 'summary',
    ];

    private const BUILDER_BLOCKED_KEYS = [
        'url', 'href', 'link', 'image', 'icon', 'video', 'price', 'sku', 'email',
        'phone', 'address', 'token', 'secret', 'password', 'order', 'customer', 'payment',
    ];

    public function __construct(private readonly TranslationLibrary $library) {}

    /** @param list<string> $targetLocales @return array{found:int,queued:int,sources:array<string,int>,errors:list<string>} */
    public function scan(array $targetLocales): array
    {
        $this->buffer = [];
        $report       = ['found' => 0, 'queued' => 0, 'sources' => [], 'errors' => []];
        if (! LibrarySchema::ready()) {
            $report['errors'][] = 'schema_not_ready';

            return $report;
        }

        foreach (self::DATABASE_SOURCES as $kind => [$table, $parent, $foreignKey, $plainFields, $htmlFields]) {
            if (! Schema::hasTable($table) || ! Schema::hasTable($parent)) {
                continue;
            }
            $fields = array_values(array_filter($plainFields, static fn (string $field): bool => Schema::hasColumn($table, $field)));
            $rich   = array_values(array_filter($htmlFields, static fn (string $field): bool => Schema::hasColumn($table, $field)));
            if ($fields === [] && $rich === []) {
                continue;
            }

            $parents = DB::table($parent)->select('id');
            if (Schema::hasColumn($parent, 'active')) {
                $parents->where('active', true);
            }
            if (Schema::hasColumn($parent, 'deleted_at')) {
                $parents->whereNull('deleted_at');
            }

            $query = DB::table($table)->whereIn($foreignKey, $parents);
            if (Schema::hasColumn($table, 'locale')) {
                $query->where('locale', 'en');
            }
            $query->orderBy($foreignKey)->chunk(200, function ($rows) use ($kind, $fields, $rich, &$report): void {
                foreach ($rows as $row) {
                    foreach ($fields as $field) {
                        $this->record((string) ($row->{$field} ?? ''), $kind, $report);
                    }
                    foreach ($rich as $field) {
                        foreach ($this->visibleTextNodes((string) ($row->{$field} ?? '')) as $text) {
                            $this->record($text, $kind, $report);
                        }
                    }
                }
            });
        }

        $this->scanStaticLanguageFiles($targetLocales, $report);
        $this->scanPublishedBuilder($targetLocales, $report);
        $report['queued'] = $this->library->queueBatch(array_values($this->buffer), $targetLocales);

        return $report;
    }

    /** @param list<string> $targetLocales @param array<string,mixed> $report */
    private function scanStaticLanguageFiles(array $targetLocales, array &$report): void
    {
        $paths = [lang_path('en/shop'), lang_path('en/platform_pay.php')];
        foreach ($paths as $path) {
            $files = File::isDirectory($path) ? File::allFiles($path) : (File::isFile($path) ? [new \SplFileInfo($path)] : []);
            foreach ($files as $file) {
                if (strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                try {
                    $values = require $file->getPathname();
                } catch (\Throwable) {
                    $report['errors'][] = 'language_file_unreadable';

                    continue;
                }
                if (is_array($values)) {
                    $this->walkStaticValues($values, $targetLocales, $report);
                }
            }
        }
    }

    /** @param array<string|int,mixed> $values @param list<string> $targetLocales @param array<string,mixed> $report */
    private function walkStaticValues(array $values, array $targetLocales, array &$report): void
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                $this->walkStaticValues($value, $targetLocales, $report);
            } elseif (is_string($value)) {
                $this->record($value, 'interface', $report);
            }
        }
    }

    /** @param list<string> $targetLocales @param array<string,mixed> $report */
    private function scanPublishedBuilder(array $targetLocales, array &$report): void
    {
        if (! class_exists(BuilderStateService::class)) {
            return;
        }

        try {
            $state = BuilderStateService::published();
        } catch (\Throwable) {
            $report['errors'][] = 'builder_unavailable';

            return;
        }
        $this->scanPublishedChrome($state, $targetLocales, $report);
        foreach ((array) data_get($state, 'design.modules', []) as $module) {
            if (! is_array($module) || ! ($module['visible'] ?? true)) {
                continue;
            }
            $this->walkBuilder((array) ($module['content'] ?? []), $targetLocales, $report);
        }
    }

    /** @param array<string,mixed> $state @param list<string> $targetLocales @param array<string,mixed> $report */
    private function scanPublishedChrome(array $state, array $targetLocales, array &$report): void
    {
        $record = function (mixed $value) use (&$report): void {
            $text = $this->englishValue($value);
            if ($text !== '') {
                $this->record($text, 'chrome', $report);
            }
        };

        if ((bool) data_get($state, 'header.header_ads.active', false)) {
            foreach (array_slice((array) data_get($state, 'header.header_ads.items', []), 0, 12) as $item) {
                if (is_array($item)) {
                    $record($item['title'] ?? null);
                }
            }
        }
        foreach (array_slice((array) data_get($state, 'menu.menus', []), 0, 20) as $menu) {
            if (! is_array($menu)) {
                continue;
            }
            $record($menu['name'] ?? null);
            if ((bool) data_get($menu, 'badge.isShow', false)) {
                $record(data_get($menu, 'badge.name'));
            }
            foreach (array_slice((array) ($menu['childrenGroup'] ?? []), 0, 12) as $group) {
                if (! is_array($group)) {
                    continue;
                }
                $record($group['name'] ?? null);
                foreach (array_slice((array) ($group['children'] ?? []), 0, 20) as $child) {
                    if (is_array($child)) {
                        $record(data_get($child, 'link.text') ?: ($child['name'] ?? null));
                    }
                }
            }
        }

        $footer = (array) ($state['footer'] ?? []);
        foreach (array_slice((array) data_get($footer, 'services.items', []), 0, 12) as $item) {
            if (is_array($item) && ($item['show'] ?? true)) {
                $record($item['title'] ?? null);
                $record($item['sub_title'] ?? null);
            }
        }
        $record(data_get($footer, 'content.intro.text'));
        foreach (['link1', 'link2', 'link3'] as $groupKey) {
            $group = (array) data_get($footer, 'content.' . $groupKey, []);
            $record($group['title'] ?? null);
            foreach (array_slice((array) ($group['links'] ?? []), 0, 20) as $link) {
                if (is_array($link)) {
                    $record($link['text'] ?? null);
                }
            }
        }
        $record(data_get($footer, 'bottom.copyright'));
    }

    private function englishValue(mixed $value): string
    {
        if (is_string($value)) {
            return TranslationLibrary::normalize($value);
        }
        if (is_array($value) && is_scalar($value['en'] ?? null)) {
            return TranslationLibrary::normalize((string) $value['en']);
        }

        return '';
    }

    /** @param array<string|int,mixed> $values @param list<string> $targetLocales @param array<string,mixed> $report */
    private function walkBuilder(array $values, array $targetLocales, array &$report): void
    {
        foreach ($values as $key => $value) {
            $key = strtolower((string) $key);
            if ($this->blockedBuilderKey($key)) {
                continue;
            }
            if (is_array($value)) {
                $this->walkBuilder($value, $targetLocales, $report);
            } elseif (is_string($value) && in_array($key, self::BUILDER_TEXT_KEYS, true)) {
                if (str_contains($value, '<') && str_contains($value, '>')) {
                    foreach ($this->visibleTextNodes($value) as $text) {
                        $this->record($text, 'builder', $report);
                    }
                } else {
                    $this->record($value, 'builder', $report);
                }
            }
        }
    }

    private function blockedBuilderKey(string $key): bool
    {
        foreach (self::BUILDER_BLOCKED_KEYS as $blocked) {
            if (str_contains($key, $blocked)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function visibleTextNodes(string $html): array
    {
        if (trim($html) === '' || ! class_exists(\DOMDocument::class) || ! mb_check_encoding($html, 'UTF-8')) {
            return [];
        }
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new \DOMDocument('1.0', 'UTF-8');
            $loaded   = $document->loadHTML('<!doctype html><html><body><div id="lit-root">' . $html . '</div></body></html>', LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
            $root     = $loaded ? $document->getElementById('lit-root') : null;
            if (! $root) {
                return [];
            }
            $texts = [];
            $walk  = static function (\DOMNode $node) use (&$walk, &$texts): void {
                foreach ($node->childNodes as $child) {
                    if ($child instanceof \DOMElement && in_array(strtolower($child->tagName), ['script', 'style', 'template', 'noscript', 'svg', 'canvas'], true)) {
                        continue;
                    }
                    if ($child->nodeType === XML_TEXT_NODE) {
                        $text = TranslationLibrary::normalize((string) $child->nodeValue);
                        if ($text !== '') {
                            $texts[] = $text;
                        }
                    } elseif ($child->hasChildNodes()) {
                        $walk($child);
                    }
                }
            };
            $walk($root);

            return array_values(array_unique($texts));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @param array<string,mixed> $report */
    private function record(string $text, string $kind, array &$report): void
    {
        $normalized = TranslationLibrary::normalize($text);
        if ($normalized === '' || $this->sensitive($normalized)) {
            return;
        }
        $report['found']++;
        $report['sources'][$kind]                                  = (int) ($report['sources'][$kind] ?? 0) + 1;
        $this->buffer[TranslationLibrary::sourceHash($normalized)] = ['text' => $normalized, 'kind' => $kind];
    }

    private function sensitive(string $text): bool
    {
        return preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', $text)                                  === 1
            || preg_match('/(?:\+?\d[\s().-]*){7,}/', $text)                                                      === 1
            || preg_match('/\b(?:token|secret|password|authorization|bearer|order\s*(?:id|number|#))\b/i', $text) === 1;
    }
}
