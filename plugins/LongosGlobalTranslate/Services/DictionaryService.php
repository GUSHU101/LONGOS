<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Beike\Services\BuilderStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DictionaryService
{
    /** @var list<string> */
    private const PUBLIC_KINDS = ['product', 'category', 'page', 'page_category', 'builder', ContentScanner::CHROME_KIND];

    /**
     * Bounded public read: callers cannot enumerate a whole tenant dictionary
     * or query an arbitrary private id. Source visibility and the frozen
     * publication source hash are rechecked for every response.
     *
     * @return array{revision:?int,items:array<string,string>,fields?:array<string,string>,field_sources?:array<string,string>,sources?:array<string,string>}
     */
    public function forEntity(string $kind, int $entityId, string $locale, int $limit = 1200): array
    {
        $memo = $this->readMemo();

        return $this->forEntityWithMemo($kind, $entityId, $locale, $limit, $memo);
    }

    /**
     * Read multiple public regions from one request-local validation context.
     *
     * The context exists only for the current controller call. It contains no
     * rendered translation data and is intentionally not stored in Laravel's
     * cache, session, static properties or a singleton, so tenant content
     * cannot bleed through a long-lived worker.
     *
     * @param array<string, array{kind:string,entity_id:int}> $entities
     * @return array<string, array{revision:?int,items:array<string,string>,fields?:array<string,string>,field_sources?:array<string,string>,sources?:array<string,string>}>
     */
    public function forEntities(array $entities, string $locale, int $limit = 1200): array
    {
        $locale = TranslationSettings::locale($locale);
        $memo   = $this->readMemo();
        $result = [];

        foreach ($entities as $key => $entity) {
            $result[(string) $key] = $this->forEntityWithMemo(
                (string) ($entity['kind'] ?? ''),
                (int) ($entity['entity_id'] ?? 0),
                $locale,
                $limit,
                $memo,
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $memo
     * @return array{revision:?int,items:array<string,string>,fields?:array<string,string>,field_sources?:array<string,string>,sources?:array<string,string>}
     */
    private function forEntityWithMemo(string $kind, int $entityId, string $locale, int $limit, array &$memo): array
    {
        $kind = strtolower(trim($kind));
        if (! in_array($kind, self::PUBLIC_KINDS, true) || $entityId < 1) {
            return ['revision' => null, 'items' => []];
        }
        if (! $this->hasDictionaryTables($memo)) {
            return ['revision' => null, 'items' => []];
        }
        if (! $this->entityIsStillPublic($kind, $entityId, $memo)) {
            return ['revision' => null, 'items' => []];
        }
        $locale      = TranslationSettings::locale($locale);
        // There is normally one active record per locale.  Sort explicitly
        // nonetheless: a database recovered from an interrupted deployment
        // may temporarily contain two marked rows, and presentation must
        // deterministically use the newest immutable snapshot.
        $publication = $this->currentPublication($locale, $memo);
        if ($publication === null) {
            return ['revision' => null, 'items' => []];
        }
        $items = DB::table('lgt_publication_items as i')
            ->join('lgt_translations as t', 't.id', '=', 'i.translation_id')
            ->join('lgt_entries as e', 'e.id', '=', 't.entry_id')
            ->where('i.publication_id', $publication->id)
            ->where('e.kind', $kind)
            ->where('e.entity_id', $entityId)
            ->where('e.is_public', true)
            ->where('e.is_tombstoned', false)
            ->whereColumn('i.source_hash', 'e.source_hash')
            ->orderBy('e.id')
            // A product/page rich-text body has one validated dictionary
            // record per visible text node. Do not silently truncate it at a
            // small card-oriented limit, otherwise the lower half of a
            // product description becomes permanently untranslated.
            ->limit(min(1200, max(1, $limit)))
            ->get(['e.content_key', 'e.source_text', 'e.source_hash', 'e.source_format', 'e.source_revision', 'e.kind', 'e.entity_id', 'e.field', 'i.text'])
            ->filter(fn (object $item): bool => $this->sourceIsStillCurrent($item, $memo));

        // Keep the V1 hash contract, but also supply public field names and
        // verified source text for older themes without data-lgt-* markers.
        // Never expose unpublished/private, stale or hidden source rows.
        return [
            'revision'      => (int) $publication->revision,
            'items'         => $items->mapWithKeys(static fn (object $item): array => [(string) $item->content_key => (string) $item->text])->all(),
            'fields'        => $items->mapWithKeys(static fn (object $item): array => [(string) $item->field => (string) $item->text])->all(),
            'field_sources' => $items->mapWithKeys(static fn (object $item): array => [(string) $item->field => (string) $item->source_text])->all(),
            'sources'       => $items->mapWithKeys(static fn (object $item): array => [(string) $item->content_key => (string) $item->source_text])->all(),
        ];
    }

    /**
     * Return only the reviewed, published static interface vocabulary.
     *
     * Unlike a catalog dictionary this response contains no merchant content
     * and no customer/order/payment data.  The browser uses the paired source
     * text to replace exact static labels only; values and free text are not
     * candidates for replacement.
     *
     * @return array{revision:?int,items:array<string,array{source:string,text:string}>}
     */
    public function forCommerceInterface(string $locale): array
    {
        $memo = $this->readMemo();
        if (! $this->hasDictionaryTables($memo)) {
            return ['revision' => null, 'items' => []];
        }
        $locale      = TranslationSettings::locale($locale);
        $publication = $this->currentPublication($locale, $memo);
        if ($publication === null) {
            return ['revision' => null, 'items' => []];
        }

        $catalog = CommerceUiCatalog::entries();
        $items   = DB::table('lgt_publication_items as i')
            ->join('lgt_translations as t', 't.id', '=', 'i.translation_id')
            ->join('lgt_entries as e', 'e.id', '=', 't.entry_id')
            ->where('i.publication_id', $publication->id)
            ->where('e.kind', CommerceUiCatalog::KIND)
            ->where('e.entity_id', CommerceUiCatalog::ENTITY_ID)
            ->where('e.is_public', true)
            ->where('e.is_tombstoned', false)
            ->whereColumn('i.source_hash', 'e.source_hash')
            ->orderBy('e.id')
            // The limit comes only from a checked-in finite catalog, not from
            // database content. Never impose a lower arbitrary cap: doing so
            // would silently omit valid fixed labels from a future default
            // theme while offering no privacy benefit.
            ->limit(max(1, count($catalog)))
            ->get(['e.field', 'e.source_text', 'e.source_hash', 'i.text'])
            ->filter(static function (object $item) use ($catalog): bool {
                $source = $catalog[(string) $item->field] ?? null;

                return is_string($source)
                    && hash_equals((string) $item->source_hash, ContentScanner::sourceHash($source))
                    && hash_equals($source, (string) $item->source_text);
            })
            ->mapWithKeys(static fn (object $item): array => [(string) $item->field => [
                'source' => (string) $item->source_text,
                'text'   => (string) $item->text,
            ]])
            ->all();

        return ['revision' => (int) $publication->revision, 'items' => $items];
    }

    /**
     * A publication is immutable, but the source entity can be hidden after
     * publishing. Re-checking one known parent row protects a storefront from
     * showing translations for a now-private product/category/page. Builder
     * modules are already constrained to a published builder state by the
     * scanner and have no stable public parent table to query here.
     */
    /** @param array<string, mixed> $memo */
    private function entityIsStillPublic(string $kind, int $entityId, array &$memo): bool
    {
        $cacheKey = $kind . ':' . $entityId;
        if (array_key_exists($cacheKey, $memo['public_entities'])) {
            return (bool) $memo['public_entities'][$cacheKey];
        }
        if ($kind === ContentScanner::CHROME_KIND) {
            return $memo['public_entities'][$cacheKey] = $entityId === ContentScanner::CHROME_ENTITY_ID;
        }
        $table = match ($kind) {
            'product'       => 'products',
            'category'      => 'categories',
            'page'          => 'pages',
            'page_category' => 'page_categories',
            default         => null,
        };
        if ($table === null) {
            return $memo['public_entities'][$cacheKey] = true;
        }
        if (! $this->hasTable($table, $memo)) {
            return $memo['public_entities'][$cacheKey] = false;
        }

        $query = DB::table($table)->where('id', $entityId);
        if ($this->hasColumn($table, 'active', $memo)) {
            $query->where('active', true);
        }
        if ($this->hasColumn($table, 'deleted_at', $memo)) {
            $query->whereNull('deleted_at');
        }

        return $memo['public_entities'][$cacheKey] = $query->exists();
    }

    /**
     * Publication snapshots are immutable, but the source model can change
     * between scheduled scans. Re-hash the very small, allowlisted source
     * field before returning a dictionary entry; a mismatch fails closed.
     */
    /** @param array<string, mixed> $memo */
    private function sourceIsStillCurrent(object $entry, array &$memo): bool
    {
        if ($entry->kind === 'builder') {
            $state = $this->publishedBuilderState($memo);

            return $state !== null && hash_equals((string) $entry->source_revision, 'published:' . (int) ($state['version'] ?? 0));
        }
        if ($entry->kind === ContentScanner::CHROME_KIND) {
            $state = $this->publishedBuilderState($memo);
            if ($state === null || ! hash_equals((string) $entry->source_revision, 'published:' . (int) ($state['version'] ?? 0))) {
                return false;
            }
            $source = ContentScanner::chromeTextFields($state)[(string) $entry->field] ?? null;

            return is_string($source)
                && hash_equals((string) $entry->source_hash, ContentScanner::sourceHash($source, (string) $entry->source_format))
                && hash_equals((string) $entry->source_text, $source);
        }
        if ($entry->kind === CommerceUiCatalog::KIND) {
            $source = CommerceUiCatalog::sourceFor((string) $entry->field);

            return is_string($source)
                && hash_equals((string) $entry->source_hash, ContentScanner::sourceHash($source))
                && hash_equals((string) $entry->source_text, $source);
        }
        $source = match ($entry->kind) {
            'product'       => ['table' => 'product_descriptions', 'key' => 'product_id', 'fields' => ['name'], 'html_fields' => ['content']],
            'category'      => ['table' => 'category_descriptions', 'key' => 'category_id', 'fields' => ['name'], 'html_fields' => ['content']],
            'page'          => ['table' => 'page_descriptions', 'key' => 'page_id', 'fields' => ['title', 'summary'], 'html_fields' => ['content']],
            'page_category' => ['table' => 'page_category_descriptions', 'key' => 'page_category_id', 'fields' => ['title', 'summary'], 'html_fields' => []],
            default         => null,
        };
        if ($source === null || ! $this->hasTable($source['table'], $memo)) {
            return false;
        }
        $field = (string) $entry->field;
        if ($entry->kind === 'product' && str_starts_with($field, 'variables.')) {
            if (! $this->hasTable('products', $memo) || ! $this->hasColumn('products', 'variables', $memo)) {
                return false;
            }
            $current = $this->productVariableFields((int) $entry->entity_id, $memo)[$field] ?? null;

            return is_string($current) && hash_equals((string) $entry->source_hash, ContentScanner::sourceHash($current, (string) $entry->source_format));
        }
        $record = $this->sourceRecord((string) $entry->kind, (int) $entry->entity_id, $source, $memo);
        if ($record === null) {
            return false;
        }
        if (in_array($field, $source['fields'], true) && $this->hasColumn($source['table'], $field, $memo)) {
            $current = $record->{$field} ?? null;

            return is_string($current) && hash_equals((string) $entry->source_hash, ContentScanner::sourceHash($current, (string) $entry->source_format));
        }
        foreach ($source['html_fields'] ?? [] as $htmlField) {
            if (! str_starts_with($field, $htmlField . '.node.') || ! $this->hasColumn($source['table'], $htmlField, $memo)) {
                continue;
            }
            $html = $record->{$htmlField} ?? null;
            if (! is_string($html)) {
                return false;
            }
            $fields  = ContentScanner::htmlTextFields($htmlField, $html);
            $current = $fields[$field] ?? null;

            return is_string($current) && hash_equals((string) $entry->source_hash, ContentScanner::sourceHash($current, (string) $entry->source_format));
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function readMemo(): array
    {
        return [
            'tables'            => [], 'columns' => [], 'publications' => [],
            'public_entities'   => [], 'source_records' => [],
            'product_variables' => [], 'builder_state_loaded' => false,
            'builder_state'     => null,
        ];
    }

    /** @param array<string, mixed> $memo */
    private function hasDictionaryTables(array &$memo): bool
    {
        foreach (['lgt_publications', 'lgt_publication_items', 'lgt_translations', 'lgt_entries'] as $table) {
            if (! $this->hasTable($table, $memo)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $memo */
    private function hasTable(string $table, array &$memo): bool
    {
        if (! array_key_exists($table, $memo['tables'])) {
            $memo['tables'][$table] = Schema::hasTable($table);
        }

        return (bool) $memo['tables'][$table];
    }

    /** @param array<string, mixed> $memo */
    private function hasColumn(string $table, string $column, array &$memo): bool
    {
        $key = $table . '.' . $column;
        if (! array_key_exists($key, $memo['columns'])) {
            $memo['columns'][$key] = Schema::hasColumn($table, $column);
        }

        return (bool) $memo['columns'][$key];
    }

    /** @param array<string, mixed> $memo */
    private function currentPublication(string $locale, array &$memo): ?object
    {
        if (! array_key_exists($locale, $memo['publications'])) {
            $memo['publications'][$locale] = DB::table('lgt_publications')->where('locale', $locale)->where('state', 'published')
                ->orderByDesc('revision')->orderByDesc('id')->first();
        }

        $publication = $memo['publications'][$locale];

        return is_object($publication) ? $publication : null;
    }

    /** @param array<string, mixed> $memo */
    private function publishedBuilderState(array &$memo): ?array
    {
        if (! $memo['builder_state_loaded']) {
            $memo['builder_state_loaded'] = true;

            try {
                $state                 = BuilderStateService::published();
                $memo['builder_state'] = is_array($state) ? $state : null;
            } catch (\Throwable) {
                $memo['builder_state'] = null;
            }
        }

        return is_array($memo['builder_state']) ? $memo['builder_state'] : null;
    }

    /**
     * @param array{table:string,key:string,fields:list<string>,html_fields:list<string>} $source
     * @param array<string, mixed>                                                        $memo
     */
    private function sourceRecord(string $kind, int $entityId, array $source, array &$memo): ?object
    {
        $cacheKey = $kind . ':' . $entityId;
        if (! array_key_exists($cacheKey, $memo['source_records'])) {
            $columns                           = array_values(array_filter(array_unique(array_merge($source['fields'], $source['html_fields'])), fn (string $column): bool => $this->hasColumn($source['table'], $column, $memo)));
            $memo['source_records'][$cacheKey] = $columns === [] ? null : DB::table($source['table'])
                ->where($source['key'], $entityId)->where('locale', 'en')->first($columns);
        }

        $record = $memo['source_records'][$cacheKey];

        return is_object($record) ? $record : null;
    }

    /** @param array<string, mixed> $memo
     * @return array<string, string>
     */
    private function productVariableFields(int $productId, array &$memo): array
    {
        if (! array_key_exists($productId, $memo['product_variables'])) {
            $variables                             = DB::table('products')->where('id', $productId)->value('variables');
            $memo['product_variables'][$productId] = ContentScanner::productVariableFields($variables);
        }

        return (array) $memo['product_variables'][$productId];
    }
}
