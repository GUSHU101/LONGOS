<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class StorefrontSeoPresentationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_master_layout_exposes_canonical_and_complete_social_metadata(): void
    {
        $master = $this->read('themes/default/layout/master.blade.php');

        self::assertStringContainsString('<link rel="canonical" href="@yield(\'canonical\', storefront_canonical_url())">', $master);
        self::assertStringContainsString('<meta property="og:url" content="@yield(\'canonical\', storefront_canonical_url())">', $master);
        self::assertStringContainsString('<meta property="og:image:alt"', $master);
        self::assertStringContainsString('<meta name="twitter:image"', $master);
        self::assertStringContainsString('<meta name="twitter:image:alt"', $master);
    }

    public function test_canonical_url_removes_tracking_but_preserves_meaningful_query_state(): void
    {
        $helpers = $this->read('beike/Helpers.php');

        self::assertStringContainsString('function storefront_canonical_url(): string', $helpers);
        self::assertStringContainsString("str_starts_with(\$normalized, 'utm_')", $helpers);
        self::assertStringContainsString("['dclid', 'fbclid', 'gclid', 'iframe'", $helpers);
        self::assertStringContainsString('PHP_QUERY_RFC3986', $helpers);
        self::assertStringNotContainsString("unset(\$query['page'])", $helpers);
    }

    public function test_all_storefront_templates_use_the_saved_meta_keywords_setting(): void
    {
        foreach (['themes/default/layout/master.blade.php', 'themes/default/product/product.blade.php', 'themes/default/category.blade.php', 'themes/default/page_categories/show.blade.php'] as $path) {
            $template = $this->read($path);
            self::assertStringNotContainsString("system_setting('base.meta_keyword')", $template, $path);
        }
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->root . '/' . $path);
    }
}
