<?php

namespace Tests\Feature;

use App\Saas\DomainConfigWriter;
use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use App\Saas\SiteAuditLogger;
use App\Saas\SiteHealthChecker;
use App\Saas\SiteLifecycleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SaasLifecycleActivationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $connection = [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ];
        config([
            'database.default'          => 'saas',
            'database.connections.saas' => $connection,
            'saas.base_domain'          => 'example.com',
            'saas.control_domains'      => ['admin.example.com'],
        ]);
        DB::purge('saas');
        DB::setDefaultConnection('saas');
        self::assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]));
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_reactivation_is_not_routable_until_domain_publication_succeeds(): void
    {
        $site   = $this->suspendedSite();
        $writer = new class(new Filesystem) extends DomainConfigWriter
        {
            public array $observedStatuses = [];

            public function synchronized(callable $callback): mixed
            {
                return $callback();
            }

            public function sync(bool $reload = true): array
            {
                $this->observedStatuses[] = Site::query()->sole()->status;

                return ['config_path' => 'test', 'manifest_path' => 'test.json', 'hosts' => 1, 'reloaded' => false];
            }
        };
        $service = new SiteLifecycleService(
            $writer,
            $this->createMock(SiteHealthChecker::class),
            $this->createMock(SiteAuditLogger::class),
        );

        $result = $service->setStatus($site, Site::STATUS_ACTIVE, true);

        self::assertSame([Site::STATUS_ACTIVATING], $writer->observedStatuses);
        self::assertSame(Site::STATUS_ACTIVE, $result->status);
    }

    public function test_failed_publication_restores_the_suspended_state(): void
    {
        $site   = $this->suspendedSite();
        $writer = new class(new Filesystem) extends DomainConfigWriter
        {
            public function synchronized(callable $callback): mixed
            {
                return $callback();
            }

            public function sync(bool $reload = true): array
            {
                throw new RuntimeException('simulated publication failure');
            }
        };
        $service = new SiteLifecycleService(
            $writer,
            $this->createMock(SiteHealthChecker::class),
            $this->createMock(SiteAuditLogger::class),
        );

        try {
            $service->setStatus($site, Site::STATUS_ACTIVE, true);
            self::fail('Expected activation to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('simulated publication failure', $exception->getMessage());
        }

        self::assertSame(Site::STATUS_SUSPENDED, $site->refresh()->status);
    }

    public function test_new_primary_domain_is_published_before_it_becomes_routable(): void
    {
        $site = $this->suspendedSite();
        $site->forceFill(['status' => Site::STATUS_ACTIVE])->save();
        $oldPrimary = $site->domains()->sole();
        $writer     = new class(new Filesystem) extends DomainConfigWriter
        {
            public array $observedStatuses = [];

            public function synchronized(callable $callback): mixed
            {
                return $callback();
            }

            public function sync(bool $reload = true): array
            {
                $this->observedStatuses[] = Domain::query()
                    ->where('host', 'new-primary.example.com')
                    ->value('status');

                return ['config_path' => 'test', 'manifest_path' => 'test.json', 'hosts' => 2, 'reloaded' => false];
            }
        };
        $service = new SiteLifecycleService(
            $writer,
            $this->createMock(SiteHealthChecker::class),
            $this->createMock(SiteAuditLogger::class),
        );

        $domain = $service->addDomain($site, 'https://new-primary.example.com', true);

        self::assertSame([Domain::STATUS_ACTIVATING, Domain::STATUS_ACTIVE], $writer->observedStatuses);
        self::assertSame(Domain::STATUS_ACTIVE, $domain->status);
        self::assertTrue($domain->is_primary);
        self::assertFalse($oldPrimary->refresh()->is_primary);
    }

    public function test_low_level_lifecycle_cannot_publish_a_customer_or_control_domain(): void
    {
        $site = $this->suspendedSite();
        $site->forceFill(['status' => Site::STATUS_ACTIVE])->save();
        $service = new SiteLifecycleService(
            new DomainConfigWriter(new Filesystem),
            $this->createMock(SiteHealthChecker::class),
            $this->createMock(SiteAuditLogger::class),
        );

        foreach (['shop.customer.test', 'example.com', 'admin.example.com'] as $host) {
            try {
                $service->addDomain($site, $host);
                self::fail("Expected {$host} to be rejected.");
            } catch (RuntimeException $exception) {
                self::assertTrue(
                    str_contains($exception->getMessage(), 'TXT')
                    || str_contains($exception->getMessage(), '控制站'),
                );
            }
        }

        self::assertSame(1, $site->domains()->count());
    }

    public function test_platform_child_can_be_published_when_control_host_equals_base_domain(): void
    {
        config([
            'saas.base_domain'     => 'example.com',
            'saas.control_domains' => ['example.com'],
        ]);
        $site = $this->suspendedSite();
        $site->forceFill(['status' => Site::STATUS_ACTIVE])->save();
        $writer = new class(new Filesystem) extends DomainConfigWriter
        {
            public function synchronized(callable $callback): mixed
            {
                return $callback();
            }

            public function sync(bool $reload = true): array
            {
                return ['config_path' => 'test', 'manifest_path' => 'test.json', 'hosts' => 2, 'reloaded' => false];
            }
        };
        $service = new SiteLifecycleService(
            $writer,
            $this->createMock(SiteHealthChecker::class),
            $this->createMock(SiteAuditLogger::class),
        );

        $domain = $service->addDomain($site, 'tenant-child.example.com');

        self::assertSame('tenant-child.example.com', $domain->host);
        self::assertSame(Domain::STATUS_ACTIVE, $domain->status);
    }

    public function test_low_level_lifecycle_cannot_publish_a_reserved_platform_subdomain(): void
    {
        config([
            'saas.base_domain'                  => 'example.com',
            'saas.control_domains'              => ['example.com'],
            'saas.domains.reserved_subdomains'  => ['admin'],
        ]);
        $site = $this->suspendedSite();
        $site->forceFill(['status' => Site::STATUS_ACTIVE])->save();
        $service = new SiteLifecycleService(
            new DomainConfigWriter(new Filesystem),
            $this->createMock(SiteHealthChecker::class),
            $this->createMock(SiteAuditLogger::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('已被系统保留');

        $service->addDomain($site, 'admin.example.com');
    }

    public function test_tenant_can_change_managed_subdomain_without_releasing_the_old_name(): void
    {
        config([
            'saas.base_domain'                 => 'shops.example.com',
            'saas.domains.reserved_subdomains' => ['www', 'admin'],
        ]);
        $site = $this->suspendedSite();
        $site->forceFill(['status' => Site::STATUS_ACTIVE])->save();
        $old = $site->domains()->sole();

        $writer = new class(new Filesystem) extends DomainConfigWriter
        {
            public function synchronized(callable $callback): mixed
            {
                return $callback();
            }

            public function sync(bool $reload = true): array
            {
                return ['config_path' => 'test', 'manifest_path' => 'test.json', 'hosts' => 1, 'reloaded' => false];
            }
        };
        $service = new SiteLifecycleService(
            $writer,
            $this->createMock(SiteHealthChecker::class),
            $this->createMock(SiteAuditLogger::class),
        );

        $replacement = $service->changeSubdomain($site, 'new-brand');

        self::assertSame('new-brand.shops.example.com', $replacement->host);
        self::assertSame(Domain::STATUS_ACTIVE, $replacement->status);
        self::assertTrue($replacement->is_primary);
        self::assertSame(Domain::STATUS_RETIRED, $old->refresh()->status);
        self::assertFalse($old->is_primary);
        self::assertTrue(Domain::query()
            ->where('host', 'tenant-a.example.com')
            ->where('status', Domain::STATUS_RETIRED)
            ->exists());
    }

    public function test_reserved_subdomain_is_rejected(): void
    {
        config([
            'saas.base_domain'                  => 'shops.example.com',
            'saas.domains.reserved_subdomains'  => ['admin'],
        ]);
        $site = $this->suspendedSite();
        $site->forceFill(['status' => Site::STATUS_ACTIVE])->save();
        $writer = new class(new Filesystem) extends DomainConfigWriter
        {
            public function synchronized(callable $callback): mixed
            {
                return $callback();
            }
        };
        $service = new SiteLifecycleService(
            $writer,
            $this->createMock(SiteHealthChecker::class),
            $this->createMock(SiteAuditLogger::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('已被平台保留');

        $service->changeSubdomain($site, 'admin');
    }

    public function test_subdomain_change_rolls_back_when_final_publication_fails(): void
    {
        config([
            'saas.base_domain'                  => 'shops.example.com',
            'saas.domains.reserved_subdomains'  => [],
        ]);
        $site = $this->suspendedSite();
        $site->forceFill(['status' => Site::STATUS_ACTIVE])->save();
        $old = $site->domains()->sole();

        $writer = new class(new Filesystem) extends DomainConfigWriter
        {
            private int $syncs = 0;

            public function synchronized(callable $callback): mixed
            {
                return $callback();
            }

            public function sync(bool $reload = true): array
            {
                $this->syncs++;
                if ($this->syncs === 3) {
                    $site = Site::query()->where('code', 'tenant-a')->sole();
                    Domain::query()->create([
                        'site_id'                  => $site->id,
                        'host'                     => 'concurrent-customer.example.net',
                        'scheme'                   => 'https',
                        'is_primary'               => false,
                        'routing_behavior'         => Domain::ROUTING_REDIRECT,
                        'status'                   => Domain::STATUS_PENDING,
                        'origin'                   => Domain::ORIGIN_TENANT,
                        'verification_method'      => Domain::VERIFICATION_DNS_TXT,
                        'verification_token'       => 'concurrent-token',
                        'verification_expires_at'  => now()->addHour(),
                    ]);

                    throw new RuntimeException('simulated final publication failure');
                }

                return ['config_path' => 'test', 'manifest_path' => 'test.json', 'hosts' => 1, 'reloaded' => false];
            }
        };
        $service = new SiteLifecycleService(
            $writer,
            $this->createMock(SiteHealthChecker::class),
            $this->createMock(SiteAuditLogger::class),
        );

        try {
            $service->changeSubdomain($site, 'rollback-brand');
            self::fail('Expected subdomain publication to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('simulated final publication failure', $exception->getMessage());
        }

        self::assertSame(Domain::STATUS_ACTIVE, $old->refresh()->status);
        self::assertTrue($old->is_primary);
        self::assertFalse(Domain::query()->where('host', 'rollback-brand.shops.example.com')->exists());
        self::assertTrue(Domain::query()
            ->where('host', 'concurrent-customer.example.net')
            ->where('status', Domain::STATUS_PENDING)
            ->exists());
    }

    private function suspendedSite(): Site
    {
        $site = Site::query()->create([
            'uuid'          => (string) Str::uuid(),
            'code'          => 'tenant-a',
            'name'          => 'Tenant A',
            'database_name' => 'tenant_a',
            'status'        => Site::STATUS_SUSPENDED,
            'locale'        => 'zh_cn',
            'timezone'      => 'Asia/Shanghai',
        ]);
        Domain::query()->create([
            'site_id'          => $site->id,
            'host'             => 'tenant-a.example.com',
            'scheme'           => 'https',
            'is_primary'       => true,
            'routing_behavior' => Domain::ROUTING_REDIRECT,
            'status'           => Domain::STATUS_ACTIVE,
            'origin'           => Domain::ORIGIN_PLATFORM,
        ]);

        return $site;
    }
}
