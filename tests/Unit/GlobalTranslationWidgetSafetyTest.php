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
        $view = file_get_contents(dirname(__DIR__, 2) . '/plugins/LongosGlobalTranslate/Views/shop/widget.blade.php');

        self::assertIsString($script);
        self::assertIsString($view);
        self::assertStringContainsString('Math.min(4, sourceRegions.length)', $script);
        self::assertStringContainsString('root.hidden = false;', $script);
        self::assertStringContainsString('hidden', $view);
    }
}
