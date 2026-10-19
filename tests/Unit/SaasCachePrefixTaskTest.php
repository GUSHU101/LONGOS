<?php

namespace Tests\Unit;

use App\Saas\Tasks\PrefixTenantCacheTask;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class SaasCachePrefixTaskTest extends TestCase
{
    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container               = new Container;
        $container->instance('config', new Repository([
            'cache' => [
                'prefix'  => 'longos_cache',
                'default' => 'array',
                'stores'  => ['file' => ['path' => 'C:\\cache']],
            ],
        ]));
        $container->bind('cache', static fn () => new class
        {
            public function forgetDriver(?string $name): void {}
        });
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_tenant_prefix_keeps_the_application_namespace(): void
    {
        $task = new class extends PrefixTenantCacheTask
        {
            public function tenantPrefix(): ?string
            {
                return $this->cacheKeyBase;
            }
        };

        self::assertSame('longos_cache_tenant_', $task->tenantPrefix());
    }

    public function test_file_cache_path_is_isolated_and_restored(): void
    {
        $task       = new PrefixTenantCacheTask;
        $tenant     = new \App\Saas\Models\Site;
        $tenant->id = 42;

        $task->makeCurrent($tenant);
        self::assertSame('C:\\cache' . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . '42', config('cache.stores.file.path'));

        $task->forgetCurrent();
        self::assertSame('C:\\cache', config('cache.stores.file.path'));
    }
}
