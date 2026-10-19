<?php

namespace Tests\Unit;

use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\TenantCollection;

class SaasTenantContractTest extends TestCase
{
    public function test_site_is_the_spatie_tenant_and_exposes_its_database(): void
    {
        $site = new Site([
            'code'          => 'brand-001',
            'database_name' => 'longos_brand_001',
        ]);

        self::assertInstanceOf(IsTenant::class, $site);
        self::assertSame('longos_brand_001', $site->getDatabaseName());
        self::assertInstanceOf(TenantCollection::class, $site->newCollection([$site]));
    }

    public function test_primary_url_never_uses_an_inactive_domain(): void
    {
        $site = new Site;
        $site->setRelation('domains', new Collection([
            new Domain([
                'host'       => 'pending.example.com',
                'scheme'     => 'https',
                'is_primary' => true,
                'status'     => Domain::STATUS_PENDING,
            ]),
            new Domain([
                'host'       => 'active.example.com',
                'scheme'     => 'https',
                'is_primary' => false,
                'status'     => Domain::STATUS_ACTIVE,
            ]),
        ]));

        self::assertSame('https://active.example.com', $site->primary_url);
    }
}
