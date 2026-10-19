<?php

namespace Tests\Unit;

use Beike\Services\ShopifyLiquidParser;
use PHPUnit\Framework\TestCase;

class ShopifyLiquidParserTest extends TestCase
{
    public function test_it_extracts_schema_filters_and_dependencies_from_structured_tokens(): void
    {
        $liquid   = "{% render 'card-product', product: product %}\n{{ 'section-card.css' | asset_url | stylesheet_tag }}\n{% schema %}{\"name\":\"Card\",\"settings\":[{\"type\":\"text\",\"id\":\"title\"}]}{% endschema %}";
        $analysis = (new ShopifyLiquidParser)->inspect($liquid);

        self::assertContains('snippets/card-product.liquid', $analysis['dependencies']);
        self::assertContains('assets/section-card.css', $analysis['dependencies']);
        self::assertSame('Card', $analysis['schema']['name']);
        self::assertContains('asset_url', $analysis['filters']);
        self::assertSame([], $analysis['warnings']);
    }
}
