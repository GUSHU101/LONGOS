<?php

namespace Plugin\LongosGlobalTranslate\Services;

/**
 * Conservative, language-neutral checks for machine-translation output.
 *
 * This is deliberately a review gate rather than an attempt to pretend that
 * a heuristic can prove semantic correctness. Every machine result still
 * needs an administrator's approval before it can appear in a publication.
 * The checks below catch the failure modes that are safe to detect without
 * knowing the target language: source echoes, pathological repetition, and
 * extreme truncation or expansion.
 */
final class TranslationQualityGate
{
    public static function evaluate(string $translation, string $source): ?string
    {
        $sourceLength      = mb_strlen(trim($source), 'UTF-8');
        $translationLength = mb_strlen(trim($translation), 'UTF-8');

        if ($translationLength === 0) {
            return 'EMPTY_OUTPUT';
        }
        if (self::isRepeated($translation)) {
            return 'REPEATED_OUTPUT';
        }
        if ($sourceLength > 50 && $translationLength > $sourceLength * 4) {
            return 'LENGTH_OUTLIER';
        }
        // A very long source reduced to a handful of glyphs is almost
        // certainly a partial response. The threshold is intentionally low
        // enough to avoid flagging concise CJK translations.
        if ($sourceLength > 80 && $translationLength < 4) {
            return 'TRUNCATED_OUTPUT';
        }
        if (self::isLikelySourceEcho($translation, $source)) {
            return 'SOURCE_ECHO';
        }

        return null;
    }

    private static function isLikelySourceEcho(string $translation, string $source): bool
    {
        if (self::canonical($translation) !== self::canonical($source) || mb_strlen($source, 'UTF-8') < 24) {
            return false;
        }

        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($source, 'UTF-8'), $matches);
        $words = array_values(array_unique(array_filter($matches[0] ?? [], static fn (string $word): bool => mb_strlen($word, 'UTF-8') > 1)));

        // Brand names and short product identifiers can legitimately be kept
        // unchanged. Flag only natural-language source strings with at least
        // three meaningful terms.
        return count($words) >= 3;
    }

    private static function isRepeated(string $text): bool
    {
        $compact = preg_replace('/\s+/u', '', trim($text)) ?? '';
        if (mb_strlen($compact, 'UTF-8') < 12) {
            return false;
        }

        return preg_match('/^(.{1,80}?)\1{2,}$/us', $compact) === 1;
    }

    private static function canonical(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';
    }
}
