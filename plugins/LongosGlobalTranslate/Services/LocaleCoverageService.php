<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Illuminate\Support\Facades\DB;

/**
 * Read-only tenant-local deployment dashboard. These counts describe indexed
 * public sources and current source hashes; they deliberately do not claim to
 * replace the DictionaryService's request-time parent/Builder visibility tests.
 * No external API request, credential, customer table, or raw source text here.
 */
final class LocaleCoverageService
{
    /** @param array<string, mixed> $settings
     *  @return array<string, mixed>
     */
    public function snapshot(array $settings): array
    {
        $locales             = array_values(array_unique((array) ($settings['enabled_locales'] ?? [])));
        $widgetEnabled       = (bool) data_get($settings, 'widget.enabled', false);
        $presentationEnabled = TranslationSettings::presentationActive($settings);
        $sources             = DB::table('lgt_entries')
            ->where('is_public', true)->where('is_tombstoned', false)->count();
        // Unlike product or Builder content, fixed checkout/account/payment
        // labels are needed on every sensitive storefront route. Count them
        // separately so a product-only publication can never be presented as
        // a fully translated checkout experience.
        $commerceUiExpected = count(CommerceUiCatalog::entries());
        $commerceUiSources  = DB::table('lgt_entries')
            ->where('kind', CommerceUiCatalog::KIND)
            ->where('entity_id', CommerceUiCatalog::ENTITY_ID)
            ->where('is_public', true)->where('is_tombstoned', false)
            ->count();
        if ($locales === []) {
            return $this->emptySnapshot((int) $sources, $widgetEnabled, $commerceUiExpected, (int) $commerceUiSources, $presentationEnabled);
        }

        // Laravel prefixes SQL aliases together with table names on the
        // tenant connection (for example `tenant_a_t`).  Query-builder
        // clauses are prefix-aware, but selectRaw expressions are not; build
        // their identifiers from the active connection so this dashboard is
        // safe on both shared-table and isolated-table tenancy layouts.
        $connection           = DB::connection();
        $grammar              = $connection->getQueryGrammar();
        $tablePrefix          = $connection->getTablePrefix();
        $translationAlias     = $grammar->wrap($tablePrefix . 't');
        $entryAlias           = $grammar->wrap($tablePrefix . 'e');
        $publicationItemAlias = $grammar->wrap($tablePrefix . 'i');

        // Only hashes that still match the current scanned public source are
        // counted as reusable. Stale translations do not inflate readiness.
        $translated = DB::table('lgt_translations as t')
            ->join('lgt_entries as e', 'e.id', '=', 't.entry_id')
            ->whereIn('t.locale', $locales)
            ->where('e.is_public', true)->where('e.is_tombstoned', false)
            ->whereColumn('t.source_hash', 'e.source_hash')
            ->whereNotNull('t.text')
            ->where('t.text', '!=', '')
            ->select('t.locale')
            ->selectRaw("SUM(CASE WHEN {$translationAlias}.status IN ('translated','needs_review','approved') THEN 1 ELSE 0 END) AS ready")
            ->selectRaw("SUM(CASE WHEN {$translationAlias}.status = 'approved' THEN 1 ELSE 0 END) AS approved")
            ->selectRaw("SUM(CASE WHEN {$translationAlias}.status IN ('translated','needs_review') THEN 1 ELSE 0 END) AS reviewable")
            ->groupBy('t.locale')->get()->keyBy('locale');

        // A recovery may briefly leave more than one row marked published.
        // Choose the same newest immutable revision that DictionaryService
        // uses rather than aggregating superseded snapshots into readiness.
        $currentPublications = DB::table('lgt_publications')
            ->whereIn('locale', $locales)->where('state', 'published')
            ->orderByDesc('revision')->orderByDesc('id')->get()
            ->unique('locale')->keyBy('locale');
        $publicationIds = $currentPublications->pluck('id')->all();
        $published      = $publicationIds === [] ? collect() : DB::table('lgt_publication_items as i')
            ->leftJoin('lgt_translations as t', 't.id', '=', 'i.translation_id')
            ->leftJoin('lgt_entries as e', 'e.id', '=', 't.entry_id')
            ->whereIn('i.publication_id', $publicationIds)
            ->select('i.publication_id')
            ->selectRaw("COUNT(DISTINCT CASE WHEN {$entryAlias}.is_public = 1 AND {$entryAlias}.is_tombstoned = 0 AND {$publicationItemAlias}.source_hash = {$entryAlias}.source_hash THEN {$publicationItemAlias}.id ELSE NULL END) AS current_items")
            ->selectRaw(
                "COUNT(DISTINCT CASE WHEN {$entryAlias}.kind = ? AND {$entryAlias}.entity_id = ? AND {$entryAlias}.is_public = 1 AND {$entryAlias}.is_tombstoned = 0 AND {$publicationItemAlias}.source_hash = {$entryAlias}.source_hash THEN {$publicationItemAlias}.id ELSE NULL END) AS commerce_ui_items",
                [CommerceUiCatalog::KIND, CommerceUiCatalog::ENTITY_ID],
            )
            ->groupBy('i.publication_id')->get()->keyBy('publication_id');

        $rows                     = [];
        $translatedTotal          = 0;
        $approvedTotal            = 0;
        $publishedLanguages       = 0;
        $publishedItems           = 0;
        $commerceUiReadyLanguages = 0;
        foreach ($locales as $locale) {
            $found               = $translated->get($locale);
            $publication         = $currentPublications->get($locale);
            $live                = $publication ? $published->get($publication->id) : null;
            $ready               = (int) ($found->ready ?? 0);
            $approved            = (int) ($found->approved ?? 0);
            $reviewable          = (int) ($found->reviewable ?? 0);
            $publishedCount      = (int) ($live->current_items ?? 0);
            $commerceUiPublished = (int) ($live->commerce_ui_items ?? 0);
            $commerceUiReady     = $commerceUiExpected > 0
                && $commerceUiSources   >= $commerceUiExpected
                && $commerceUiPublished >= $commerceUiExpected;
            $translatedTotal          += $ready;
            $approvedTotal            += $approved;
            $publishedItems           += $publishedCount;
            $publishedLanguages       += (int) ($publishedCount > 0);
            $commerceUiReadyLanguages += (int) $commerceUiReady;
            $rows[] = [
                'locale'                => (string) $locale,
                'sources'               => (int) $sources,
                'reusable'              => $ready,
                'missing'               => max(0, (int) $sources - $ready),
                'approved'              => $approved,
                'needs_review'          => $reviewable,
                'published_items'       => $publishedCount,
                'revision'              => $publication?->revision !== null ? (int) $publication->revision : null,
                'commerce_ui_expected'  => $commerceUiExpected,
                'commerce_ui_sources'   => (int) $commerceUiSources,
                'commerce_ui_published' => $commerceUiPublished,
                'commerce_ui_ready'     => $commerceUiReady,
                'next_action'           => self::nextAction(
                    (int) $sources,
                    $ready,
                    $approved,
                    $reviewable,
                    $publishedCount,
                    $presentationEnabled,
                    $commerceUiExpected,
                    (int) $commerceUiSources,
                    $commerceUiPublished,
                ),
            ];
        }

        return [
            'source_entries'               => (int) $sources,
            'translated'                   => $translatedTotal,
            'approved'                     => $approvedTotal,
            'published_languages'          => $publishedLanguages,
            'published_items'              => $publishedItems,
            'widget_enabled'               => $widgetEnabled,
            'presentation_enabled'         => $presentationEnabled,
            'ready_to_display'             => $publishedLanguages > 0 && $presentationEnabled,
            'commerce_ui_expected'         => $commerceUiExpected,
            'commerce_ui_sources'          => (int) $commerceUiSources,
            'commerce_ui_ready_languages'  => $commerceUiReadyLanguages,
            'commerce_ui_ready_to_display' => $locales !== []
                && $commerceUiSources > 0
                && $commerceUiReadyLanguages === count($locales)
                && $presentationEnabled,
            'locales' => $rows,
        ];
    }

