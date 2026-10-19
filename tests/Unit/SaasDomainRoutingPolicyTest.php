<?php

namespace Tests\Unit;

use App\Saas\Models\Domain;
use App\Saas\Support\DomainRoutingPolicy;
use PHPUnit\Framework\TestCase;

class SaasDomainRoutingPolicyTest extends TestCase
{
    public function test_secondary_redirect_domains_canonicalize_storefront_gets(): void
    {
        self::assertTrue(DomainRoutingPolicy::shouldRedirect(
            'GET',
            'products/a?variant=1',
            false,
            Domain::ROUTING_REDIRECT,
            ['admin', 'api'],
        ));
    }

    public function test_primary_alias_admin_and_mutating_requests_are_not_redirected(): void
    {
        self::assertFalse(DomainRoutingPolicy::shouldRedirect('GET', '/', true, Domain::ROUTING_REDIRECT, []));
        self::assertFalse(DomainRoutingPolicy::shouldRedirect('GET', '/', false, Domain::ROUTING_ALIAS, []));
        self::assertFalse(DomainRoutingPolicy::shouldRedirect('GET', 'admin/orders', false, Domain::ROUTING_REDIRECT, ['admin']));
        self::assertFalse(DomainRoutingPolicy::shouldRedirect('POST', 'cart', false, Domain::ROUTING_REDIRECT, []));
    }

    public function test_domain_exposes_shopify_style_routing_type(): void
    {
        $primary = new Domain(['is_primary' => true, 'routing_behavior' => Domain::ROUTING_ALIAS]);
        $alias   = new Domain(['is_primary' => false, 'routing_behavior' => Domain::ROUTING_ALIAS]);

        self::assertSame('primary', $primary->routing_type);
        self::assertSame('alias', $alias->routing_type);
    }
}
