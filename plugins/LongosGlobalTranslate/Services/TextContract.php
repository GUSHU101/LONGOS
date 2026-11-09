<?php

namespace Plugin\LongosGlobalTranslate\Services;

use InvalidArgumentException;

final class TextContract
{
    /**
     * Validate text that is going to cross the provider boundary or be saved
     * back into the local dictionary.  This is deliberately more precise
     * than the former "contains < or >" guard: product copy such as
     * "fits screens > 6.1 in" is plain text, while an actual HTML/active
     * markup fragment is not.
     *
     * All storefront dictionary writes are rendered through textContent, but
     * retaining this boundary prevents a provider from introducing markup if
     * a future theme accidentally weakens that contract.
     */
    public static function assertSafePlainText(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8') || trim($text) === '' || mb_strlen($text, 'UTF-8') > 100_000) {
            throw new InvalidArgumentException('INVALID_TRANSLATION_TEXT');
        }
        if (self::containsUnsafeMarkup($text)) {
            throw new InvalidArgumentException('UNSAFE_TRANSLATION');
        }

        return trim($text);
    }

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
        $segments     = [];
        $current      = '';
        $currentBytes = 0;
        $currentUtf16 = 0;
        foreach (self::atomicParts($text, $atomicTokens) as $part) {
            $partUnits = self::units($part);
            if ($partUnits['bytes'] > $bytes || $partUnits['utf16'] > $utf16) {
                throw new InvalidArgumentException('ATOMIC_TOKEN_TOO_LARGE');
            }
            if ($current !== '' && ($currentBytes + $partUnits['bytes']                  > $bytes
                || $currentUtf16                  + $partUnits['utf16']                  > $utf16)) {
                $segments[]   = $current;
                $current      = '';
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
        $replacements = self::protectedReplacements($protectedTerms);
        $masked       = preg_replace_callback(self::protectedPattern($replacements), function (array $match) use (&$tokens, $nonce, $replacements): string {
            // Translation engines routinely turn brackets, underscores and
            // punctuation into typographic variants. A single opaque ASCII
            // word has proved far more stable across all supported engines:
            // it is neither a translatable word nor markup. The nonce,
            // ordinal and digest still bind it to this exact source fragment,
            // and restore() still rejects a missing, duplicated or altered
            // marker. Example: LGTX8a…f1X0Xc8…42.
            $token          = 'LGTX' . $nonce . 'X' . count($tokens) . 'X' . substr(hash('sha256', $match[0]), 0, 8);
            $tokens[$token] = $replacements[$match[0]] ?? $match[0];

            return $token;
        }, $text);

        return ['text' => $masked, 'tokens' => $tokens];
    }

    /**
     * Split a source field before it crosses the provider boundary. Protected
     * values are replaced locally and are never embedded in an opaque marker
     * sent to an external service. This removes an entire class of provider
     * token-mutation failures while keeping the strict restore() contract for
     * historical attempts created by older plugin releases.
     *
     * @param list<string>|array<string, string> $protectedTerms
     * @return list<array{translate:bool,text:string}>
     */
    public static function translationPlan(string $text, array $protectedTerms = []): array
    {
        self::assertSafePlainText($text);
        $replacements = self::protectedReplacements($protectedTerms);
        $matches      = [];
        preg_match_all(self::protectedPattern($replacements), $text, $matches, PREG_OFFSET_CAPTURE);

        $parts  = [];
        $offset = 0;
        foreach ($matches[0] ?? [] as $match) {
            $source = (string) ($match[0] ?? '');
            $start  = (int) ($match[1] ?? 0);
            if ($start > $offset) {
                self::appendPlanPart($parts, true, substr($text, $offset, $start - $offset));
            }
            self::appendPlanPart($parts, false, $replacements[$source] ?? $source);
            $offset = $start + strlen($source);
        }
        if ($offset < strlen($text)) {
            self::appendPlanPart($parts, true, substr($text, $offset));
        }
        if ($parts === []) {
            $parts[] = ['translate' => true, 'text' => $text];
        }

        return $parts;
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
        if (preg_match('~(?:LGTX[A-Fa-f0-9]{24}X[0-9]+X[A-Fa-f0-9]{8}|(?:__|\[\[)LGT(?:_|:)[A-Fa-f0-9]{24}(?:_|:)[0-9]+(?:_|:)[A-Fa-f0-9]{8}(?:__|\]\]))~i', $unknownTokenScan) === 1) {
            throw new InvalidArgumentException('UNKNOWN_PROTECTED_TOKEN');
        }
        $translated = self::assertSafePlainText($translated);

        return strtr($translated, $tokens);
    }

    /**
     * Detect real markup rather than comparison operators.  The opening
     * angle bracket must immediately start a tag-like token; ordinary public
     * copy such as "< 2 mm" and "> 6.1 in" therefore stays translatable.
     */
    private static function containsUnsafeMarkup(string $text): bool
    {
        return preg_match(
            '~<!--|<!\\[CDATA\\[|<!DOCTYPE\\b|<\\?|<%|</?[A-Za-z][A-Za-z0-9:-]*(?:\\s+[A-Za-z_:][-A-Za-z0-9_:.]*(?:\\s*=\\s*(?:"[^"]*"|\'[^\']*\'|[^\\s"\'=<>`]+))?)*\\s*/?>~iu',
            $text,
        ) === 1;
    }

    /** @param list<string>|array<string, string> $protectedTerms @return array<string,string> */
    private static function protectedReplacements(array $protectedTerms): array
    {
        $replacements = [];
        foreach ($protectedTerms as $term => $replacement) {
            $source = trim((string) (is_int($term) ? $replacement : $term));
            if ($source === '') {
                continue;
            }
            $target                = is_int($term) ? $source : trim((string) $replacement);
            $replacements[$source] = $target !== '' ? $target : $source;
        }
        uksort($replacements, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return $replacements;
    }

    /** @param array<string,string> $replacements */
    private static function protectedPattern(array $replacements): string
    {
        $quotedTerms = array_map(static fn (string $term): string => preg_quote($term, '~'), array_keys($replacements));
        $termPattern = $quotedTerms === [] ? '(?!)' : '(?:' . implode('|', $quotedTerms) . ')';

        return '~' . $termPattern . '|(?i:https?://[^\s<>]+|[\w.+-]+@[\w.-]+\.[a-z]{2,})|\{\{[^{}]+\}\}|\{[\w]+\}|(?<!\w):[a-zA-Z_]+|%[sd]|[$€£]?\d+(?:[.,]\d+)*(?:%|\s?(?:kg|cm|mm|GB|TB))?~u';
    }

    /** @param list<array{translate:bool,text:string}> $parts */
    private static function appendPlanPart(array &$parts, bool $translate, string $text): void
    {
        if ($text === '') {
            return;
        }
        // Whitespace, punctuation, emoji and already-protected numeric
        // fragments have no linguistic content. Keeping them local reduces
        // billable calls and prevents a provider from rewriting typography.
        if ($translate && preg_match('/\p{L}/u', $text) !== 1) {
            $translate = false;
        }
        $last = array_key_last($parts);
        if ($last !== null && $parts[$last]['translate'] === $translate) {
            $parts[$last]['text'] .= $text;

            return;
        }
        $parts[] = ['translate' => $translate, 'text' => $text];
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
            // Some engines insert a soft line break, zero-width character or
            // separator *inside* a long opaque word while formatting their
            // response. Treat that as typography-only only when every literal
            // character of the request-bound marker survives in the exact
            // order. The nonce and digest remain mandatory, so this cannot
            // accept a provider-created value as a protected marker.
            $currentPattern = '~(?<![[:alnum:]])' . self::interleavedTokenPattern('LGTX' . $nonce . 'X' . $ordinal . 'X' . $digest)
                . '(?![[:alnum:]])~iu';
            $separator     = '[\\s_:\\-：]*';
            $legacyPattern = '~(?<![[:alnum:]])(?:__|\[\[|\[)?' . $separator
                . 'LGT' . $separator . preg_quote($nonce, '~') . $separator
                . preg_quote($ordinal, '~') . $separator . preg_quote($digest, '~')
                . $separator . '(?:__|\]\]|\])?(?![[:alnum:]])~iu';
            $pattern   = str_starts_with($token, 'LGTX') ? $currentPattern : $legacyPattern;
            $candidate = preg_replace($pattern, $token, $translated, -1, $count);
            if ($candidate !== null && $count === 1) {
                $translated = $candidate;
            }
        }

        return $translated;
    }

    /**
     * Match an otherwise exact opaque marker after renderer-only separators.
     * Matching is scoped to the marker, so real target-language text is never
     * normalised or rewritten.
     */
    private static function interleavedTokenPattern(string $token): string
    {
        $gap = '[\\s_\\-\\x{200B}\\x{200C}\\x{200D}\\x{FEFF}]*';

        return implode($gap, array_map(static fn (string $character): string => preg_quote($character, '~'), str_split($token)));
    }

    /** @return array{0:string,1:string,2:string}|null */
    private static function tokenParts(string $token): ?array
    {
        if (preg_match('~^(?:LGTX|(?:__LGT_|\[\[LGT:))([a-f0-9]{24})(?:X|[_:])([0-9]+)(?:X|[_:])([a-f0-9]{8})(?:__|\]\])?$~i', $token, $matches) !== 1) {
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
