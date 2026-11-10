<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class TranslationLibrary
{
    public const PENDING = 'pending';

    public const TRANSLATED = 'translated';

    public const NEEDS_REVIEW = 'needs_review';

    public const APPROVED = 'approved';

    public const STALE = 'stale';

    /** @return array<string,int|string> */
    public function resetLocale(string $locale, ?int $actorId): array
    {
        $result = $this->resetLocales([$locale], $actorId);

        return $result['locales'][0];
    }

    /**
     * Reset several languages atomically. Keep source records, job/attempt
     * evidence, billing and audit history. No deletion starts if an eligible
     * job might resume or an already-cancelled worker still owns a claim.
     *
     * @param list<string> $locales
     * @return array{locales:list<array<string,int|string>>,totals:array<string,int>}
     */
    public function resetLocales(array $locales, ?int $actorId): array
    {
        $locales = array_values(array_unique(array_map(
            static fn (string $locale): string => TranslationSettings::locale($locale), $locales
        )));
        if ($locales === [] || count($locales) > 24) {
            throw new RuntimeException('INVALID_LOCALE_SELECTION');
        }
        if (in_array('en', $locales, true)) {
            throw new RuntimeException('SOURCE_LOCALE_CANNOT_BE_RESET');
        }

        return DB::transaction(function () use ($locales, $actorId): array {
            TranslationMaintenanceGuard::lockLocales($locales);
            $this->assertLocalesIdle($locales);
            $rows   = [];
            $totals = ['translations' => 0, 'versions' => 0, 'publications' => 0, 'published_items' => 0];
            foreach ($locales as $locale) {
                $translations = DB::table('lgt_translations')->where('locale', $locale);
                $publications = DB::table('lgt_publications')->where('locale', $locale);
                $counts       = [
                    'translations'    => (clone $translations)->count(),
                    'versions'        => DB::table('lgt_translation_versions')->whereIn('translation_id', (clone $translations)->select('id'))->count(),
                    'publications'    => (clone $publications)->count(),
                    'published_items' => DB::table('lgt_publication_items')->whereIn('publication_id', (clone $publications)->select('id'))->count(),
                ];
                // SQL subqueries keep deletion bounded even for large catalogs.
                DB::table('lgt_publication_items')->whereIn('publication_id', (clone $publications)->select('id'))->delete();
                DB::table('lgt_publications')->where('locale', $locale)->delete();
                DB::table('lgt_translation_versions')->whereIn('translation_id', (clone $translations)->select('id'))->delete();
                DB::table('lgt_translations')->where('locale', $locale)->delete();
                $rows[] = ['locale' => $locale, ...$counts];
                foreach ($counts as $name => $count) {
                    $totals[$name] += $count;
                }
            }
            $this->auditDeletion('translation_library_reset', $actorId, ['locales' => $rows], ['totals' => $totals]);

            return ['locales' => $rows, 'totals' => $totals];
        }, 3);
    }

    /**
     * Explicit erasure also removes selected rows from all frozen releases:
     * otherwise a storefront or historical rollback could resurrect them.
     * Other translations and job/usage evidence remain unchanged.
     *
     * @param list<int> $ids
     * @return array{translations:int,versions:int,published_items:int,locales:list<string>}
     */
    public function deleteTranslations(array $ids, ?int $actorId): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] || count($ids) > 100 || min($ids) < 1) {
            throw new RuntimeException('INVALID_TRANSLATION_SELECTION');
        }

        return DB::transaction(function () use ($ids, $actorId): array {
            $locales = DB::table('lgt_translations')->whereIn('id', $ids)->pluck('locale')->unique()->values()->all();
            TranslationMaintenanceGuard::lockLocales($locales);
            $rows = DB::table('lgt_translations')->whereIn('id', $ids)->lockForUpdate()->get(['id', 'locale']);
            if ($rows->count() !== count($ids)) {
                throw new RuntimeException('TRANSLATION_SELECTION_CHANGED');
            }
            $lockedLocales = $rows->pluck('locale')->unique()->values()->all();
            sort($locales, SORT_STRING);
            sort($lockedLocales, SORT_STRING);
            if ($lockedLocales !== $locales) {
                throw new RuntimeException('TRANSLATION_SELECTION_CHANGED');
            }
            $this->assertLocalesIdle($locales);
            $publishedItems = DB::table('lgt_publication_items')->whereIn('translation_id', $ids)->delete();
            $versions       = DB::table('lgt_translation_versions')->whereIn('translation_id', $ids)->delete();
            $deleted        = DB::table('lgt_translations')->whereIn('id', $ids)->delete();
            $result         = [
                'translations'    => $deleted, 'versions' => $versions,
                'published_items' => $publishedItems, 'locales' => $locales,
            ];
            $this->auditDeletion('translation_entries_deleted', $actorId, ['ids' => $ids, 'locales' => $locales], $result);

            return $result;
        }, 3);
    }

    /** @param list<string> $locales */
    private function assertLocalesIdle(array $locales): void
    {
        $jobs = DB::table('lgt_jobs')->whereIn('status', ['planned', 'queued', 'scanning', 'running', 'pausing', 'paused'])
            ->lockForUpdate()->get(['id', 'target_locales']);
        foreach ($jobs as $job) {
            $targets = json_decode((string) $job->target_locales, true);
            if (is_array($targets) && array_intersect($locales, $targets) !== []) {
                throw new RuntimeException('LOCALE_HAS_ACTIVE_JOB');
            }
        }
        if (DB::table('lgt_job_items')->whereIn('target_locale', $locales)->where('status', 'claimed')->exists()) {
            throw new RuntimeException('LOCALE_HAS_INFLIGHT_REQUEST');
        }
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private function auditDeletion(string $action, ?int $actorId, array $before, array $after): void
    {
        DB::table('lgt_audit_logs')->insert([
            'actor_id'   => $actorId, 'action' => $action, 'subject_type' => 'translation_library',
            'subject_id' => null, 'before' => json_encode($before, JSON_UNESCAPED_UNICODE),
            'after'      => json_encode($after, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    public function saveMachineResult(int $entryId, string $locale, string $sourceHash, string $text, string $provider, ?int $accountId): array
    {
        $locale = TranslationSettings::locale($locale);
        $text   = $this->validatedText($text);

        return DB::transaction(function () use ($entryId, $locale, $sourceHash, $text, $provider, $accountId): array {
            $entry = DB::table('lgt_entries')->where('id', $entryId)->lockForUpdate()->first();
            if ($entry === null || ! hash_equals((string) $entry->source_hash, $sourceHash)) {
                throw new RuntimeException('STALE_SOURCE');
            }
            DB::table('lgt_translations')->insertOrIgnore([
                'entry_id'    => $entryId,
                'locale'      => $locale,
                'source_hash' => $sourceHash,
                'text'        => null,
                'status'      => self::PENDING,
                'locked'      => false,
                'version'     => 1,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
            $translation = DB::table('lgt_translations')->where('entry_id', $entryId)->where('locale', $locale)->lockForUpdate()->first();
            if ($translation === null) {
                throw new RuntimeException('TRANSLATION_ROW_UNAVAILABLE');
            }
            if ((bool) $translation->locked) {
                return (array) $translation;
            }
            $quality     = TranslationQualityGate::evaluate($text, (string) $entry->source_text, $locale);
            $status      = $quality === null ? self::TRANSLATED : self::NEEDS_REVIEW;
            $nextVersion = (int) $translation->version + 1;
            DB::table('lgt_translations')->where('id', $translation->id)->update([
                'source_hash'         => $sourceHash,
                'text'                => $text,
                'status'              => $status,
                'provider'            => $provider,
                'provider_account_id' => $accountId,
                'quality_code'        => $quality,
                'version'             => $nextVersion,
                'updated_at'          => now(),
            ]);
            $this->version((int) $translation->id, $nextVersion, $sourceHash, $text, $status, 'machine_result', null);

            return (array) DB::table('lgt_translations')->where('id', $translation->id)->first();
        });
    }

    /**
     * Re-run the current quality policy immediately before automatic review.
     * This protects older machine rows that were saved before a stricter gate
     * was deployed. A newly detected issue is versioned and quarantined; a
     * clean row is returned unchanged for the caller to approve.
     *
     * @return array<string, mixed>
     */
    public function recheckMachineResult(int $translationId, int $expectedVersion): array
    {
        return DB::transaction(function () use ($translationId, $expectedVersion): array {
            $translation = DB::table('lgt_translations')->where('id', $translationId)->lockForUpdate()->first();
            if ($translation === null || (int) $translation->version !== $expectedVersion) {
                throw new RuntimeException($translation === null ? 'TRANSLATION_NOT_FOUND' : 'TRANSLATION_VERSION_CONFLICT');
            }
            if ($translation->status !== self::TRANSLATED || $translation->provider                                           === null
                                                          || (bool) $translation->locked || trim((string) $translation->text) === '') {
                throw new RuntimeException('TRANSLATION_NOT_MACHINE_RECHECKABLE');
            }
            $entry = DB::table('lgt_entries')->where('id', $translation->entry_id)->first();
            if ($entry === null || ! hash_equals((string) $translation->source_hash, (string) $entry->source_hash)) {
                throw new RuntimeException('STALE_SOURCE');
            }

            $quality = TranslationQualityGate::evaluate(
                (string) $translation->text,
                (string) $entry->source_text,
                (string) $translation->locale,
            );
            if ($quality === null) {
                return (array) $translation;
            }

            $nextVersion = $expectedVersion + 1;
            DB::table('lgt_translations')->where('id', $translationId)->update([
                'status'       => self::NEEDS_REVIEW,
                'quality_code' => $quality,
                'version'      => $nextVersion,
                'updated_at'   => now(),
            ]);
            $this->version(
                $translationId,
                $nextVersion,
                (string) $entry->source_hash,
                (string) $translation->text,
                self::NEEDS_REVIEW,
                'automatic_quality_recheck',
                null,
            );

            return (array) DB::table('lgt_translations')->where('id', $translationId)->first();
        });
    }

    /** @return array<string, mixed> */
    public function edit(int $translationId, int $expectedVersion, string $text, ?int $actorId, bool $lock = false): array
    {
        $text = $this->validatedText($text);

        return DB::transaction(function () use ($translationId, $expectedVersion, $text, $actorId, $lock): array {
            $translation = DB::table('lgt_translations')->where('id', $translationId)->lockForUpdate()->first();
            if ($translation === null) {
                throw new RuntimeException('TRANSLATION_NOT_FOUND');
            }
            if ((int) $translation->version !== $expectedVersion) {
                throw new RuntimeException('TRANSLATION_VERSION_CONFLICT');
            }
            $entry = DB::table('lgt_entries')->where('id', $translation->entry_id)->first();
            if ($entry === null || ! hash_equals((string) $translation->source_hash, (string) $entry->source_hash)) {
                throw new RuntimeException('STALE_SOURCE');
            }
            $nextVersion = $expectedVersion + 1;
            DB::table('lgt_translations')->where('id', $translationId)->update([
                'text'                => $text,
                'status'              => self::NEEDS_REVIEW,
                // A manual edit is no longer machine output. Clearing the
                // attribution prevents automatic publishing from silently
                // approving a merchant-authored revision.
                'provider'            => null,
                'provider_account_id' => null,
                'locked'              => $lock || (bool) $translation->locked,
                'locked_by'           => $lock ? $actorId : $translation->locked_by,
                'locked_at'           => $lock ? now() : $translation->locked_at,
                'quality_code'        => TranslationQualityGate::evaluate($text, (string) $entry->source_text, (string) $translation->locale),
                'version'             => $nextVersion,
                'updated_at'          => now(),
            ]);
            $this->version($translationId, $nextVersion, (string) $entry->source_hash, $text, self::NEEDS_REVIEW, 'human_edit', $actorId);

            return (array) DB::table('lgt_translations')->where('id', $translationId)->first();
        });
    }

    /** @return array<string, mixed> */
    public function review(int $translationId, int $expectedVersion, bool $approved, ?int $actorId, string $approvalReason = 'approved'): array
    {
        return DB::transaction(function () use ($translationId, $expectedVersion, $approved, $actorId, $approvalReason): array {
            $translation = DB::table('lgt_translations')->where('id', $translationId)->lockForUpdate()->first();
            if ($translation === null) {
                throw new RuntimeException('TRANSLATION_NOT_FOUND');
            }
            if ((int) $translation->version !== $expectedVersion) {
                throw new RuntimeException('TRANSLATION_VERSION_CONFLICT');
            }
            $entry = DB::table('lgt_entries')->where('id', $translation->entry_id)->first();
            if ($entry === null || ! hash_equals((string) $translation->source_hash, (string) $entry->source_hash) || trim((string) $translation->text) === '') {
                throw new RuntimeException('TRANSLATION_NOT_REVIEWABLE');
            }
            $status      = $approved ? self::APPROVED : self::NEEDS_REVIEW;
            $nextVersion = $expectedVersion + 1;
            DB::table('lgt_translations')->where('id', $translationId)->update([
                'status'      => $status,
                'reviewed_by' => $actorId,
                'reviewed_at' => now(),
                'version'     => $nextVersion,
                'updated_at'  => now(),
            ]);
            $this->version($translationId, $nextVersion, (string) $entry->source_hash, (string) $translation->text, $status, $approved ? ($approvalReason === 'auto_basic_check' ? 'auto_basic_check' : 'approved') : 'returned_for_review', $actorId);

            return (array) DB::table('lgt_translations')->where('id', $translationId)->first();
        });
    }

    private function validatedText(string $text): string
    {
        // Keep the incoming and persisted-text contracts identical.  The
        // scanner has already separated public HTML into text nodes; this
        // blocks actual markup without rejecting harmless comparison signs
        // in product specifications.
        return TextContract::assertSafePlainText($text);
    }

    private function version(int $translationId, int $revision, string $sourceHash, string $text, string $status, string $reason, ?int $actorId): void
    {
        DB::table('lgt_translation_versions')->insert([
            'translation_id' => $translationId,
            'revision'       => $revision,
            'source_hash'    => $sourceHash,
            'text'           => $text,
            'status'         => $status,
            'reason'         => $reason,
            'actor_id'       => $actorId,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }
}
