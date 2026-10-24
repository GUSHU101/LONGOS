<?php

namespace Tests\Unit;

use Beike\Services\LegacyRedirectService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class LegacyRedirectServiceTest extends TestCase
{
    public function test_it_parses_and_normalizes_internal_redirect_lines(): void
    {
        self::assertSame([
            ['from' => '/old-product', 'to' => '/products/100103'],
            ['from' => '/old-category', 'to' => '/categories/new?sort=sales'],
        ], LegacyRedirectService::normalize("# Relaunch map\n/old-product/ => /products/100103\n/old-category -> /categories/new?sort=sales"));
    }

    public function test_it_rejects_external_and_reserved_redirect_sources(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LegacyRedirectService::normalize('/admin/orders => https://malicious.example.test');
    }

    public function test_it_rejects_duplicate_sources(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LegacyRedirectService::normalize("/old => /new\n/old => /newer");
    }

    public function test_it_rejects_self_redirects_and_circular_chains(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LegacyRedirectService::normalize("/old => /new\n/new => /old");
    }

    public function test_it_collapses_redirect_chains_to_one_hop_and_preserves_query_overrides(): void
    {
        self::assertSame([
            ['from' => '/first', 'to' => '/final?campaign=spring&sort=newest'],
            ['from' => '/middle', 'to' => '/final?sort=newest'],
        ], LegacyRedirectService::normalize("/first => /middle?campaign=spring&sort=popular\n/middle => /final?sort=newest"));
    }

    public function test_it_rejects_paths_the_runtime_intentionally_bypasses(): void
    {
        foreach (['/build/app.js', '/cache/product.webp', '/documents/terms.pdf'] as $source) {
            try {
                LegacyRedirectService::normalize($source . ' => /new-location');
                self::fail('Expected reserved static path to be rejected: ' . $source);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_it_rejects_control_characters_and_backslashes(): void
    {
        foreach (["/old\r\nX-Test: injected", '/old\\path'] as $source) {
            try {
                LegacyRedirectService::normalize($source . ' => /new-location');
                self::fail('Expected unsafe redirect source to be rejected.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
