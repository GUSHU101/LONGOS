<?php

namespace Plugin\LongosGlobalTranslate\Services;

use InvalidArgumentException;

final class TextContract
{
    public static function units(string $text): array
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('INVALID_UTF8');
        }
        $utf16 = iconv('UTF-8', 'UTF-16LE', $text);
        if ($utf16 === false) {
            throw new InvalidArgumentException('INVALID_UTF16');
        }

        return ['bytes' => strlen($text), 'codepoints' => mb_strlen($text, 'UTF-8'), 'utf16' => intdiv(strlen($utf16), 2)];
    }

    /** @param list<string> $atomicTokens */
    public static function segments(string $text, int $bytes = 4500, int $utf16 = 4500, array $atomicTokens = []): array
    {
        self::units($text);
        if ($bytes < 4 || $utf16 < 2) {
            throw new InvalidArgumentException('INVALID_SEGMENT_LIMIT');
        }
        $segments = [];
        $current = '';
        $currentBytes = 0;
        $currentUtf16 = 0;
        foreach (self::atomicParts($text, $atomicTokens) as $part) {
            $partUnits = self::units($part);
            if ($partUnits['bytes'] > $bytes || $partUnits['utf16'] > $utf16) {
                throw new InvalidArgumentException('ATOMIC_TOKEN_TOO_LARGE');
            }
            if ($current !== '' && ($currentBytes + $partUnits['bytes'] > $bytes
                || $currentUtf16 + $partUnits['utf16'] > $utf16)) {
                $segments[] = $current;
                $current = '';
                $currentBytes = 0;
                $currentUtf16 = 0;
            }
            $current .= $part;
            $currentBytes += $partUnits['bytes'];
            $currentUtf16 += $partUnits['utf16'];
        }
        if ($current !== '') {
            $segments[] = $current;
        }

        return $segments;
    }

    /**
     * @param list<string>|array<string, string> $protectedTerms
     *
     * Associative input is a tenant glossary: its key is the exact source
     * term and its value is the required target-language replacement. A
     * numeric list keeps a term unchanged (useful for brands, SKU fragments,
     * and legal labels). Neither the source term nor the replacement is sent
     * to an external provider.
     */
    public static function protect(string $text, array $protectedTerms = []): array
    {
        $tokens       = [];
        $nonce        = bin2hex(random_bytes(12));
        $replacements = [];
        foreach ($protectedTerms as $term => $replacement) {
            $source = trim((string) (is_int($term) ? $replacement : $term));
            if ($source === '') {
                continue;
            }
            $target                = is_int($term) ? $source : trim((string) $replacement);
            $replacements[$source] = $target !== '' ? $target : $source;
        }
        $quotedTerms = array_values(array_map(static fn (string $term): string => preg_quote($term, '~'), array_keys($replacements)));
        $termPattern = $quotedTerms === [] ? '(?!)' : '(?:' . implode('|', $quotedTerms) . ')';
        $masked      = preg_replace_callback('~' . $termPattern . '|https?://[^\s<>]+|[\w.+-]+@[\w.-]+\.[a-z]{2,}|\{\{[^{}]+\}\}|\{[\w]+\}|(?<!\w):[a-zA-Z_]+|%[sd]|[$€£]?\d+(?:[.,]\d+)*(?:%|\s?(?:kg|cm|mm|GB|TB))?~u', function (array $match) use (&$tokens, $nonce, $replacements): string {
            // Bracketed, delimiter-separated markers survive the common
            // normalisation performed by translation providers much better
            // than a run of underscores. The nonce and digest still make a
            // marker unguessable and bind it to this exact source fragment.
            $token          = '[[LGT:' . $nonce . ':' . count($tokens) . ':' . substr(hash('sha256', $match[0]), 0, 8) . ']]';
            $tokens[$token] = $replacements[$match[0]] ?? $match[0];

            return $token;
        }, $text);

        return ['text' => $masked, 'tokens' => $tokens];
    }

    public static function restore(string $translated, array $tokens): string
    {
        // Providers occasionally add spaces, replace underscores with dashes,
        // or change the case of an otherwise intact marker. That is a
        // formatting-only change, not a source-integrity failure. Canonicalise
        // only markers that retain the per-request nonce, ordinal *and* digest;
        // a missing, duplicated or materially changed marker is still rejected.
        $translated = self::normaliseProviderTokenVariants($translated, $tokens);
        foreach ($tokens as $token => $value) {
            if (substr_count($translated, $token) !== 1) {
                throw new InvalidArgumentException('PROTECTED_TOKEN_CHANGED');
            }
        }
        $unknownTokenScan = str_replace(array_keys($tokens), '', $translated);
        if (preg_match('~(?:__|\[\[)LGT(?:_|:)[A-Fa-f0-9]{24}(?:_|:)[0-9]+(?:_|:)[A-Fa-f0-9]{8}(?:__|\]\])~i', $unknownTokenScan) === 1) {
            throw new InvalidArgumentException('UNKNOWN_PROTECTED_TOKEN');
        }
        if (trim($translated) === '' || str_contains($translated, '<') || str_contains($translated, '>')) {
            throw new InvalidArgumentException('UNSAFE_TRANSLATION');
        }

        return strtr($translated, $tokens);
    }

    /**
     * Restore a marker to its exact canonical spelling when a provider made a
     * harmless typography adjustment. This accepts neither a different nonce
     * nor a different digest, so it cannot turn a provider-created value into
     * a valid protected token.
     *
     * @param array<string,string> $tokens
     */
    private static function normaliseProviderTokenVariants(string $translated, array $tokens): string
    {
        foreach (array_keys($tokens) as $token) {
            $parts = self::tokenParts($token);
            if ($parts === null) {
                continue;
            }
            [$nonce, $ordinal, $digest] = $parts;
            $separator = '[\\s_:\\-：]*';
            $pattern = '~(?<![[:alnum:]])(?:__|\[\[|\[)?' . $separator
                . 'LGT' . $separator . preg_quote($nonce, '~') . $separator
                . preg_quote($ordinal, '~') . $separator . preg_quote($digest, '~')
                . $separator . '(?:__|\]\]|\])?(?![[:alnum:]])~iu';
            $candidate = preg_replace($pattern, $token, $translated, -1, $count);
            if ($candidate !== null && $count === 1) {
                $translated = $candidate;
            }
        }

        return $translated;
    }

    /** @return array{0:string,1:string,2:string}|null */
    private static function tokenParts(string $token): ?array
    {
        if (preg_match('~^(?:__LGT_|\[\[LGT:)([a-f0-9]{24})[_:]([0-9]+)[_:]([a-f0-9]{8})(?:__|\]\])$~i', $token, $matches) !== 1) {
            return null;
        }

        return [$matches[1], $matches[2], $matches[3]];
    }

    /** @param list<string> $atomicTokens @return list<string> */
    private static function atomicParts(string $text, array $atomicTokens): array
    {
        if ($atomicTokens === []) {
            return preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        usort($atomicTokens, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $pattern = '~(' . implode('|', array_map(static fn (string $token): string => preg_quote($token, '~'), $atomicTokens)) . ')~u';
        $parts   = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $result  = [];
        foreach ($parts as $part) {
            if (in_array($part, $atomicTokens, true)) {
                $result[] = $part;

                continue;
            }
            foreach (preg_split('//u', $part, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
                $result[] = $character;
            }
        }

        return $result;
    }
}
