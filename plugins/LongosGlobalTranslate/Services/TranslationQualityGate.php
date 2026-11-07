<?php

namespace Plugin\LongosGlobalTranslate\Services;

/**
 * Conservative, language-neutral checks for machine-translation output.
 *
 * This is deliberately a review gate rather than an attempt to pretend that
 * a heuristic can prove semantic correctness. A clean result may enter the
 * configured automatic publication flow; a flagged result is always held for
 * review. The checks below catch the failure modes that are safe to detect
 * without pretending to fully understand every target language: source
 * echoes, pathological repetition, script mismatch, and extreme truncation
 * or expansion.
 */
final class TranslationQualityGate
{
    public static function evaluate(string $translation, string $source, ?string $targetLocale = null): ?string
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
        if (self::isMissingRequiredTargetScript($translation, $source, $targetLocale)) {
            return 'TARGET_SCRIPT_MISSING';
        }

        return null;
    }

    /**
     * Detect an especially harmful class of false success: a long English
     * storefront sentence returned unchanged for a script-based locale. This
     * is intentionally narrow; names such as "iPhone 16 Pro Max" are allowed
     * to remain unchanged, and Latin-script locales are evaluated only by the
     * language-neutral checks above. This is a publish gate, never a claim of
     * semantic proof.
     */
    private static function isMissingRequiredTargetScript(string $translation, string $source, ?string $targetLocale): bool
    {
        $targetLocale = strtolower(str_replace('_', '-', trim((string) $targetLocale)));
        $requiredScript = match ($targetLocale) {
            'zh-cn', 'zh-tw' => '\\p{Han}',
            'ja' => '\\p{Han}|\\p{Hiragana}|\\p{Katakana}',
            'ko' => '\\p{Hangul}',
            'ru' => '\\p{Cyrillic}',
            'ar' => '\\p{Arabic}',
            'th' => '\\p{Thai}',
            default => null,
        };
        if ($requiredScript === null || mb_strlen(trim($source), 'UTF-8') < 40) {
            return false;
        }

        preg_match_all('/[A-Za-z]{2,}/', $source, $words);
        // Require natural-language-like English before rejecting output. This
        // keeps part numbers and all-brand titles out of the heuristic.
        if (count(array_unique(array_map('strtolower', $words[0] ?? []))) < 5) {
            return false;
        }

        return preg_match('/(?:' . $requiredScript . ')/u', $translation) !== 1;
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
