<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
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
            $quality     = TranslationQualityGate::evaluate($text, (string) $entry->source_text);
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
                'text'         => $text,
                'status'       => self::NEEDS_REVIEW,
                'locked'       => $lock || (bool) $translation->locked,
                'locked_by'    => $lock ? $actorId : $translation->locked_by,
                'locked_at'    => $lock ? now() : $translation->locked_at,
                'quality_code' => TranslationQualityGate::evaluate($text, (string) $entry->source_text),
                'version'      => $nextVersion,
                'updated_at'   => now(),
            ]);
            $this->version($translationId, $nextVersion, (string) $entry->source_hash, $text, self::NEEDS_REVIEW, 'human_edit', $actorId);

            return (array) DB::table('lgt_translations')->where('id', $translationId)->first();
        });
    }

    /** @return array<string, mixed> */
    public function review(int $translationId, int $expectedVersion, bool $approved, ?int $actorId): array
    {
        return DB::transaction(function () use ($translationId, $expectedVersion, $approved, $actorId): array {
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
            $this->version($translationId, $nextVersion, (string) $entry->source_hash, (string) $translation->text, $status, $approved ? 'approved' : 'returned_for_review', $actorId);

            return (array) DB::table('lgt_translations')->where('id', $translationId)->first();
        });
    }

    private function validatedText(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8') || trim($text) === '' || mb_strlen($text, 'UTF-8') > 100_000) {
            throw new InvalidArgumentException('INVALID_TRANSLATION_TEXT');
        }
        // Rich text reconstruction is handled separately. A raw provider
        // response is never permitted to introduce markup in this text field.
        if (str_contains($text, '<') || str_contains($text, '>')) {
            throw new InvalidArgumentException('UNSAFE_TRANSLATION');
        }

        return trim($text);
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
