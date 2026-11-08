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
