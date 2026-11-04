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
        $locales = array_values(array_unique((array) ($settings['enabled_locales'] ?? [])));
        $widgetEnabled = (bool) data_get($settings, 'widget.enabled', false);
        $sources = DB::table('lgt_entries')
            ->where('is_public', true)->where('is_tombstoned', false)->count();
        if ($locales === []) {
            return $this->emptySnapshot((int) $sources, $widgetEnabled);
        }

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
            ->selectRaw("SUM(CASE WHEN t.status IN ('translated','needs_review','approved') THEN 1 ELSE 0 END) AS ready")
            ->selectRaw("SUM(CASE WHEN t.status = 'approved' THEN 1 ELSE 0 END) AS approved")
            ->selectRaw("SUM(CASE WHEN t.status IN ('translated','needs_review') THEN 1 ELSE 0 END) AS reviewable")
            ->groupBy('t.locale')->get()->keyBy('locale');

        $published = DB::table('lgt_publications as p')
            ->leftJoin('lgt_publication_items as i', 'i.publication_id', '=', 'p.id')
            ->leftJoin('lgt_translations as t', 't.id', '=', 'i.translation_id')
            ->leftJoin('lgt_entries as e', 'e.id', '=', 't.entry_id')
            ->whereIn('p.locale', $locales)->where('p.state', 'published')
            ->select('p.locale')
            ->selectRaw('MAX(p.revision) AS revision')
            ->selectRaw('COUNT(DISTINCT CASE WHEN e.is_public = 1 AND e.is_tombstoned = 0 AND i.source_hash = e.source_hash THEN i.id ELSE NULL END) AS current_items')
            ->groupBy('p.locale')->get()->keyBy('locale');

        $rows = [];
        $translatedTotal = 0;
        $approvedTotal = 0;
        $publishedLanguages = 0;
        $publishedItems = 0;
        foreach ($locales as $locale) {
            $found = $translated->get($locale);
            $live = $published->get($locale);
            $ready = (int) ($found->ready ?? 0);
            $approved = (int) ($found->approved ?? 0);
            $reviewable = (int) ($found->reviewable ?? 0);
            $publishedCount = (int) ($live->current_items ?? 0);
            $translatedTotal += $ready;
            $approvedTotal += $approved;
            $publishedItems += $publishedCount;
            $publishedLanguages += (int) ($publishedCount > 0);
            $rows[] = [
                'locale' => (string) $locale,
                'sources' => (int) $sources,
                'reusable' => $ready,
                'missing' => max(0, (int) $sources - $ready),
                'approved' => $approved,
                'needs_review' => $reviewable,
                'published_items' => $publishedCount,
                'revision' => $live?->revision !== null ? (int) $live->revision : null,
                'next_action' => self::nextAction((int) $sources, $ready, $approved, $reviewable, $publishedCount, $widgetEnabled),
            ];
        }

        return [
            'source_entries' => (int) $sources,
            'translated' => $translatedTotal,
            'approved' => $approvedTotal,
            'published_languages' => $publishedLanguages,
            'published_items' => $publishedItems,
            'widget_enabled' => $widgetEnabled,
            'ready_to_display' => $publishedLanguages > 0 && $widgetEnabled,
            'locales' => $rows,
        ];
    }

    public static function nextAction(int $sources, int $ready, int $approved, int $reviewable, int $published, bool $widget): string
    {
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
            return '已有发布词典，请开启前台语言选择器';
        }
        if ($published < $sources) {
            return '已部分上线；可补齐缺失译文并重新发布';
        }

        return '已达到索引级可用状态，请打开真实前台验证';
    }

    /** @return array<string, mixed> */
    private function emptySnapshot(int $sources, bool $widget): array
    {
        return [
            'source_entries' => $sources, 'translated' => 0, 'approved' => 0,
            'published_languages' => 0, 'published_items' => 0,
            'widget_enabled' => $widget, 'ready_to_display' => false, 'locales' => [],
        ];
    }
}
