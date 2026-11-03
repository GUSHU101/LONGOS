<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Illuminate\Support\Facades\DB;
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

    public function currentRevision(string $locale): ?int
    {
        return DB::table('lgt_publications')->where('locale', TranslationSettings::locale($locale))->where('state', 'published')->value('revision');
    }
}
