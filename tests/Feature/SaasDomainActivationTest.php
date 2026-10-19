<?php

namespace Tests\Feature;

use App\Saas\Contracts\DnsRouteResolver;
use App\Saas\Contracts\DnsTxtResolver;
use App\Saas\Contracts\DomainTrafficProbe;
use App\Saas\DomainConfigWriter;
use App\Saas\DomainVerificationService;
use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use App\Saas\SiteAuditLogger;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SaasDomainActivationTest extends TestCase
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
            'database.default'            => 'saas',
            'database.connections.saas'   => $connection,
            'saas.enabled'                => true,
            'saas.custom_domains.enabled' => true,
            'saas.dns_targets'            => ['192.0.2.10'],
            'saas.tls.driver'             => 'caddy',
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

    public function test_verified_custom_domain_is_not_routable_before_publication(): void
    {
        $site = Site::query()->create([
            'uuid'          => (string) Str::uuid(),
            'code'          => 'tenant-a',
            'name'          => 'Tenant A',
            'database_name' => 'tenant_a',
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'zh_cn',
            'timezone'      => 'Asia/Shanghai',
        ]);
        $token  = Str::lower(Str::random(48));
        $domain = Domain::query()->create([
            'site_id'                 => $site->id,
            'host'                    => 'custom.example.com',
            'scheme'                  => 'https',
            'is_primary'              => false,
            'routing_behavior'        => Domain::ROUTING_REDIRECT,
            'status'                  => Domain::STATUS_PENDING,
            'origin'                  => Domain::ORIGIN_TENANT,
            'verification_method'     => Domain::VERIFICATION_DNS_TXT,
            'verification_token'      => $token,
            'verification_expires_at' => now()->addHour(),
        ]);
        $dns = new class($token) implements DnsTxtResolver
        {
            public function __construct(private readonly string $token) {}

            public function resolve(string $host): array
            {
                return ['longos-site-verification=' . $this->token];
            }
        };
        $writer = new class(new Filesystem) extends DomainConfigWriter
        {
            public array $observedStatuses = [];

            public function synchronized(callable $callback): mixed
            {
                return $callback();
            }

            public function sync(bool $reload = true): array
            {
                $this->observedStatuses[] = Domain::query()->where('host', 'custom.example.com')->value('status');

                return ['config_path' => 'test', 'manifest_path' => 'test.json', 'hosts' => 1, 'reloaded' => false];
            }
        };
        $service = new DomainVerificationService(
            $dns,
            $this->routingDns(['192.0.2.10']),
            $this->trafficProbe(true),
            $writer,
            $this->createMock(SiteAuditLogger::class),
        );

        $verified = $service->verify($site, $domain);

        self::assertSame([Domain::STATUS_ACTIVATING], $writer->observedStatuses);
        self::assertSame(Domain::STATUS_ACTIVE, $verified->status);
        self::assertNotNull($verified->verified_at);
        self::assertNotNull($verified->published_at);
        self::assertNotNull($verified->last_probe_at);
        self::assertNull($verified->verification_token);
    }

    public function test_caddy_domain_stays_pending_when_certificate_or_https_probe_fails(): void
    {
        $site   = $this->activeSite('tenant-caddy-failed');
        $token  = Str::lower(Str::random(48));
        $domain = $this->pendingDomain($site, $token, 'caddy-failed.example.com');
        $dns    = new class($token) implements DnsTxtResolver
        {
            public function __construct(private readonly string $token) {}

            public function resolve(string $host): array
            {
                return ['longos-site-verification=' . $this->token];
            }
        };
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
        $service = new DomainVerificationService(
            $dns,
            $this->routingDns(['192.0.2.10']),
            $this->trafficProbe(false),
            $writer,
            $this->createMock(SiteAuditLogger::class),
        );

        try {
            $service->verify($site, $domain);
            self::fail('Expected Caddy HTTPS publication to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Caddy', $exception->getMessage());
        }

        $domain->refresh();
        self::assertSame(Domain::STATUS_PENDING, $domain->status);
        self::assertNull($domain->published_at);
        self::assertNotNull($domain->last_probe_at);
        self::assertStringContainsString('probe failed', (string) $domain->publication_error);
    }

    public function test_cloudflare_anycast_dns_is_accepted_only_when_cloudflare_mode_is_enabled(): void
    {
        config(['longos.cloudflare.mode' => 'optional']);
        $site   = $this->activeSite('tenant-cloudflare');
        $token  = Str::lower(Str::random(48));
        $domain = $this->pendingDomain($site, $token, 'cloudflare.example.com');
        $dns    = new class($token) implements DnsTxtResolver
        {
            public function __construct(private readonly string $token) {}

            public function resolve(string $host): array
            {
                return ['longos-site-verification=' . $this->token];
            }
        };
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
        $service = new DomainVerificationService(
            $dns,
            $this->routingDns(['104.16.10.20']),
            $this->trafficProbe(true),
            $writer,
            $this->createMock(SiteAuditLogger::class),
        );

        $verified = $service->verify($site, $domain);

        self::assertSame(Domain::STATUS_ACTIVE, $verified->status);
        self::assertNotNull($verified->last_probe_at);
    }

    public function test_custom_domain_requests_are_forced_to_https(): void
    {
        config(['saas.base_domain' => 'shops.example.com']);
        $site    = $this->activeSite('tenant-https');
        $service = $this->verificationService();

        $domain = $service->request($site, 'http://store.customer.example');

        self::assertSame('store.customer.example', $domain->host);
        self::assertSame('https', $domain->scheme);
        self::assertSame(Domain::STATUS_PENDING, $domain->status);
    }

    public function test_platform_namespace_cannot_be_claimed_as_a_custom_domain(): void
    {
        config(['saas.base_domain' => 'shops.example.com']);
        $site    = $this->activeSite('tenant-platform-namespace');
        $service = $this->verificationService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('平台子域名命名空间');

        $service->request($site, 'another.shops.example.com');
    }

    public function test_control_plane_can_distinguish_platform_hosts_from_customer_domains(): void
    {
        config([
            'saas.base_domain'     => 'shops.example.com',
            'saas.control_domains' => ['admin.shops.example.com'],
        ]);
        $service = $this->verificationService();

        self::assertTrue($service->isPlatformManagedHost('tenant-a.shops.example.com'));
        self::assertTrue($service->isPlatformManagedHost('https://tenant-b.shops.example.com'));
        self::assertFalse($service->isPlatformManagedHost('shops.example.com'));
        self::assertFalse($service->isPlatformManagedHost('admin.shops.example.com'));
        self::assertFalse($service->isPlatformManagedHost('shop.customer.example'));
        self::assertFalse($service->isPlatformManagedHost('not a domain'));

        config(['saas.control_domains' => ['shops.example.com']]);
        self::assertTrue($service->isPlatformManagedHost('tenant-c.shops.example.com'));
        self::assertFalse($service->isPlatformManagedHost('shops.example.com'));
    }

    public function test_exact_control_host_cannot_be_claimed_as_a_custom_domain(): void
    {
        config([
            'saas.base_domain'     => 'shops.example.com',
            'saas.control_domains' => ['admin.platform.example'],
        ]);
        $site    = $this->activeSite('tenant-control-namespace');
        $service = $this->verificationService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('控制站域名');

        $service->request($site, 'admin.platform.example');
    }

    public function test_child_of_control_host_is_a_distinct_custom_domain(): void
    {
        config([
            'saas.base_domain'     => 'shops.example.com',
            'saas.control_domains' => ['admin.platform.example'],
        ]);
        $site    = $this->activeSite('tenant-control-child');
        $service = $this->verificationService();

        $domain = $service->request($site, 'nested.admin.platform.example');

        self::assertSame(Domain::STATUS_PENDING, $domain->status);
        self::assertSame(Domain::ORIGIN_TENANT, $domain->origin);
    }

    public function test_txt_ownership_does_not_activate_a_domain_with_wrong_traffic_dns(): void
    {
        $site   = $this->activeSite('tenant-wrong-dns');
        $token  = Str::lower(Str::random(48));
        $domain = $this->pendingDomain($site, $token, 'wrong.example.com');
        $dns    = new class($token) implements DnsTxtResolver
        {
            public function __construct(private readonly string $token) {}

            public function resolve(string $host): array
            {
                return ['longos-site-verification=' . $this->token];
            }
        };
        $service = new DomainVerificationService(
            $dns,
            new class implements DnsRouteResolver
            {
                public function resolve(string $host): array
                {
                    return $host === 'wrong.example.com' ? ['198.51.100.20'] : [];
                }
            },
            $this->trafficProbe(true),
            new DomainConfigWriter(new Filesystem),
            $this->createMock(SiteAuditLogger::class),
        );

        try {
            $service->verify($site, $domain);
            self::fail('Expected traffic DNS verification to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('必须指向', $exception->getMessage());
        }

        self::assertSame(Domain::STATUS_PENDING, $domain->refresh()->status);
        self::assertNull($domain->published_at);
    }

    public function test_external_tls_domain_stays_pending_until_real_https_routes_to_longos(): void
    {
        config(['saas.tls.driver' => 'external']);
        $site   = $this->activeSite('tenant-external-failed');
        $token  = Str::lower(Str::random(48));
        $domain = $this->pendingDomain($site, $token, 'external.example.com');
        $dns    = new class($token) implements DnsTxtResolver
        {
            public function __construct(private readonly string $token) {}

            public function resolve(string $host): array
            {
                return ['longos-site-verification=' . $this->token];
            }
        };
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
        $service = new DomainVerificationService(
            $dns,
            $this->routingDns(['192.0.2.10']),
            $this->trafficProbe(false),
            $writer,
            $this->createMock(SiteAuditLogger::class),
        );

        try {
            $service->verify($site, $domain);
            self::fail('Expected the HTTPS traffic probe to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('外部 HTTPS 入口', $exception->getMessage());
        }

        $domain->refresh();
        self::assertSame(Domain::STATUS_PENDING, $domain->status);
        self::assertNull($domain->published_at);
        self::assertNotNull($domain->last_probe_at);
        self::assertStringContainsString('probe failed', (string) $domain->publication_error);
        self::assertFalse($domain->isRoutable());
    }

    public function test_mixed_expected_and_untrusted_dns_addresses_are_rejected(): void
    {
        $site   = $this->activeSite('tenant-mixed-dns');
        $token  = Str::lower(Str::random(48));
        $domain = $this->pendingDomain($site, $token, 'mixed.example.com');
        $dns    = new class($token) implements DnsTxtResolver
        {
            public function __construct(private readonly string $token) {}

            public function resolve(string $host): array
            {
                return ['longos-site-verification=' . $this->token];
            }
        };
        $routingDns = new class implements DnsRouteResolver
        {
            public function resolve(string $host): array
            {
                return $host === 'mixed.example.com'
                    ? ['192.0.2.10', '127.0.0.1']
                    : [$host];
            }
        };
        $service = new DomainVerificationService(
            $dns,
            $routingDns,
            $this->trafficProbe(true),
            new DomainConfigWriter(new Filesystem),
            $this->createMock(SiteAuditLogger::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('必须指向');

        $service->verify($site, $domain);
    }

    public function test_external_tls_domain_activates_after_real_https_probe_succeeds(): void
    {
        config(['saas.tls.driver' => 'external']);
        $site   = $this->activeSite('tenant-external-ready');
        $token  = Str::lower(Str::random(48));
        $domain = $this->pendingDomain($site, $token, 'ready.example.com');
        $dns    = new class($token) implements DnsTxtResolver
        {
            public function __construct(private readonly string $token) {}

            public function resolve(string $host): array
            {
                return ['longos-site-verification=' . $this->token];
            }
        };
        $writer = new class(new Filesystem) extends DomainConfigWriter
        {
            public function synchronized(callable $callback): mixed
            {
                return $callback();
            }

            public function sync(bool $reload = true): array
            {
                return ['config_path' => 'test', 'manifest_path' => 'test.json', 'hosts' => 1, 'reloaded' => true];
            }
        };
        $service = new DomainVerificationService(
            $dns,
            $this->routingDns(['192.0.2.10']),
            $this->trafficProbe(true),
            $writer,
            $this->createMock(SiteAuditLogger::class),
        );

        $verified = $service->verify($site, $domain);

        self::assertSame(Domain::STATUS_ACTIVE, $verified->status);
        self::assertTrue($verified->isRoutable());
        self::assertNotNull($verified->published_at);
        self::assertNotNull($verified->last_probe_at);
        self::assertNull($verified->publication_error);
    }

    private function activeSite(string $code): Site
    {
        return Site::query()->create([
            'uuid'          => (string) Str::uuid(),
            'code'          => $code,
            'name'          => 'Tenant',
            'database_name' => str_replace('-', '_', $code),
            'status'        => Site::STATUS_ACTIVE,
            'locale'        => 'zh_cn',
            'timezone'      => 'Asia/Shanghai',
        ]);
    }

    private function pendingDomain(Site $site, string $token, string $host): Domain
    {
        return $site->domains()->create([
            'host'                    => $host,
            'scheme'                  => 'https',
            'is_primary'              => false,
            'routing_behavior'        => Domain::ROUTING_REDIRECT,
            'status'                  => Domain::STATUS_PENDING,
            'origin'                  => Domain::ORIGIN_TENANT,
            'verification_method'     => Domain::VERIFICATION_DNS_TXT,
            'verification_token'      => $token,
            'verification_expires_at' => now()->addHour(),
        ]);
    }

    private function verificationService(): DomainVerificationService
    {
        $dns = new class implements DnsTxtResolver
        {
            public function resolve(string $host): array
            {
                return [];
            }
        };

        return new DomainVerificationService(
            $dns,
            $this->routingDns(['192.0.2.10']),
            $this->trafficProbe(true),
            new DomainConfigWriter(new Filesystem),
            $this->createMock(SiteAuditLogger::class),
        );
    }

    /** @param array<int, string> $answers */
    private function routingDns(array $answers): DnsRouteResolver
    {
        return new class($answers) implements DnsRouteResolver
        {
            public function __construct(private readonly array $answers) {}

            public function resolve(string $host): array
            {
                return $this->answers;
            }
        };
    }

    private function trafficProbe(bool $ready): DomainTrafficProbe
    {
        return new class($ready) implements DomainTrafficProbe
        {
            public function __construct(private readonly bool $ready) {}

            public function probe(Domain $domain): array
            {
                return ['ok' => $this->ready, 'error' => $this->ready ? null : 'probe failed'];
            }
        };
    }
}
