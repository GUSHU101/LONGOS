<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\LongosGlobalTranslate\Services\CommerceTranslationProfile;
use Plugin\LongosGlobalTranslate\Services\TextContract;

class GlobalTranslationCommerceProfileTest extends TestCase
{
    public function test_profile_combines_target_language_rules_with_a_safe_field_context(): void
    {
        $context = CommerceTranslationProfile::context('en', 'fr', 'product', 'content.node.2');

        self::assertSame('product description', $context['domain']);
        self::assertSame(CommerceTranslationProfile::VERSION, $context['profile_version']);
        self::assertStringContainsString('French', $context['prompt']);
        self::assertStringContainsString('grammar', $context['prompt']);
        self::assertStringContainsString('retail copy', $context['prompt']);
        self::assertStringNotContainsString('@', $context['prompt']);
        self::assertStringNotContainsString('shipping address:', strtolower($context['prompt']));
        self::assertStringNotContainsString('card number', strtolower($context['prompt']));
    }

    public function test_commerce_terms_are_masked_before_every_provider_and_restored_deterministically(): void
    {
        $terms = CommerceTranslationProfile::protectedTerms('en', 'fr');
        self::assertSame('Coque de téléphone', $terms['Phone case']);
        self::assertSame('Livraison gratuite', $terms['Free shipping']);
        self::assertSame('MagSafe', $terms['MagSafe']);

        $prepared = TextContract::protect('Phone case for MagSafe with Free shipping', $terms);
        self::assertStringNotContainsString('Phone case', $prepared['text']);
        self::assertStringNotContainsString('Free shipping', $prepared['text']);

        // The simulated provider response contains only opaque markers, not
        // the approved product terms. Restoration produces the selected
        // target terminology regardless of the active MT provider.
        self::assertSame(
            'Coque de téléphone for MagSafe with Livraison gratuite',
            TextContract::restore($prepared['text'], $prepared['tokens']),
        );
    }

    public function test_non_english_sources_do_not_receive_an_english_commerce_glossary(): void
    {
        self::assertSame([], CommerceTranslationProfile::protectedTerms('fr', 'de'));
    }

    public function test_profile_covers_every_locale_exposed_by_the_bundled_providers(): void
    {
        foreach (['ar', 'de', 'es', 'fr', 'id', 'it', 'ja', 'ko', 'nl', 'pl', 'pt', 'pt-br', 'ru', 'th', 'tr', 'vi', 'zh-cn', 'zh-tw'] as $locale) {
            $context = CommerceTranslationProfile::context('en', $locale, 'product', 'name');

            self::assertNotSame('', $context['prompt'], $locale);
            self::assertLessThanOrEqual(480, mb_strlen($context['prompt']), $locale);
            self::assertNotEmpty(CommerceTranslationProfile::protectedTerms('en', $locale), $locale);
        }
    }
}
