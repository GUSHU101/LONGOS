<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Beike\Services\BuilderStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registry-backed scanner. Only an explicit public source/field allowlist is
 * considered; models containing customer, checkout or review data never enter
 * this service or a provider request.
 */
final class ContentScanner
{
    /**
     * The storefront header/footer/menu are one published visual bundle, not
     * database records with their own public IDs.  Give their strictly
     * allowlisted merchant copy one stable dictionary identity.  This is
     * deliberately not a catch-all for settings: links, phone numbers,
     * addresses, email addresses, payment methods and any account/customer
     * data are never candidates.
     */
    public const CHROME_KIND = 'chrome';

    public const CHROME_ENTITY_ID = 1;

    private const SOURCES = [
        // Public rich text is scanned node-by-node, never as HTML. The
        // storefront contract only writes textContent back to the same node
        // index; provider markup can therefore never enter the browser.
        'product'       => ['product_descriptions', 'products', 'product_id', ['name'], ['content']],
        'category'      => ['category_descriptions', 'categories', 'category_id', ['name'], ['content']],
        'page'          => ['page_descriptions', 'pages', 'page_id', ['title', 'summary'], ['content']],
        'page_category' => ['page_category_descriptions', 'page_categories', 'page_category_id', ['title', 'summary']],
    ];