    public static function nextAction(
        int $sources,
        int $ready,
        int $approved,
        int $reviewable,
        int $published,
        bool $widget,
        int $commerceUiExpected = 0,
        int $commerceUiSources = 0,
        int $commerceUiPublished = 0,
    ): string {
        if ($sources < 1) {
            return '请先扫描公开原文';
        }
        if ($ready < 1) {
            return '请创建增量翻译任务并检查队列';
        }
        if ($approved < 1 && $reviewable > 0) {
            return '请先抽查并审核译文';
        }
        if ($published < 1 && $approved > 0) {
            return '请发布该语言的已审核词典';
        }
        if ($published < 1) {
            return '暂无可发布译文，请检查翻译结果';
        }
        if (! $widget) {
            return '已有发布词典，请开启地区自动本地化或语言选择器';
        }
        if ($commerceUiExpected > 0 && $commerceUiSources < $commerceUiExpected) {
            return "结账、账户与支付的固定界面词典尚未完整扫描（{$commerceUiSources}/{$commerceUiExpected}）；请创建包含“固定界面文案”的任务";
        }
        if ($commerceUiExpected > 0 && $commerceUiPublished < $commerceUiExpected) {
            return "结账、账户与支付界面词典未完整发布（{$commerceUiPublished}/{$commerceUiExpected}）；请补齐固定界面文案并重新发布";
        }
        if ($published < $sources) {
            return '已部分上线；可补齐缺失译文并重新发布';
        }

        return '已达到索引级可用状态，请打开真实前台验证';
    }

    /** @return array<string, mixed> */
    private function emptySnapshot(int $sources, bool $widget, int $commerceUiExpected, int $commerceUiSources, bool $presentationEnabled): array
    {
        return [
            'source_entries'               => $sources, 'translated' => 0, 'approved' => 0,
            'published_languages'          => 0, 'published_items' => 0,
            'widget_enabled'               => $widget, 'presentation_enabled' => $presentationEnabled, 'ready_to_display' => false,
            'commerce_ui_expected'         => $commerceUiExpected,
            'commerce_ui_sources'          => $commerceUiSources,
            'commerce_ui_ready_languages'  => 0,
            'commerce_ui_ready_to_display' => false,
            'locales'                      => [],
        ];
    }
}
