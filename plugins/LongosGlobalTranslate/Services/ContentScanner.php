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
    private const SOURCES = [
        // HTML bodies are intentionally not in V1. A provider response must
        // never be injected back into merchant HTML until a structural,
        // node-by-node reconstruction contract is separately implemented and
        // browser-tested. Plain presentation fields remain safe to publish.
        // Every field below has a matching, safe data-lgt-field marker in a
        // storefront Blade view. Do not add metadata or HTML here until its
        // rendering contract is equally explicit.
        'product'  => ['product_descriptions', 'products', 'product_id', ['name']],
        'category' => ['category_descriptions', 'categories', 'category_id', ['name']],
        'page'     => ['page_descriptions', 'pages', 'page_id', ['title']],
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
        // Keep console/default scans backwards-compatible and catalog-only.
        // The separately labelled commerce UI scope is opt-in from the admin
        // workspace, so an operator consciously chooses to translate the
        // finite public storefront-label catalog after reviewing the safety
        // notice.
        $scope ??= [...array_keys(self::SOURCES), 'builder'];
        $report = [
            'supported_sources'      => [], 'found' => 0, 'changed' => 0, 'translated' => 0,
            'approved'               => 0, 'stale' => 0, 'excluded_by_rule' => 0,
            'unsupported_components' => [], 'errors' => [],
        ];
        foreach (self::SOURCES as $kind => [$table, $parent, $key, $fields]) {
            if (! in_array($kind, $scope, true)) {
                continue;
            }
            if (! Schema::hasTable($table) || ! Schema::hasTable($parent)) {
                $report['errors'][] = $kind . '_tables_missing';

                continue;
            }
            $fields                        = array_values(array_filter($fields, static fn (string $field): bool => Schema::hasColumn($table, $field)));
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
                ->orderBy($key)->chunk(200, function ($rows) use ($kind, $key, $fields, &$report): void {
                    foreach ($rows as $row) {
                        foreach ($fields as $field) {
                            $this->record($kind, (int) $row->{$key}, $field, (string) ($row->{$field} ?? ''), 'en', 'plain', null, $report);
                        }
                    }
                });
        }
        if (in_array('builder', $scope, true)) {
            $report['supported_sources'][] = 'builder';
            $this->scanBuilder($report);
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
                DB::table('lgt_translations')->where('entry_id', $entry->id)->update(['status' => TranslationLibrary::STALE, 'updated_at' => now()]);
                $report['changed']++;
            } else {
                DB::table('lgt_entries')->where('id', $entry->id)->update(['is_public' => true, 'is_tombstoned' => false, 'last_scanned_at' => now(), 'updated_at' => now()]);
            }
            $report['found']++;
        });
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
                $code     = trim((string) ($module['code'] ?? ''));
                if ($moduleId === '' || ! in_array($code, $this->supportedBuilderCodes(), true)) {
                    if ($code !== '') {
                        $report['unsupported_components'][] = $code;
                    }

                    continue;
                }
                $entityId = $this->stableBuilderId($moduleId);
                foreach ($this->builderFields($code, (array) ($module['content'] ?? [])) as $field => $value) {
                    $path = 'module.' . $moduleId . '.' . $field;
                    $this->record('builder', $entityId, $path, $value, 'en', 'plain', $revision, $report);
                    $seen[] = hash('sha256', implode(':', ['lgt-content-v2', 'builder', $entityId, $path, 'en']));
                }
            }
            DB::table('lgt_entries')->where('kind', 'builder')->whereNotIn('content_key', $seen ?: ['__never__'])->update(['is_public' => false, 'is_tombstoned' => true, 'updated_at' => now()]);
        } catch (\Throwable $exception) {
            $report['errors'][] = 'builder_scan_failed';
        }
        $report['unsupported_components'] = array_values(array_unique($report['unsupported_components']));
    }

    /** @return list<string> */
    private function supportedBuilderCodes(): array
    {
        return ['promo_bar', 'faq', 'trust_cards', 'testimonials', 'campaign_offer', 'payment_trust', 'story_block', 'feature_grid', 'social_proof'];
    }

    /**
     * The scanner and the storefront must stay in lockstep.  This is an
     * allowlist of fields that are both public and rendered by each supported
     * component; it intentionally is not a generic "translate everything in
     * module content" traversal.
     *
     * @return array<string, array{top: list<string>, collections?: array<string, list<string>>}>
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
        ];
    }

    /** @param array<string, mixed> $content @return array<string, string> */
    private function builderFields(string $code, array $content): array
    {
        $contract = self::builderFieldContract()[$code] ?? null;
        if ($contract === null) {
            return [];
        }

        $fields = [];
        foreach ($contract['top'] as $field) {
            if (is_scalar($content[$field] ?? null)) {
                $fields[$field] = (string) $content[$field];
            }
        }
        foreach ($contract['collections'] ?? [] as $collection => $fieldNames) {
            foreach (array_slice((array) ($content[$collection] ?? []), 0, 24, true) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                foreach ($fieldNames as $field) {
                    if (is_scalar($item[$field] ?? null)) {
                        $fields[$collection . '.' . $index . '.' . $field] = (string) $item[$field];
                    }
                }
            }
        }

        return $fields;
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
