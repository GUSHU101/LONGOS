<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Beike\Services\BuilderStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DictionaryService
{
    /**
     * Bounded public read: callers cannot enumerate a whole tenant dictionary
     * or query an arbitrary private id. Source visibility and the frozen
     * publication source hash are rechecked for every response.
     *
     * @return array{revision:?int,items:array<string,string>}
     */
    public function forEntity(string $kind, int $entityId, string $locale, int $limit = 128): array
    {
        $kind = strtolower(trim($kind));
        if (! in_array($kind, ['product', 'category', 'page', 'builder'], true) || $entityId < 1) {
            return ['revision' => null, 'items' => []];
        }
        if (! Schema::hasTable('lgt_publications') || ! Schema::hasTable('lgt_publication_items') || ! Schema::hasTable('lgt_translations') || ! Schema::hasTable('lgt_entries')) {
            return ['revision' => null, 'items' => []];
        }
        if (! $this->entityIsStillPublic($kind, $entityId)) {
            return ['revision' => null, 'items' => []];
        }
        $locale      = TranslationSettings::locale($locale);
        // There is normally one active record per locale.  Sort explicitly
        // nonetheless: a database recovered from an interrupted deployment
        // may temporarily contain two marked rows, and presentation must
        // deterministically use the newest immutable snapshot.
        $publication = DB::table('lgt_publications')->where('locale', $locale)->where('state', 'published')
            ->orderByDesc('revision')->orderByDesc('id')->first();
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
            ->limit(min(200, max(1, $limit)))
            ->get(['e.content_key', 'e.source_text', 'e.source_hash', 'e.source_format', 'e.source_revision', 'e.kind', 'e.entity_id', 'e.field', 'i.text'])
            ->filter(fn (object $item): bool => $this->sourceIsStillCurrent($item));

        // Keep the V1 hash contract, but also supply public field names and
        // verified source text for older themes without data-lgt-* markers.
        // Never expose unpublished/private, stale or hidden source rows.
        return [
            'revision' => (int) $publication->revision,
            'items' => $items->mapWithKeys(static fn (object $item): array => [(string) $item->content_key => (string) $item->text])->all(),
            'fields' => $items->mapWithKeys(static fn (object $item): array => [(string) $item->field => (string) $item->text])->all(),
            'sources' => $items->mapWithKeys(static fn (object $item): array => [(string) $item->content_key => (string) $item->source_text])->all(),
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
        if (! Schema::hasTable('lgt_publications') || ! Schema::hasTable('lgt_publication_items') || ! Schema::hasTable('lgt_translations') || ! Schema::hasTable('lgt_entries')) {
            return ['revision' => null, 'items' => []];
        }
        $locale      = TranslationSettings::locale($locale);
        $publication = DB::table('lgt_publications')->where('locale', $locale)->where('state', 'published')
            ->orderByDesc('revision')->orderByDesc('id')->first();
        if ($publication === null) {
            return ['revision' => null, 'items' => []];
        }

        $catalog = CommerceUiCatalog::entries();
        $items = DB::table('lgt_publication_items as i')
            ->join('lgt_translations as t', 't.id', '=', 'i.translation_id')
            ->join('lgt_entries as e', 'e.id', '=', 't.entry_id')
            ->where('i.publication_id', $publication->id)
            ->where('e.kind', CommerceUiCatalog::KIND)
            ->where('e.entity_id', CommerceUiCatalog::ENTITY_ID)
            ->where('e.is_public', true)
            ->where('e.is_tombstoned', false)
            ->whereColumn('i.source_hash', 'e.source_hash')
            ->orderBy('e.id')
            ->limit(200)
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
    private function entityIsStillPublic(string $kind, int $entityId): bool
    {
        $table = match ($kind) {
            'product'  => 'products',
            'category' => 'categories',
            'page'     => 'pages',
            default    => null,
        };
        if ($table === null) {
            return true;
        }
        if (! Schema::hasTable($table)) {
            return false;
        }

        $query = DB::table($table)->where('id', $entityId);
        if (Schema::hasColumn($table, 'active')) {
            $query->where('active', true);
        }
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->exists();
    }

    /**
     * Publication snapshots are immutable, but the source model can change
     * between scheduled scans. Re-hash the very small, allowlisted source
     * field before returning a dictionary entry; a mismatch fails closed.
     */
    private function sourceIsStillCurrent(object $entry): bool
    {
        if ($entry->kind === 'builder') {
            try {
                $state = BuilderStateService::published();

                return hash_equals((string) $entry->source_revision, 'published:' . (int) ($state['version'] ?? 0));
            } catch (\Throwable) {
                return false;
            }
        }
        if ($entry->kind === CommerceUiCatalog::KIND) {
            $source = CommerceUiCatalog::sourceFor((string) $entry->field);

            return is_string($source)
                && hash_equals((string) $entry->source_hash, ContentScanner::sourceHash($source))
                && hash_equals((string) $entry->source_text, $source);
        }
        $source = match ($entry->kind) {
            'product'  => ['table' => 'product_descriptions', 'key' => 'product_id', 'fields' => ['name']],
            'category' => ['table' => 'category_descriptions', 'key' => 'category_id', 'fields' => ['name']],
            'page'     => ['table' => 'page_descriptions', 'key' => 'page_id', 'fields' => ['title']],
            default    => null,
        };
        if ($source === null || ! in_array((string) $entry->field, $source['fields'], true) || ! Schema::hasTable($source['table']) || ! Schema::hasColumn($source['table'], (string) $entry->field)) {
            return false;
        }
        $current = DB::table($source['table'])
            ->where($source['key'], $entry->entity_id)
            ->where('locale', 'en')
            ->value((string) $entry->field);

        return is_string($current) && hash_equals((string) $entry->source_hash, ContentScanner::sourceHash($current, (string) $entry->source_format));
    }
}
