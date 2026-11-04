<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class PublicationService
{
    /** @return array{id:int,locale:string,revision:int,coverage:array<string,int>} */
    public function publish(string $locale, ?int $actorId): array
    {
        $locale = TranslationSettings::locale($locale);

        return DB::transaction(function () use ($locale, $actorId): array {
            $last         = DB::table('lgt_publications')->where('locale', $locale)->lockForUpdate()->max('revision');
            $revision     = (int) $last + 1;
            $translations = DB::table('lgt_translations as t')
                ->join('lgt_entries as e', 'e.id', '=', 't.entry_id')
                ->where('t.locale', $locale)
                ->where('t.status', TranslationLibrary::APPROVED)
                ->whereColumn('t.source_hash', 'e.source_hash')
                ->where('e.is_public', true)
                ->where('e.is_tombstoned', false)
                ->orderBy('t.id')
                ->get(['t.id', 't.version', 't.source_hash', 't.text']);
            $coverage = [
                'approved'       => $translations->count(),
                'stale'          => DB::table('lgt_translations')->where('locale', $locale)->where('status', TranslationLibrary::STALE)->count(),
                'pending_review' => DB::table('lgt_translations')->where('locale', $locale)->where('status', TranslationLibrary::NEEDS_REVIEW)->count(),
            ];
            if ($coverage['approved'] === 0) {
                throw ValidationException::withMessages([
                    'locale' => '当前语言没有已审核且仍与公开原文一致的译文。请先完成翻译，或使用下方「审核合格译文并发布」。',
                ]);
            }
            // Publishing twice without any text/version change should not
            // create dozens of identical immutable releases. A source change
            // or a human review always produces a new snapshot as expected.
            $current = DB::table('lgt_publications')->where('locale', $locale)
                ->where('state', 'published')->orderByDesc('revision')->orderByDesc('id')->first();
            if ($current !== null && $this->samePublishedSnapshot((int) $current->id, $translations)) {
                return [
                    'id' => (int) $current->id, 'locale' => $locale,
                    'revision' => (int) $current->revision, 'coverage' => $coverage, 'unchanged' => true,
                ];
            }
            $publicationId = DB::table('lgt_publications')->insertGetId([
                'locale'            => $locale,
                'revision'          => $revision,
                'state'             => 'published',
                'published_by'      => $actorId,
                'published_at'      => now(),
                'coverage_snapshot' => json_encode($coverage),
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
            foreach ($translations->chunk(250) as $chunk) {
                DB::table('lgt_publication_items')->insert($chunk->map(static fn (object $translation): array => [
                    'publication_id'      => $publicationId,
                    'translation_id'      => $translation->id,
                    'translation_version' => $translation->version,
                    'source_hash'         => $translation->source_hash,
                    'text'                => $translation->text,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ])->all());
            }
            DB::table('lgt_publications')->where('locale', $locale)->where('state', 'published')->where('id', '!=', $publicationId)->update(['state' => 'superseded', 'updated_at' => now()]);

            return ['id' => $publicationId, 'locale' => $locale, 'revision' => $revision, 'coverage' => $coverage];
        });
    }


    /**
     * One explicit admin action: approve only machine translations that
     * passed every automatic quality check, then publish an immutable snapshot.
     * Flagged results remain manual-review-only; source-version checks are
     * re-applied inside TranslationLibrary::review() under row locks.
     * @return array<string,mixed>
     */
    public function publishReady(string $locale, ?int $actorId, TranslationLibrary $library): array
    {
        $locale = TranslationSettings::locale($locale);

        return DB::transaction(function () use ($locale, $actorId, $library): array {
            $eligible = DB::table('lgt_translations as t')
                ->join('lgt_entries as e', 'e.id', '=', 't.entry_id')
                ->where('t.locale', $locale)
                ->where('t.status', TranslationLibrary::TRANSLATED)
                ->whereNull('t.quality_code')
                ->whereColumn('t.source_hash', 'e.source_hash')
                ->where('e.is_public', true)->where('e.is_tombstoned', false)
                ->whereNotNull('t.text')
                ->orderBy('t.id')->limit(5001)->get(['t.id', 't.version']);
            if ($eligible->count() > 5000) {
                throw ValidationException::withMessages([
                    'locale' => '一次可自动审核的条目超过 5000 条。请按语言和批次人工处理后再发布。',
                ]);
            }
            $approved = 0;
            foreach ($eligible as $row) {
                $library->review((int) $row->id, (int) $row->version, true, $actorId, 'auto_basic_check');
                $approved++;
            }
            $publication = $this->publish($locale, $actorId);
            $needsReview = DB::table('lgt_translations')->where('locale', $locale)
                ->where('status', TranslationLibrary::NEEDS_REVIEW)->count();

            return ['publication' => $publication, 'auto_approved' => $approved,
                'needs_manual_review' => $needsReview];
        });
    }

    /** @return array{id:int,locale:string,revision:int,coverage:array<string,int>} */
    public function rollback(string $locale, int $publicationId, ?int $actorId): array
    {
        $locale = TranslationSettings::locale($locale);

        return DB::transaction(function () use ($locale, $publicationId, $actorId): array {
            $source = DB::table('lgt_publications')->where('id', $publicationId)->where('locale', $locale)->lockForUpdate()->first();
            if ($source === null || ! in_array($source->state, ['published', 'superseded'], true)) {
                throw new RuntimeException('PUBLICATION_NOT_RECOVERABLE');
            }
            $revision = (int) DB::table('lgt_publications')->where('locale', $locale)->lockForUpdate()->max('revision') + 1;
            $targetId = DB::table('lgt_publications')->insertGetId([
                'locale'            => $locale,
                'revision'          => $revision,
                'state'             => 'published',
                'published_by'      => $actorId,
                'published_at'      => now(),
                'rollback_of'       => $source->id,
                'coverage_snapshot' => $source->coverage_snapshot,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
            foreach (DB::table('lgt_publication_items')->where('publication_id', $source->id)->orderBy('id')->get()->chunk(250) as $chunk) {
                DB::table('lgt_publication_items')->insert($chunk->map(static fn (object $item): array => [
                    'publication_id'      => $targetId,
                    'translation_id'      => $item->translation_id,
                    'translation_version' => $item->translation_version,
                    'source_hash'         => $item->source_hash,
                    'text'                => $item->text,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ])->all());
            }
            DB::table('lgt_publications')->where('locale', $locale)->where('state', 'published')->where('id', '!=', $targetId)->update(['state' => 'superseded', 'updated_at' => now()]);

            return ['id' => $targetId, 'locale' => $locale, 'revision' => $revision, 'coverage' => (array) json_decode((string) $source->coverage_snapshot, true)];
        });
    }

    /**
     * Compare the exact, versioned text snapshot. Keep the comparison
     * streaming so a large catalog does not double the database result in RAM.
     * @param \Illuminate\Support\Collection<int, object> $translations
     */
    private function samePublishedSnapshot(int $publicationId, $translations): bool
    {
        if (DB::table('lgt_publication_items')->where('publication_id', $publicationId)->count() !== $translations->count()) {
            return false;
        }
        $rows = DB::table('lgt_publication_items')->where('publication_id', $publicationId)
            ->orderBy('translation_id')->cursor();
        $index = 0;
        foreach ($rows as $item) {
            $translation = $translations[$index] ?? null;
            if ($translation === null
                || (int) $item->translation_id !== (int) $translation->id
                || (int) $item->translation_version !== (int) $translation->version
                || ! hash_equals((string) $item->source_hash, (string) $translation->source_hash)
                || ! hash_equals((string) $item->text, (string) $translation->text)) {
                return false;
            }
            $index++;
        }

        return $index === $translations->count();
    }

    public function currentRevision(string $locale): ?int
    {
        return DB::table('lgt_publications')->where('locale', TranslationSettings::locale($locale))
            ->where('state', 'published')->orderByDesc('revision')->orderByDesc('id')->value('revision');
    }
}
