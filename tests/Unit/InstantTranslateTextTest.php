<?php

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Plugin\LongosInstantTranslate\Services\TextContract;
use Plugin\LongosInstantTranslate\Services\TranslationQualityGate;

class InstantTranslateTextTest extends TestCase
{
    public function test_utf16_and_bytes_are_measured_independently(): void
    {
        self::assertSame(['bytes' => 8, 'codepoints' => 3, 'utf16' => 4], TextContract::units('A中😀'));
        $source   = str_repeat('中😀é', 2000);
        $segments = TextContract::segments($source);
        self::assertSame($source, implode('', $segments));
        foreach ($segments as $segment) {
            self::assertLessThanOrEqual(4500, TextContract::units($segment)['bytes']);
            self::assertLessThanOrEqual(4500, TextContract::units($segment)['utf16']);
        }
    }

    public function test_segment_limits_reject_an_oversized_atomic_token_even_after_a_valid_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TextContract::segments('ok' . str_repeat('X', 20), 8, 8, [str_repeat('X', 20)]);
    }

    public function test_protected_tokens_roundtrip_and_are_unpredictable(): void
    {
        $source = 'Hello :name, $39.99, 30%, https://example.com and shop@example.com';
        $first  = TextContract::protect($source);
        self::assertSame($source, TextContract::restore($first['text'], $first['tokens']));
        self::assertNotSame($first['text'], TextContract::protect($source)['text']);
        $this->expectException(InvalidArgumentException::class);
        TextContract::restore('Broken translation', $first['tokens']);
    }

    public function test_provider_typography_changes_to_an_intact_marker_are_canonicalised_safely(): void
    {
        $protected = TextContract::protect('Hello :name, your price is $39.99.');
        $token     = array_key_first($protected['tokens']);
        self::assertNotNull($token);
        self::assertMatchesRegularExpression('/^LGTX([a-f0-9]{24})X(\d+)X([a-f0-9]{8})$/i', $token);
        preg_match('/^LGTX([a-f0-9]{24})X(\d+)X([a-f0-9]{8})$/i', $token, $parts);

        // This mirrors provider output that has made the opaque token more
        // readable but has not changed its nonce, ordinal or source digest.
        $variant    = 'lgtx ' . strtoupper($parts[1]) . ' - x ' . $parts[2] . ' _ x ' . strtoupper($parts[3]);
        $translated = str_replace($token, $variant, $protected['text']);

        self::assertSame('Hello :name, your price is $39.99.', TextContract::restore($translated, $protected['tokens']));
    }

    public function test_provider_token_reflow_preserves_only_an_exact_request_bound_marker(): void
    {
        $protected = TextContract::protect('Your protected amount is $19.99.');
        $token     = array_key_first($protected['tokens']);
        self::assertIsString($token);

        // A soft line break / zero-width joiner inside a token is a renderer
        // artefact, not a semantic change. The nonce, ordinal and digest are
        // still present in order, so restoration remains safe.
        $reflowed = implode("\u{200B}-", str_split($token));
        self::assertSame('Votre montant protégé est $19.99.', TextContract::restore('Votre montant protégé est ' . $reflowed . '.', $protected['tokens']));
    }

    public function test_changed_marker_nonce_or_digest_remains_a_hard_failure(): void
    {
        $protected = TextContract::protect('Hello :name');
        $token     = array_key_first($protected['tokens']);
        self::assertNotNull($token);
        $lastDigestCharacter = substr($token, -1);
        $changed             = substr($token, 0, -1) . ($lastDigestCharacter === '0' ? '1' : '0');

        $this->expectException(InvalidArgumentException::class);
        TextContract::restore(str_replace($token, $changed, $protected['text']), $protected['tokens']);
    }

    public function test_external_html_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TextContract::restore('<img src=x onerror=alert(1)>', []);
    }

    public function test_plain_comparison_symbols_are_not_mistaken_for_markup(): void
    {
        $text = 'Fits displays > 6.1 in and cases < 2 mm thick.';

        self::assertSame($text, TextContract::restore($text, []));

        self::assertSame($text, TextContract::assertSafePlainText($text));
    }

    public function test_glossary_replacements_are_restored_without_sending_terms_to_a_provider(): void
    {
        $protected = TextContract::protect('LONGOS ships the Atlas Case worldwide.', ['LONGOS' => '朗格斯', 'Atlas Case' => '阿特拉斯保护壳']);

        self::assertStringNotContainsString('LONGOS', $protected['text']);
        self::assertStringNotContainsString('Atlas Case', $protected['text']);
        self::assertSame('朗格斯 ships the 阿特拉斯保护壳 worldwide.', TextContract::restore($protected['text'], $protected['tokens']));
    }

    public function test_translation_plan_keeps_sensitive_and_commercial_tokens_local(): void
    {
        $parts = TextContract::translationPlan(
            'LONGOS sends :name a $39.99 offer at https://example.com.',
            ['LONGOS' => '朗格斯'],
        );
        $providerText = implode('', array_column(array_values(array_filter($parts, static fn (array $part): bool => $part['translate'])), 'text'));
        $assembled    = implode('', array_column($parts, 'text'));

        self::assertStringNotContainsString('LONGOS', $providerText);
        self::assertStringNotContainsString(':name', $providerText);
        self::assertStringNotContainsString('$39.99', $providerText);
        self::assertStringNotContainsString('https://example.com', $providerText);
        self::assertSame('朗格斯 sends :name a $39.99 offer at https://example.com.', $assembled);
    }

    public function test_quality_gate_flags_only_safe_machine_output_anomalies(): void
    {
        self::assertSame('SOURCE_ECHO', TranslationQualityGate::evaluate('Enjoy free worldwide shipping on every order today', 'Enjoy free worldwide shipping on every order today'));
        self::assertSame('REPEATED_OUTPUT', TranslationQualityGate::evaluate('bonjour bonjour bonjour', 'Enjoy free worldwide shipping on every order today'));
        self::assertSame('TRUNCATED_OUTPUT', TranslationQualityGate::evaluate('好', str_repeat('A clear product promise. ', 5)));
        self::assertNull(TranslationQualityGate::evaluate('Profitez de la livraison gratuite dans le monde entier.', 'Enjoy free worldwide shipping on every order today'));
        self::assertSame(
            'TARGET_SCRIPT_MISSING',
            TranslationQualityGate::evaluate(
                'Get reliable worldwide shipping, secure checkout, and easy returns for every order today.',
                'Enjoy reliable worldwide shipping, secure checkout, and easy returns for every order today.',
                'zh-cn',
            ),
        );
        self::assertNull(TranslationQualityGate::evaluate('适用于每笔订单的可靠全球配送、安全结账和轻松退货。', 'Enjoy reliable worldwide shipping, secure checkout, and easy returns for every order today.', 'zh-cn'));
        self::assertSame(
            'TARGET_SCRIPT_MISSING',
            TranslationQualityGate::evaluate('Premium leather phone case edition', 'Premium leather phone case', 'ja'),
        );
        self::assertSame(
            'MODEL_META_OUTPUT',
            TranslationQualityGate::evaluate('Translation: Coque de téléphone premium', 'Premium leather phone case', 'fr'),
        );
        self::assertSame(
            'MODEL_META_OUTPUT',
            TranslationQualityGate::evaluate('{"translation":"Coque de téléphone"}', 'Premium leather phone case', 'fr'),
        );
        self::assertSame(
            'SOURCE_LANGUAGE_DOMINANT',
            TranslationQualityGate::evaluate(
                'Enjoy reliable worldwide shipping, secure checkout, and easy returns for every commande today.',
                'Enjoy reliable worldwide shipping, secure checkout, and easy returns for every order today.',
                'fr',
            ),
        );
        self::assertNull(TranslationQualityGate::evaluate('Coque en cuir premium avec expédition mondiale fiable et retours faciles.', 'Premium leather phone case with reliable worldwide shipping and easy returns.', 'fr'));
    }
}
