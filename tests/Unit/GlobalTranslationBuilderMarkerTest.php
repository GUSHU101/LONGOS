<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin\LongosGlobalTranslate\Services\ContentScanner;

class GlobalTranslationBuilderMarkerTest extends TestCase
{
    #[DataProvider('supportedBuilderViews')]
    public function test_supported_builder_view_has_a_bounded_dictionary_root_and_field_markers(string $view): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/themes/default/design/' . $view . '.blade.php');

        self::assertIsString($contents);
        self::assertStringContainsString('data-lgt-root', $contents);
        self::assertStringContainsString('data-lgt-kind="builder"', $contents);
        self::assertStringContainsString('data-lgt-field=', $contents);
        self::assertStringContainsString("hash('sha256', 'lgt-builder:'", $contents);
    }

    /** @return iterable<string, array{string}> */
    public static function supportedBuilderViews(): iterable
    {
        foreach ([
            'promo_bar', 'faq', 'trust_cards', 'testimonials', 'campaign_offer',
            'payment_trust', 'story_block', 'feature_grid', 'social_proof',
        ] as $view) {
            yield $view => [$view];
        }
    }

    public function test_rich_html_builder_component_is_not_marked_for_client_side_replacement(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/themes/default/design/rich_text.blade.php');

        self::assertIsString($contents);
        self::assertStringNotContainsString('data-lgt-root', $contents);
        self::assertStringNotContainsString('data-lgt-field=', $contents);
    }

    public function test_every_scannable_builder_field_has_an_exact_storefront_marker(): void
    {
        foreach (ContentScanner::builderFieldContract() as $component => $contract) {
            $contents = file_get_contents(dirname(__DIR__, 2) . '/themes/default/design/' . $component . '.blade.php');

            self::assertIsString($contents, $component . ' view must exist');
            foreach ($contract['top'] as $field) {
                self::assertStringContainsString(
                    'data-lgt-field="module.{{ $module_id }}.' . $field . '"',
                    $contents,
                    $component . '.' . $field . ' is scannable but not renderable'
                );
            }
            foreach ($contract['collections'] ?? [] as $collection => $fields) {
                foreach ($fields as $field) {
                    self::assertStringContainsString(
                        'data-lgt-field="module.{{ $module_id }}.' . $collection . '.{{ $loop->index }}.' . $field . '"',
                        $contents,
                        $component . '.' . $collection . '.' . $field . ' is scannable but not renderable'
                    );
                }
            }
        }
    }
}