    /** @param list<string>|null $scope
     * @return array<string, mixed>
     */
    public function scan(?array $scope = null, ?int $actorId = null): array
    {
        // The builder is a supported public source just like catalog records.
        // Keep it in the default invocation too: console callers and tests do
        // not go through the admin controller, which otherwise supplies this
        // scope explicitly.
        // The separately labelled commerce UI scope stays opt-in from the
        // admin workspace: it has a deliberately finite static-label
        // catalog, while the scopes below contain only published merchant
        // content and are safe to index by default.
        // A "global" scan must include the finite public interface catalog
        // as well as merchant copy. Leaving it opt-in made it too easy to
        // publish translated product pages while cart, checkout, account and
        // payment labels silently stayed English. The catalog is checked-in,
        // public and value-free, so including it by default does not widen
        // the privacy boundary.
        $scope ??= [...array_keys(self::SOURCES), 'builder', self::CHROME_KIND, CommerceUiCatalog::KIND];
        $report = [
            'supported_sources'      => [], 'found' => 0, 'changed' => 0, 'translated' => 0,
            'approved'               => 0, 'stale' => 0, 'excluded_by_rule' => 0,
            'unsupported_components' => [], 'errors' => [],
        ];
        foreach (self::SOURCES as $kind => $source) {
            [$table, $parent, $key, $fields] = $source;
            $htmlFields = $source[4] ?? [];
            if (! in_array($kind, $scope, true)) {
                continue;
            }
            if (! Schema::hasTable($table) || ! Schema::hasTable($parent)) {
                $report['errors'][] = $kind . '_tables_missing';

                continue;
            }
            $fields                        = array_values(array_filter($fields, static fn (string $field): bool => Schema::hasColumn($table, $field)));
            $htmlFields                    = array_values(array_filter($htmlFields, static fn (string $field): bool => Schema::hasColumn($table, $field)));
            $report['supported_sources'][] = $kind;
            $parents                       = DB::table($parent)->select('id');
            if (Schema::hasColumn($parent, 'active')) {
                $parents->where('active', true);
            }
            if (Schema::hasColumn($parent, 'deleted_at')) {
                $parents->whereNull('deleted_at');
            }
            DB::table($table)->where('locale', 'en')
                ->whereIn($key, $parents)
                ->orderBy($key)->chunk(200, function ($rows) use ($kind, $key, $fields, $htmlFields, &$report): void {
                    // Variant labels are public merchant catalog content.  They
                    // are not a customer choice or an order value, so we can
                    // pretranslate the English labels and send the resulting
                    // published strings back with the product payload. Images,
                    // SKU values, stock and prices are intentionally omitted.
                    $productVariables = [];
                    if ($kind === 'product' && Schema::hasColumn('products', 'variables')) {
                        $ids = collect($rows)->pluck($key)->map(static fn (mixed $id): int => (int) $id)->filter()->all();
                        if ($ids !== []) {
                            $productVariables = DB::table('products')->whereIn('id', $ids)->pluck('variables', 'id')->all();
                        }
                    }
                    foreach ($rows as $row) {
                        foreach ($fields as $field) {
                            $this->record($kind, (int) $row->{$key}, $field, (string) ($row->{$field} ?? ''), 'en', 'plain', null, $report);
                        }
                        foreach ($htmlFields as $field) {
                            $this->recordHtmlTextFields($kind, (int) $row->{$key}, $field, (string) ($row->{$field} ?? ''), null, $report);
                        }
                        if ($kind === 'product') {
                            $this->recordProductVariableFields((int) $row->{$key}, $productVariables[(int) $row->{$key}] ?? null, $report);
                        }
                    }
                });
        }
        if (in_array('builder', $scope, true)) {
            $report['supported_sources'][] = 'builder';
            $this->scanBuilder($report);
        }
        if (in_array(self::CHROME_KIND, $scope, true)) {
            $report['supported_sources'][] = self::CHROME_KIND;
            $this->scanChrome($report);
        }
        if (in_array(CommerceUiCatalog::KIND, $scope, true)) {
            // Only the finite static-label catalog is indexed. This is the
            // sole route through which public storefront UI text can reach a
            // provider; customer-entered or transaction-derived values are
            // deliberately absent from this scanner.
            $report['supported_sources'][] = CommerceUiCatalog::KIND;
            $seen = [];
            foreach (CommerceUiCatalog::entries() as $field => $source) {
                $this->record(CommerceUiCatalog::KIND, CommerceUiCatalog::ENTITY_ID, $field, $source, 'en', 'plain', 'catalog:v2', $report);
                $seen[] = hash('sha256', implode(':', ['lgt-content-v2', CommerceUiCatalog::KIND, CommerceUiCatalog::ENTITY_ID, $field, 'en']));
            }
            // A deployment can remove or rename an obsolete default-theme
            // label. Keep old rows out of future jobs and publications rather
            // than leaving an unbounded historical interface catalog behind.
            DB::table('lgt_entries')
                ->where('kind', CommerceUiCatalog::KIND)
                ->where('entity_id', CommerceUiCatalog::ENTITY_ID)
                ->whereNotIn('content_key', $seen ?: ['__never__'])
                ->update(['is_public' => false, 'is_tombstoned' => true, 'updated_at' => now()]);
        }
        $report['translated'] = DB::table('lgt_translations')->whereIn('status', [TranslationLibrary::TRANSLATED, TranslationLibrary::NEEDS_REVIEW, TranslationLibrary::APPROVED])->count();
        $report['approved']   = DB::table('lgt_translations')->where('status', TranslationLibrary::APPROVED)->count();
        $report['stale']      = DB::table('lgt_translations')->where('status', TranslationLibrary::STALE)->count();
        DB::table('lgt_coverage_snapshots')->insert([
            'scope'      => 'scan', 'report' => json_encode($report, JSON_UNESCAPED_UNICODE), 'created_by' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $report;
    }

    /** @param array<string, mixed> $report */
    private function record(string $kind, int $entityId, string $field, string $source, string $locale, string $format, ?string $revision, array &$report): void
    {
        $normalized = self::normalizePlainText($source, $format);
        if ($normalized === '') {
            return;
        }
        if ($this->excluded($kind, $entityId, $field)) {
            $report['excluded_by_rule']++;

            return;
        }
        $contentKey = hash('sha256', implode(':', ['lgt-content-v2', $kind, $entityId, $field, $locale]));
        $hash       = self::sourceHash($source, $format);
        DB::transaction(function () use ($kind, $entityId, $field, $locale, $format, $revision, $normalized, $contentKey, $hash, &$report): void {
            DB::table('lgt_entries')->insertOrIgnore([
                'content_key'   => $contentKey, 'kind' => $kind, 'entity_id' => $entityId, 'field' => $field,
                'source_locale' => $locale, 'source_format' => $format, 'source_revision' => $revision,
                'source_text'   => $normalized, 'source_hash' => $hash, 'is_public' => true, 'is_tombstoned' => false,
                'metadata'      => json_encode(['contract' => 'lgt-source-v2']), 'last_scanned_at' => now(),
                'created_at'    => now(), 'updated_at' => now(),
            ]);
            $entry = DB::table('lgt_entries')->where('content_key', $contentKey)->lockForUpdate()->first();
            if ($entry === null) {
                throw new \RuntimeException('ENTRY_INSERT_FAILED');
            }
            $changed = ! hash_equals((string) $entry->source_hash, $hash);
            if ($changed) {
                DB::table('lgt_entries')->where('id', $entry->id)->update([
                    'source_text' => $normalized, 'source_hash' => $hash, 'source_format' => $format, 'source_revision' => $revision,
                    'is_public'   => true, 'is_tombstoned' => false, 'last_scanned_at' => now(), 'updated_at' => now(),
                ]);
                // A reviewer lock protects a translation of this exact
                // English source revision. Once the merchant changes that
                // source, retaining the old lock would leave the row stale
                // forever: TranslationLibrary correctly refuses to overwrite
                // a locked row, but the old wording is no longer safe to
                // publish. Clear that revision-scoped lock so the new source
                // can enter the normal provider/quality pipeline. The old
                // text remains in lgt_translation_versions for audit and
                // rollback; no source-language field is changed here.
                DB::table('lgt_translations')->where('entry_id', $entry->id)->update([
                    'status'    => TranslationLibrary::STALE,
                    'locked'    => false,
                    'locked_by' => null,
                    'locked_at' => null,
                    'updated_at' => now(),
                ]);
                $report['changed']++;
            } else {
                DB::table('lgt_entries')->where('id', $entry->id)->update(['is_public' => true, 'is_tombstoned' => false, 'last_scanned_at' => now(), 'updated_at' => now()]);
            }
            $report['found']++;
        });
    }

    /**
     * Register only visible text nodes from an already public merchant HTML
     * body. Node paths are deterministic and are verified again before a
     * dictionary response is emitted, so stale structure fails closed.
     *
     * @param array<string, mixed> $report
     */
    /** @return array<string, string> */
    private function recordHtmlTextFields(string $kind, int $entityId, string $field, string $html, ?string $revision, array &$report): array
    {
        $fields = self::htmlTextFields($field, $html);
        $seen   = [];
        foreach ($fields as $path => $text) {
            $this->record($kind, $entityId, $path, $text, 'en', 'plain', $revision, $report);
            $seen[] = $path;
        }

        // A merchant edit can remove a paragraph. Keep its old translation
        // out of future jobs/publications instead of paying to translate text
        // that no longer exists in the storefront.
        $query = DB::table('lgt_entries')
            ->where('kind', $kind)
            ->where('entity_id', $entityId)
            ->where('field', 'like', $field . '.node.%');
        if ($seen !== []) {
            $query->whereNotIn('field', $seen);
        }
        $query->update(['is_public' => false, 'is_tombstoned' => true, 'updated_at' => now()]);

        return $fields;
    }

    /**
     * Return a field map for only text nodes that are safe to render as text.
     * The same pre-order traversal is implemented in widget.js. Do not add
     * attribute values, URLs, image alt text or script/style/template content.
     *
     * @return array<string, string>
     */
    public static function htmlTextFields(string $field, string $html): array
    {
        if (! class_exists(\DOMDocument::class) || ! mb_check_encoding($html, 'UTF-8') || trim($html) === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument('1.0', 'UTF-8');
            $loaded = $document->loadHTML(
                '<!doctype html><html><head><meta charset="utf-8"></head><body><div id="lgt-public-root">' . $html . '</div></body></html>',
                LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED,
            );
            $root = $loaded ? $document->getElementById('lgt-public-root') : null;
            if (! $root instanceof \DOMElement) {
                return [];
            }

            $fields = [];
            $index  = 0;
            $walk = static function (\DOMNode $node) use (&$walk, &$fields, &$index, $field): void {
                foreach ($node->childNodes as $child) {
                    if ($child instanceof \DOMElement && in_array(strtolower($child->tagName), ['script', 'style', 'noscript', 'template', 'svg', 'canvas'], true)) {
                        continue;
                    }
                    if ($child->nodeType === XML_TEXT_NODE) {
                        $text = self::normalizePlainText((string) $child->nodeValue);
                        if ($text !== '') {
                            $fields[$field . '.node.' . $index] = $text;
                            $index++;
                        }
                        continue;
                    }
                    if ($child->hasChildNodes()) {
                        $walk($child);
                    }
                }
            };
            $walk($root);

            return $fields;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @param array<string, mixed> $report */
    private function scanBuilder(array &$report): void
    {
        try {
            $state    = BuilderStateService::published();
            $seen     = [];
            $revision = 'published:' . (int) ($state['version'] ?? 0);
            foreach ((array) data_get($state, 'design.modules', []) as $module) {
                if (! is_array($module) || ! ($module['visible'] ?? true)) {
                    continue;
                }
                $moduleId = trim((string) ($module['module_id'] ?? ''));
                $rawCode  = trim((string) ($module['code'] ?? ''));
                // The public Builder state preserves historical Shopify
                // module names with hyphens (for example
                // `shopify-slideshow`), while Blade view filenames and this
                // explicit contract use underscores. Normalise only this
                // finite code token; do not normalise or traverse arbitrary
                // merchant payload keys.
                $code     = self::canonicalBuilderCode($rawCode);
                if ($moduleId === '' || ! in_array($code, $this->supportedBuilderCodes(), true)) {
                    if ($rawCode !== '') {
                        $report['unsupported_components'][] = $rawCode;
                    }

                    continue;
                }
                $entityId = $this->stableBuilderId($moduleId);
                $content = (array) ($module['content'] ?? []);
                foreach ($this->builderFields($code, $content) as $field => $value) {
                    $path = 'module.' . $moduleId . '.' . $field;
                    $this->record('builder', $entityId, $path, $value, 'en', 'plain', $revision, $report);
                    $seen[] = hash('sha256', implode(':', ['lgt-content-v2', 'builder', $entityId, $path, 'en']));
                }
                foreach ($this->builderHtmlFields($code, $content) as $field => $html) {
                    $path   = 'module.' . $moduleId . '.' . $field;
                    $fields = $this->recordHtmlTextFields('builder', $entityId, $path, $html, $revision, $report);
                    foreach (array_keys($fields) as $nodePath) {
                        $seen[] = hash('sha256', implode(':', ['lgt-content-v2', 'builder', $entityId, $nodePath, 'en']));
                    }
                }
            }
            DB::table('lgt_entries')->where('kind', 'builder')->whereNotIn('content_key', $seen ?: ['__never__'])->update(['is_public' => false, 'is_tombstoned' => true, 'updated_at' => now()]);
        } catch (\Throwable $exception) {
            $report['errors'][] = 'builder_scan_failed';
        }
        $report['unsupported_components'] = array_values(array_unique($report['unsupported_components']));
    }

    /**
     * Index merchant-authored header, navigation and footer labels from the
     * immutable published visual state.  Every path below is also rendered
     * with a data-lgt-field marker in the default theme; no broad settings
     * traversal is used, so non-display or sensitive configuration can never
     * reach a provider.
     *
     * @param array<string, mixed> $report
     */
    private function scanChrome(array &$report): void
    {
        try {
            $state    = BuilderStateService::published();
            $revision = 'published:' . (int) ($state['version'] ?? 0);
            $seen     = [];
            foreach (self::chromeTextFields($state) as $field => $value) {
                $this->record(self::CHROME_KIND, self::CHROME_ENTITY_ID, $field, $value, 'en', 'plain', $revision, $report);
                $seen[] = hash('sha256', implode(':', ['lgt-content-v2', self::CHROME_KIND, self::CHROME_ENTITY_ID, $field, 'en']));
            }
            DB::table('lgt_entries')->where('kind', self::CHROME_KIND)
                ->where('entity_id', self::CHROME_ENTITY_ID)
                ->whereNotIn('content_key', $seen ?: ['__never__'])
                ->update(['is_public' => false, 'is_tombstoned' => true, 'updated_at' => now()]);
        } catch (\Throwable) {
            $report['errors'][] = 'chrome_scan_failed';
        }
    }

    /**
     * Return the only merchant-authored chrome fields that may be translated.
     * Values are always read from the locked English source locale; a missing
     * English source is intentionally skipped rather than using a visitor or
     * tenant-local fallback as source text.
     *
     * @param array<string, mixed> $state
     * @return array<string, string>
     */
    public static function chromeTextFields(array $state): array
    {
        $fields = [];
        $source = static fn (mixed $value): string => self::normalizePlainText(self::sourceLocaleValue($value));
        $add = static function (string $field, mixed $value) use (&$fields, $source): void {
            $text = $source($value);
            if ($text !== '') {
                $fields[$field] = $text;
            }
        };

        $headerAds = (array) data_get($state, 'header.header_ads', []);
        if (($headerAds['active'] ?? false) === true) {
            foreach (array_slice((array) ($headerAds['items'] ?? []), 0, 12, true) as $index => $item) {
                if (is_array($item)) {
                    $add('header.header_ads.items.' . $index . '.title', $item['title'] ?? null);
                }
            }
        }

        foreach (array_slice((array) data_get($state, 'menu.menus', []), 0, 20, true) as $menuIndex => $menu) {
            if (! is_array($menu)) {
                continue;
            }
            $base = 'menu.menus.' . $menuIndex;
            $add($base . '.name', $menu['name'] ?? null);
            if ((bool) data_get($menu, 'badge.isShow', false)) {
                $add($base . '.badge.name', data_get($menu, 'badge.name'));
            }
            if (! ($menu['isChildren'] ?? false)) {
                continue;
            }
            foreach (array_slice((array) ($menu['childrenGroup'] ?? []), 0, 12, true) as $groupIndex => $group) {
                if (! is_array($group)) {
                    continue;
                }
                $groupBase = $base . '.childrenGroup.' . $groupIndex;
                $add($groupBase . '.name', $group['name'] ?? null);
                if (($group['type'] ?? 'link') === 'image') {
                    continue;
                }
                foreach (array_slice((array) ($group['children'] ?? []), 0, 20, true) as $childIndex => $child) {
                    if (! is_array($child)) {
                        continue;
                    }
                    // MenuRepo renders link.text and falls back to name for
                    // legacy menu records. Mirror that public behavior here.
                    $text = $source(data_get($child, 'link.text'));
                    if ($text === '') {
                        $text = $source($child['name'] ?? null);
                    }
                    if ($text !== '') {
                        $fields[$groupBase . '.children.' . $childIndex . '.link.text'] = $text;
                    }
                }
            }
        }

        $footer = (array) ($state['footer'] ?? []);
        if ((bool) data_get($footer, 'services.enable', false)) {
            foreach (array_slice((array) data_get($footer, 'services.items', []), 0, 12, true) as $index => $item) {
                if (! is_array($item) || ($item['show'] ?? true) === false) {
                    continue;
                }
                $base = 'footer.services.items.' . $index;
                $add($base . '.title', $item['title'] ?? null);
                $add($base . '.sub_title', $item['sub_title'] ?? null);
            }
        }
        foreach (['link1', 'link2', 'link3'] as $groupKey) {
            $group = (array) data_get($footer, 'content.' . $groupKey, []);
            $add('footer.content.' . $groupKey . '.title', $group['title'] ?? null);
            foreach (array_slice((array) ($group['links'] ?? []), 0, 20, true) as $index => $link) {
                if (is_array($link)) {
                    $add('footer.content.' . $groupKey . '.links.' . $index . '.text', $link['text'] ?? null);
                }
            }
        }

        return $fields;
    }

    /** @return list<string> */
    private function supportedBuilderCodes(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(self::builderFieldContract()),
            array_keys(self::builderHtmlFieldContract()),
            // These first-party sections render only media, controls or
            // labels already held by the finite UI catalog. Marking them as
            // known prevents a misleading "unsupported" report without
            // broadening the provider boundary to image metadata or URLs.
            ['slideshow', 'image100', 'image200', 'image300', 'image301', 'image400', 'image401', 'shopify_announcement_bar'],
        )));
    }

    /**
     * The scanner and the storefront must stay in lockstep.  This is an
     * allowlist of fields that are both public and rendered by each supported
     * component; it intentionally is not a generic "translate everything in
     * module content" traversal.
     *
     * @return array<string, array{top?: list<string>, collections?: array<string, list<string>>}>
     */
    public static function builderFieldContract(): array
    {
        return [
            'promo_bar' => [
                'top' => ['title', 'sub_title', 'button_text'],
            ],
            'faq' => [
                'top'         => ['title', 'sub_title'],
                'collections' => ['items' => ['question', 'answer']],
            ],
            'trust_cards' => [
                'top'         => ['title', 'sub_title'],
                'collections' => ['items' => ['title', 'description']],
            ],
            'testimonials' => [
                'top'         => ['title', 'sub_title'],
                'collections' => ['items' => ['quote', 'name', 'detail']],
            ],
            'campaign_offer' => [
                'top'         => ['title', 'sub_title', 'code_label', 'terms'],
                'collections' => ['offers' => ['badge', 'condition', 'benefit', 'description']],
            ],
            'payment_trust' => [
                'top'         => ['title', 'sub_title', 'status_text', 'payments_title', 'payments_sub'],
                'collections' => ['benefits' => ['title', 'text']],
            ],
            'story_block' => [
                'top' => ['eyebrow', 'title', 'description', 'button_text'],
            ],
            'feature_grid' => [
                'top'         => ['title', 'sub_title'],
                'collections' => ['items' => ['eyebrow', 'title', 'text', 'link_label']],
            ],
            'social_proof' => [
                'top'         => ['title', 'sub_title'],
                'collections' => ['items' => ['label', 'detail']],
            ],
            // Older default-theme modules are still actively used by tenant
            // storefronts. They get the same immutable publication contract
            // as newer components instead of falling back to browser machine
            // translation or an unsafe generic content walk.
            'img_text_slideshow' => [
                'top'         => ['scroll_text.text'],
                'collections' => ['images' => ['sub_title', 'title', 'description']],
            ],
            'img_text_slideshow_2' => [
                'top'         => ['scroll_text.text'],
                'collections' => ['images' => ['sub_title', 'title', 'description']],
            ],
            'icons' => [
                'top'         => ['title', 'sub_title'],
                'collections' => ['images' => ['text', 'sub_text']],
            ],
            'img_text_banner' => [
                'top' => ['title', 'sub_title', 'description', 'button_text'],
            ],
            'img_text_banner_multiple' => [
                'top'         => ['title', 'sub_title'],
                'collections' => ['images' => ['text', 'sub_text']],
            ],
            'latest_products' => [
                'top' => ['title', 'sub_title'],
            ],
            'bestseller' => [
                'top' => ['title', 'sub_title'],
            ],
            'tab_product' => [
                'top'         => ['title'],
                'collections' => ['tabs' => ['title']],
            ],
            // Long-lived first-party Builder components. Their wrappers are
            // supplied by DesignService, so legacy themes receive the same
            // reviewed exact-text replacement even when an old view has not
            // yet been hand-annotated with data-lgt-field attributes.
            'brand' => [
                'top'         => ['title'],
                'collections' => ['brands' => ['name']],
            ],
            'page' => [
                'top'         => ['title'],
                'collections' => ['items' => ['title', 'summary']],
            ],
            'product' => [
                'top' => ['title'],
            ],
            'image402' => [
                'top'         => ['title', 'sub_title'],
                'collections' => ['images' => ['title']],
            ],
            'image403' => [
                'top'         => ['title', 'sub_title'],
                'collections' => ['images' => ['title', 'sub_title']],
            ],
            // Shopify-compatible blocks are still published Builder content.
            // Their text is indexed field-by-field, not by traversing an
            // arbitrary import payload. This keeps the provider boundary as
            // narrow as the native components above while covering the
            // modules used on imported storefront home/category pages.
            'shopify_slideshow' => [
                'collections' => ['slides' => ['heading', 'subheading', 'button_label']],
            ],
            'shopify_image_with_text' => [
                'collections' => ['blocks' => ['heading', 'caption', 'button_label']],
            ],
            'shopify_image_banner' => [
                'collections' => ['blocks' => ['heading', 'text', 'button_label_1', 'button_label_2']],
            ],
            'shopify_collapsible_content' => [
                'top'         => ['caption', 'heading'],
                'collections' => ['blocks' => ['heading']],
            ],
            'shopify_collection_list' => [
                // Category cards are resolved at render time. Their names
                // are scanned as public `category` entries and rendered with
                // category roots, rather than attempting to read a derived
                // collection from the saved Builder state.
                'top' => ['title'],
            ],
            'shopify_multicolumn' => [
                'top'         => ['title', 'button_label'],
                'collections' => ['blocks' => ['title', 'link_label']],
            ],
            'shopify_multirow' => [
                'collections' => ['blocks' => ['caption', 'heading', 'button_label']],
            ],
            'shopify_rich_text' => [
                'collections' => ['blocks' => ['heading', 'caption', 'button_label', 'button_label_2']],
            ],
            'shopify_featured_blog' => [
                // Blog/page cards are dynamic entities and use their own
                // published `page` dictionaries at render time.
                'top' => ['heading'],
            ],
            'shopify_video' => [
                'top' => ['heading', 'description'],
            ],
            // Page and collection content is loaded from public entities at
            // render time. It is intentionally covered by the page/category
            // scanners instead of pseudo-fields that do not exist in the
            // persisted module configuration.
            'shopify_main_page' => [],
            'shopify_page' => [],
            'shopify_main_collection_banner' => [],
            'shopify_main_list_collections' => [
                'top' => ['title'],
            ],
            'shopify_migrated' => [
                'top' => ['title', 'heading', 'sub_title', 'description', 'text', 'button_text', 'button_label'],
            ],
        ];
    }

    /**
     * Public HTML values require their own text-node contract. HTML is never
     * sent to a translation provider as markup and never reinserted into the
     * storefront as markup.
     *
     * @return array<string, array{top?:list<string>, collections?:array<string,list<string>>}>
     */
    public static function builderHtmlFieldContract(): array
    {
        return [
            'rich_text' => ['top' => ['data']],
            'shopify_image_with_text' => ['collections' => ['blocks' => ['html']]],
            'shopify_collapsible_content' => ['collections' => ['blocks' => ['html', 'page_html']]],
            'shopify_multicolumn' => ['collections' => ['blocks' => ['html']]],
            'shopify_multirow' => ['collections' => ['blocks' => ['html']]],
            'shopify_rich_text' => ['collections' => ['blocks' => ['html']]],
        ];
    }

    /**
     * Builder state historically stored codes with hyphens while the native
     * PHP views and translation contracts use underscores. Keep this mapping
     * in one tested place so a whole component family cannot silently drop
     * out of the scanner when a storefront is imported from Shopify.
     */
    public static function canonicalBuilderCode(string $code): string
    {
        return str_replace('-', '_', trim($code));
    }

    /** @param array<string, mixed> $content @return array<string, string> */
    private function builderFields(string $code, array $content): array
    {
        $contract = self::builderFieldContract()[$code] ?? null;
        if ($contract === null) {
            return [];
        }

        $fields = [];
        foreach ($contract['top'] ?? [] as $field) {
            $value = data_get($content, $field);
            $value = self::sourceLocaleValue($value);
            if ($value !== '') {
                $fields[$field] = $value;
            }
        }
        foreach ($contract['collections'] ?? [] as $collection => $fieldNames) {
            foreach (array_slice((array) ($content[$collection] ?? []), 0, 24, true) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                foreach ($fieldNames as $field) {
                    $value = self::sourceLocaleValue(data_get($item, $field));
                    if ($value !== '') {
                        $fields[$collection . '.' . $index . '.' . $field] = $value;
                    }
                }
            }
        }

        return $fields;
    }

    /** @param array<string, mixed> $content @return array<string, string> */
    private function builderHtmlFields(string $code, array $content): array
    {
        $fields = [];
        $contract = self::builderHtmlFieldContract()[$code] ?? [];
        foreach ($contract['top'] ?? [] as $field) {
            $value = self::sourceLocaleValue(data_get($content, $field));
            if (trim($value) !== '') {
                $fields[$field] = $value;
            }
        }
        foreach ($contract['collections'] ?? [] as $collection => $fieldNames) {
            foreach (array_slice((array) ($content[$collection] ?? []), 0, 24, true) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                foreach ($fieldNames as $field) {
                    $value = self::sourceLocaleValue(data_get($item, $field));
                    if (trim($value) !== '') {
                        $fields[$collection . '.' . $index . '.' . $field] = $value;
                    }
                }
            }
        }

        return $fields;
    }

    /**
     * Return only the locked-English display labels in a product's public
     * option schema.  A variable can contain image paths, SKU indexes and
     * other operational fields; none of them are content candidates.
     *
     * @return array<string, string>
     */
    public static function productVariableFields(mixed $variables): array
    {
        if (is_string($variables)) {
            try {
                $variables = json_decode($variables, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
        }
        if (! is_array($variables)) {
            return [];
        }

        $fields = [];
        foreach (array_slice(array_values($variables), 0, 12) as $variableIndex => $variable) {
            if (! is_array($variable)) {
                continue;
            }
            $name = self::sourceLocaleValue($variable['name'] ?? null);
            if (self::normalizePlainText($name) !== '') {
                $fields['variables.' . $variableIndex . '.name'] = $name;
            }
            foreach (array_slice(array_values((array) ($variable['values'] ?? [])), 0, 100) as $valueIndex => $value) {
                if (! is_array($value)) {
                    continue;
                }
                $label = self::sourceLocaleValue($value['name'] ?? null);
                if (self::normalizePlainText($label) !== '') {
                    $fields['variables.' . $variableIndex . '.values.' . $valueIndex . '.name'] = $label;
                }
            }
        }

        return $fields;
    }

    /** @param array<string, mixed> $report */
    private function recordProductVariableFields(int $productId, mixed $variables, array &$report): void
    {
        $seen = [];
        foreach (self::productVariableFields($variables) as $field => $label) {
            $this->record('product', $productId, $field, $label, 'en', 'plain', null, $report);
            $seen[] = $field;
        }

        $query = DB::table('lgt_entries')
            ->where('kind', 'product')
            ->where('entity_id', $productId)
            ->where('field', 'like', 'variables.%');
        if ($seen !== []) {
            $query->whereNotIn('field', $seen);
        }
        $query->update(['is_public' => false, 'is_tombstoned' => true, 'updated_at' => now()]);
    }

    private function stableBuilderId(string $moduleId): int
    {
        return (int) hexdec(substr(hash('sha256', 'lgt-builder:' . $moduleId), 0, 15));
    }

    private function excluded(string $kind, int $entityId, string $field): bool
    {
        return DB::table('lgt_source_exclusions')->where('kind', $kind)
            ->where(static function ($query) use ($entityId): void {
                $query->whereNull('entity_id')->orWhere('entity_id', $entityId);
            })
            ->where(static function ($query) use ($field): void {
                $query->whereNull('field')->orWhere('field', $field);
            })
            ->exists();
    }

    /**
     * The only accepted source language is English. Legacy Builder payloads
     * can contain a direct scalar, while modern blocks store a locale map;
     * never fall back to the request locale or an arbitrary first value.
     */
    private static function sourceLocaleValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        return is_array($value) && is_scalar($value['en'] ?? null)
            ? (string) $value['en']
            : '';
    }

    public static function sourceHash(string $text, string $format = 'plain'): string
    {
        return hash('sha256', "lgt-source-v2\0{$format}\0" . self::normalizePlainText($text, $format));
    }

    public static function normalizePlainText(string $text, string $format = 'plain'): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            return '';
        }
        if ($format === 'html') {
            $text = strip_tags($text);
        }
        $text = preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '';

        return mb_substr(trim($text), 0, 100_000);
    }
}
