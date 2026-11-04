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
            $token          = '__LGT_' . $nonce . '_' . count($tokens) . '_' . substr(hash('sha256', $match[0]), 0, 8) . '__';
            $tokens[$token] = $replacements[$match[0]] ?? $match[0];

            return $token;
        }, $text);

        return ['text' => $masked, 'tokens' => $tokens];
    }

    public static function restore(string $translated, array $tokens): string
    {
        foreach ($tokens as $token => $value) {
            if (substr_count($translated, $token) !== 1) {
                throw new InvalidArgumentException('PROTECTED_TOKEN_CHANGED');
            }
        }
        $unknownTokenScan = str_replace(array_keys($tokens), '', $translated);
        if (preg_match('/__LGT_[A-Fa-f0-9]{24}_[0-9]+_[A-Fa-f0-9]{8}__/', $unknownTokenScan) === 1) {
            throw new InvalidArgumentException('UNKNOWN_PROTECTED_TOKEN');
        }
        if (trim($translated) === '' || str_contains($translated, '<') || str_contains($translated, '>')) {
            throw new InvalidArgumentException('UNSAFE_TRANSLATION');
        }

        return strtr($translated, $tokens);
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
