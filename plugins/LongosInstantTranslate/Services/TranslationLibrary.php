<?php

namespace Plugin\LongosInstantTranslate\Services;

use Illuminate\Support\Facades\DB;

final class TranslationLibrary
{
    public const SOURCE_LOCALE = 'en';

    public static function normalize(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            return '';
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return mb_substr(trim($text), 0, 5000);
    }

    public static function sourceHash(string $text): string
    {
        return hash('sha256', "lit-source-v1\0" . self::normalize($text));
    }

    public static function localeForLanguage(string $language): ?string
    {
        return match ($language) {
            'english'             => 'en',
            'japanese'            => 'ja',
            'deutsch'             => 'de',
            'french'              => 'fr',
            'spanish'             => 'es',
            'italian'             => 'it',
            'korean'              => 'ko',
            'portuguese'          => 'pt',
            'polish'              => 'pl',
            'dutch'               => 'nl',
            'chinese_traditional' => 'zh-tw',
            'chinese_simplified'  => 'zh-cn',
            'arabic'              => 'ar',
            'russian'             => 'ru',
            'vietnamese'          => 'vi',
            'indonesian'          => 'id',
            'malay'               => 'ms',
            'hindi'               => 'hi',
            default               => null,
        };
    }

    /** @param list<string> $texts @return array<string, string> normalized source => translation */
    public function lookup(string $targetLocale, array $texts): array
    {
        if (! LibrarySchema::ready()) {
            return [];
        }

        $normalized = [];
        foreach ($texts as $text) {
            $value = self::normalize($text);
            if ($value !== '') {
                $normalized[self::sourceHash($value)] = $value;
            }
        }
        if ($normalized === []) {
            return [];
        }

        return DB::table('lit_translation_entries')
            ->where('source_locale', self::SOURCE_LOCALE)
            ->where('target_locale', $targetLocale)
            ->where('status', 'ready')
            ->whereIn('source_hash', array_keys($normalized))
            ->get(['source_hash', 'translated_text'])
            ->reduce(function (array $carry, object $row) use ($normalized): array {
                $translation = trim((string) $row->translated_text);
                if ($translation !== '' && isset($normalized[$row->source_hash])) {
                    $carry[$normalized[$row->source_hash]] = $translation;
                }

                return $carry;
            }, []);
    }

    /** @param list<string> $targetLocales */
    public function queue(string $text, string $kind, array $targetLocales): int
    {
        $source = self::normalize($text);
        if ($source === '' || mb_strlen($source, 'UTF-8') < 2 || preg_match('/\p{L}/u', $source) !== 1) {
            return 0;
        }

        try {
            TextContract::assertSafePlainText($source);
        } catch (\Throwable) {
            return 0;
        }

        $created = 0;
        foreach (array_unique($targetLocales) as $targetLocale) {
            if ($targetLocale === self::SOURCE_LOCALE || preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $targetLocale) !== 1) {
                continue;
            }
            $created += DB::table('lit_translation_entries')->insertOrIgnore([
                'source_locale' => self::SOURCE_LOCALE,
                'target_locale' => $targetLocale,
                'source_hash'   => self::sourceHash($source),
                'source_text'   => $source,
                'source_kind'   => mb_substr($kind, 0, 32),
                'status'        => 'pending',
                'last_seen_at'  => now(),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
            DB::table('lit_translation_entries')
                ->where('source_locale', self::SOURCE_LOCALE)
                ->where('target_locale', $targetLocale)
                ->where('source_hash', self::sourceHash($source))
                ->update(['last_seen_at' => now(), 'source_kind' => mb_substr($kind, 0, 32)]);
        }

        return $created;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        if (! LibrarySchema::ready()) {
            return ['total' => 0, 'ready' => 0, 'pending' => 0, 'review' => 0, 'failed' => 0];
        }

        $counts = DB::table('lit_translation_entries')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total'   => (int) $counts->sum(),
            'ready'   => (int) ($counts['ready'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0),
            'review'  => (int) ($counts['review'] ?? 0),
            'failed'  => (int) ($counts['failed'] ?? 0),
        ];
    }

    public function nextPendingDelay(int $defaultSeconds): ?int
    {
        if (! LibrarySchema::ready()) {
            return null;
        }
        $next = DB::table('lit_translation_entries')->where('status', 'pending')
            ->orderByRaw('CASE WHEN next_attempt_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('next_attempt_at')->value('next_attempt_at');
        if ($next === null) {
            return DB::table('lit_translation_entries')->where('status', 'pending')->exists()
                ? max(5, $defaultSeconds) : null;
        }
        $retryAt = \Illuminate\Support\Carbon::parse($next);
        $seconds = $retryAt->isFuture() ? now()->diffInSeconds($retryAt) : 0;

        return max(max(5, $defaultSeconds), min(3600, (int) ceil($seconds)));
    }

    public function resumeDeferred(): int
    {
        if (! LibrarySchema::ready()) {
            return 0;
        }

        return DB::table('lit_translation_entries')
            ->where('status', 'pending')
            ->whereIn('error_code', ['NO_VERIFIED_PROVIDER', 'NO_SUPPORTED_PROVIDER', 'PROVIDER_BUSY'])
            ->update([
                'error_code'      => null,
                'locked_at'       => null,
                'next_attempt_at' => null,
                'updated_at'      => now(),
            ]);
    }
}
