<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class StorefrontDesignLocalizationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_storefront_design_templates_do_not_hardcode_chinese_runtime_copy(): void
    {
        $directory = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root . '/themes/default/design'));

        foreach ($directory as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $source = preg_replace('#//[^\r\n]*#', '', $source) ?? $source;
            self::assertDoesNotMatchRegularExpression('/\p{Han}/u', $source, $file->getPathname());
        }

        $campaignScript = (string) file_get_contents($this->root . '/resources/beike/shop/default/js/campaign-offer.js');
        $campaignView   = (string) file_get_contents($this->root . '/themes/default/design/campaign_offer.blade.php');
        self::assertDoesNotMatchRegularExpression('/\p{Han}/u', $campaignScript);
        self::assertStringContainsString("__('shop/design.campaign_active')", $campaignView);
        self::assertStringNotContainsString("__('common.active')", $campaignView);
    }

    public function test_english_and_chinese_design_translation_contracts_match(): void
    {
        $english = require $this->root . '/lang/en/shop/design.php';
        $chinese = require $this->root . '/lang/zh_cn/shop/design.php';

        self::assertSame(array_keys($this->flatten($english)), array_keys($this->flatten($chinese)));
        self::assertSame('Copied', $english['copied']);
        self::assertSame('已复制', $chinese['copied']);
        self::assertSame('Offer active', $english['campaign_active']);
        self::assertSame('优惠进行中', $chinese['campaign_active']);
    }

    /** @return array<string, scalar|null> */
    private function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $flat += $this->flatten($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }
}
