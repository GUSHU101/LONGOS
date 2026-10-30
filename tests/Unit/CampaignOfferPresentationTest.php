<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CampaignOfferPresentationTest extends TestCase
{
    public function test_campaign_offer_exposes_clear_semantic_information_groups(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string) file_get_contents($root . '/themes/default/design/campaign_offer.blade.php');

        self::assertStringContainsString('aria-labelledby="campaign-title-', $view);
        self::assertStringContainsString('<header class="campaign-offer__header">', $view);
        self::assertStringContainsString('class="campaign-offer__tiers" role="list"', $view);
        self::assertStringContainsString('role="listitem"', $view);
        self::assertStringContainsString('campaign-offer__quantity-unit', $view);
        self::assertStringContainsString('campaign-offer__description', $view);
        self::assertStringContainsString('campaign-offer__shipping', $view);
        self::assertStringContainsString('campaign-offer__code-action', $view);
    }

    public function test_campaign_offer_uses_legible_contrast_and_does_not_hide_status_on_narrow_cards(): void
    {
        $root   = dirname(__DIR__, 2);
        $styles = (string) file_get_contents($root . '/resources/beike/shop/default/css/module/module-shopify-sections.scss');

        self::assertStringContainsString('--campaign-muted: #62584f;', $styles);
        self::assertStringContainsString('container-name: campaign-offer;', $styles);
        self::assertStringContainsString('grid-template-columns: 2.5rem minmax(0, 1fr) auto;', $styles);
        self::assertStringContainsString('&__code-action { color: inherit;', $styles);
        self::assertStringContainsString('&__description { color: var(--campaign-muted); font-size: .8rem;', $styles);
        self::assertStringNotContainsString('&__tier small {', $styles);
        self::assertStringNotContainsString('.campaign-offer__status { display: none; }', $styles);
    }
}
