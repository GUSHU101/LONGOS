<?php

namespace Tests\Unit;

use App\Saas\Models\Site;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class SaasFileIsolationTest extends TestCase
{
    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container               = new Container;
        $container->instance('config', new Repository(['saas' => ['isolate_files' => true]]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_new_tenants_receive_a_scoped_public_prefix(): void
    {
        $site = new Site(['code' => 'brand-001', 'meta' => ['isolate_files' => true]]);
        Container::getInstance()->instance('saas.current_site', $site);

        self::assertSame('image/catalog/tenants/brand-001', tenant_catalog_prefix());
        self::assertSame('upload/tenants/brand-001', tenant_upload_prefix());
    }

    public function test_legacy_main_site_can_keep_shared_paths(): void
    {
        $site = new Site(['code' => 'main', 'meta' => ['isolate_files' => false]]);
        Container::getInstance()->instance('saas.current_site', $site);

        self::assertSame('image/catalog', tenant_catalog_prefix());
        self::assertSame('upload', tenant_upload_prefix());
    }
}
