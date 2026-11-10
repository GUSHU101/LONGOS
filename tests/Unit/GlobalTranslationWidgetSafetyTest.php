<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class GlobalTranslationWidgetSafetyTest extends TestCase
{
    public function test_widget_resets_to_source_before_applying_a_new_dictionary_and_never_uses_html_injection(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/plugins/LongosGlobalTranslate/Static/public/widget.js');

        self::assertIsString($script);
        self::assertStringNotContainsString('.innerHTML', $script);
        self::assertStringContainsString('restoreSource(sourceRegions);', $script);
        self::assertLessThan(
            strpos($script, 'const dictionaries = await fetchDictionaries(sourceRegions, locale);'),
            strpos($script, 'restoreSource(sourceRegions);'),
            'The old locale must be cleared before any new dictionary is read.'
        );
    }

    public function test_widget_bounds_dictionary_concurrency_and_hides_until_configuration_is_valid(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/plugins/LongosGlobalTranslate/Static/public/widget.js');
        $view   = file_get_contents(dirname(__DIR__, 2) . '/plugins/LongosGlobalTranslate/Views/shop/widget.blade.php');

        self::assertIsString($script);
        self::assertIsString($view);
        self::assertStringContainsString('Math.min(4,sourceRegions.length)', $script);
        self::assertStringContainsString('batch_dictionary_url', $script);
        self::assertStringContainsString('safeFallbackParent', $script);
        self::assertStringContainsString('[data-lgt-private]', $script);
        self::assertStringContainsString('sensitivePath()', $script);
        self::assertStringContainsString('root.hidden = !data.widget_visible;', $script);
        self::assertStringContainsString("request(root.dataset.configUrl, {cache: 'no-store'})", $script);
        self::assertStringNotContainsString('inlineBootstrap', $script);
        self::assertStringContainsString('window.sessionStorage', $script);
        self::assertStringContainsString('published_revisions', $script);
        self::assertStringContainsString('isCurrentDictionary', $script);
        self::assertStringContainsString('window.requestIdleCallback', $script);
        self::assertStringContainsString('scheduleFallback(locale, epoch);', $script);
        self::assertStringNotContainsString('longos-global-translate-bootstrap', $view);
        self::assertStringContainsString('hidden', $view);
    }

    public function test_interface_dictionary_reaches_fixed_labels_inside_public_content_regions_but_not_merchant_fields(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/plugins/LongosGlobalTranslate/Static/public/widget.js');

        self::assertIsString($script);
        self::assertStringContainsString('[data-lgt-field]', $script);
        self::assertStringContainsString('Protect the marked', $script);
        self::assertStringContainsString('safeFallbackParent', $script);
        // Entity fallback remains outside a region because that route handles
        // merchant text. The interface dictionary has a narrower, static-only
        // rule and must be able to reach fixed chrome inside the same region.
        self::assertStringContainsString('[data-lgt-root], form', $script);
        self::assertStringNotContainsString('#longos-global-translate, [data-lgt-root], script', $script);
    }

    public function test_widget_cannot_apply_an_old_locale_response_over_a_newer_visitor_choice(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/plugins/LongosGlobalTranslate/Static/public/widget.js');

        self::assertIsString($script);
        self::assertStringContainsString('let presentationEpoch = 0;', $script);
        self::assertStringContainsString('const epoch = ++presentationEpoch;', $script);
        self::assertStringContainsString('if (epoch !== presentationEpoch) return {applied: 0, available: false};', $script);
        self::assertStringContainsString('let applyEpoch = 0;', $script);
        self::assertStringContainsString('if (epoch === applyEpoch) document.documentElement.lang', $script);
    }
}
