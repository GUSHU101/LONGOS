<?php

namespace Tests\Unit;

use App\Saas\SiteDefinitionFactory;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SaasSiteDefinitionFactoryTest extends TestCase
{
    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container               = new Container;
        $container->instance('config', new Repository([
            'saas' => [
                'base_domain'        => 'example.com',
                'control_domains'    => ['admin.example.com'],
                'database_mode'      => 'prefix',
                'database_prefix'    => 'longos_',
                'max_batch_size'     => 100,
                'default_scheme'     => 'https',
            ],
        ]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_it_builds_deterministic_subdomains_and_database_names(): void
    {
        $factory = new SiteDefinitionFactory;
        $sites   = $factory->fromCount(2, [
            'prefix'          => 'brand',
            'name_prefix'     => '品牌站',
            'base_domain'     => 'example.com',
            'database_prefix' => 'tenant_',
            'admin_password'  => 'A-secure-password-123',
            'timezone'        => 'Asia/Shanghai',
            'locale'          => 'zh_cn',
        ]);

        self::assertSame('brand001', $sites[0]['code']);
        self::assertSame('brand001.example.com', $sites[0]['domains'][0]['host']);
        self::assertTrue($sites[0]['domains'][0]['is_primary']);
        self::assertSame('tenant_brand001', $sites[0]['database_name']);
        self::assertSame('prefix', $sites[0]['meta']['database_mode']);
        self::assertMatchesRegularExpression('/^lt_[a-f0-9]{12}_$/', $sites[0]['meta']['database_prefix']);
        self::assertNotSame($sites[0]['meta']['database_prefix'], $sites[1]['meta']['database_prefix']);
        self::assertSame('admin@brand001.example.com', $sites[0]['admin_email']);
        self::assertSame('brand002', $sites[1]['code']);
    }

    public function test_it_accepts_multiple_domains_and_marks_only_the_first_primary(): void
    {
        $site = (new SiteDefinitionFactory)->normalize([
            'code'           => 'demo',
            'name'           => 'Demo',
            'domains'        => 'demo.example.com|www.demo.example.com',
            'database'       => 'tenant_demo',
            'admin_password' => 'A-secure-password-123',
        ]);

        self::assertCount(2, $site['domains']);
        self::assertTrue($site['domains'][0]['is_primary']);
        self::assertFalse($site['domains'][1]['is_primary']);
    }

    public function test_it_canonicalizes_legacy_timezone_aliases(): void
    {
        $site = (new SiteDefinitionFactory)->normalize([
            'code'           => 'timezone-demo',
            'admin_password' => 'A-secure-password-123',
            'timezone'       => 'PRC',
        ]);

        self::assertSame('Asia/Shanghai', $site['timezone']);
    }

    public function test_it_rejects_weak_passwords_and_protected_control_metadata(): void
    {
        $factory = new SiteDefinitionFactory;

        $site = $factory->normalize([
            'code'           => 'safe',
            'domain'         => 'safe.example.com',
            'admin_password' => 'A-secure-password-123',
            'meta'           => [
                'control_site'              => true,
                'database_created'          => true,
                'database_creation_pending' => ['lease_hash' => 'untrusted'],
                'isolate_files'             => false,
            ],
        ]);
        self::assertArrayNotHasKey('control_site', $site['meta']);
        self::assertArrayNotHasKey('database_created', $site['meta']);
        self::assertArrayNotHasKey('database_creation_pending', $site['meta']);
        self::assertFalse($site['meta']['isolate_files']);

        $this->expectException(InvalidArgumentException::class);
        $factory->normalize([
            'code'           => 'weak',
            'domain'         => 'weak.example.com',
            'admin_password' => 'too-short',
        ]);
    }

    public function test_it_rejects_reserved_platform_subdomains(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('保留子域名');

        (new SiteDefinitionFactory)->normalize(['code' => 'admin', 'name' => 'Reserved']);
    }

    public function test_it_rejects_oversized_batches_before_generating_credentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('单次最多定义 100 个站点');

        (new SiteDefinitionFactory)->fromCount(101, ['base_domain' => 'example.com']);
    }

    public function test_long_valid_subdomains_get_safe_deterministic_database_identifiers(): void
    {
        $code = str_repeat('a', 63);
        $site = (new SiteDefinitionFactory)->normalize([
            'code'           => $code,
            'domain'         => "{$code}.example.com",
            'admin_password' => 'A-secure-password-123',
        ]);

        self::assertSame(63, strlen($site['code']));
        self::assertLessThanOrEqual(64, strlen($site['database_name']));
        self::assertMatchesRegularExpression('/^longos_[a-z0-9_]+$/', $site['database_name']);
    }

    public function test_batch_definition_rejects_customer_base_and_control_domains(): void
    {
        $factory = new SiteDefinitionFactory;

        foreach (['shop.customer.test', 'example.com', 'admin.example.com'] as $host) {
            try {
                $factory->normalize([
                    'code'           => 'unsafe-' . substr(hash('sha256', $host), 0, 8),
                    'domain'         => $host,
                    'admin_password' => 'A-secure-password-123',
                ]);
                self::fail("Expected {$host} to be rejected.");
            } catch (InvalidArgumentException $exception) {
                self::assertTrue(
                    str_contains($exception->getMessage(), '平台子域名')
                    || str_contains($exception->getMessage(), '控制站域名'),
                );
            }
        }
    }

    public function test_control_apex_can_also_be_the_platform_base_domain(): void
    {
        Container::getInstance()->make('config')->set('saas.control_domains', ['example.com']);

        $site = (new SiteDefinitionFactory)->normalize([
            'code'           => 'tenant-one',
            'admin_password' => 'A-secure-password-123',
        ]);

        self::assertSame('tenant-one.example.com', $site['domains'][0]['host']);
    }

    public function test_explicit_domain_cannot_bypass_reserved_platform_subdomains(): void
    {
        Container::getInstance()->make('config')->set('saas.control_domains', ['example.com']);
        Container::getInstance()->make('config')->set('saas.domains.reserved_subdomains', ['admin']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('已被系统保留');

        (new SiteDefinitionFactory)->normalize([
            'code'           => 'safe-code',
            'domain'         => 'admin.example.com',
            'admin_password' => 'A-secure-password-123',
        ]);
    }
}
