<?php

namespace Tests\Unit;

use App\Saas\DomainConfigWriter;
use App\Saas\SiteAuditLogger;
use App\Saas\SiteProvisioner;
use App\Saas\TenantManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SaasSiteProvisionerTest extends TestCase
{
    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container               = new Container;
        $container->instance('config', new Repository([
            'saas' => [
                'max_batch_size'  => 100,
                'base_domain'     => 'shops.example.com',
                'control_domains' => ['admin.shops.example.com'],
            ],
        ]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_duplicate_domains_are_rejected_before_provisioning_starts(): void
    {
        $provisioner = new SiteProvisioner(
            $this->createMock(TenantManager::class),
            $this->createMock(DomainConfigWriter::class),
            $this->createMock(SiteAuditLogger::class),
        );

        $definitions = [
            [
                'code'          => 'site-a',
                'database_name' => 'longos_site_a',
                'domains'       => [['host' => 'shared.example.com']],
            ],
            [
                'code'          => 'site-b',
                'database_name' => 'longos_site_b',
                'domains'       => [['host' => 'shared.example.com']],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('重复 domain：shared.example.com');

        $provisioner->provisionMany($definitions);
    }

    public function test_handcrafted_definition_cannot_publish_an_unverified_customer_domain(): void
    {
        $provisioner = new SiteProvisioner(
            $this->createMock(TenantManager::class),
            $this->createMock(DomainConfigWriter::class),
            $this->createMock(SiteAuditLogger::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('自有域名必须完成 TXT 验证');

        $provisioner->provisionMany([[
            'code'          => 'unsafe-site',
            'database_name' => 'longos_unsafe_site',
            'domains'       => [['host' => 'shop.customer.example']],
        ]]);
    }

    public function test_platform_child_is_not_treated_as_control_when_control_host_is_the_apex(): void
    {
        Container::getInstance()->make('config')->set('saas.base_domain', 'example.com');
        Container::getInstance()->make('config')->set('saas.control_domains', ['example.com']);

        $domainConfig = $this->createMock(DomainConfigWriter::class);
        $domainConfig->expects(self::once())
            ->method('synchronized')
            ->willThrowException(new RuntimeException('platform host validation passed'));
        $provisioner = new SiteProvisioner(
            $this->createMock(TenantManager::class),
            $domainConfig,
            $this->createMock(SiteAuditLogger::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('platform host validation passed');

        $provisioner->provisionMany([[
            'code'          => 'tenant-one',
            'database_name' => 'longos_tenant_one',
            'domains'       => [['host' => 'tenant-one.example.com']],
        ]]);
    }

    public function test_handcrafted_platform_domains_are_normalized_and_respect_reserved_names(): void
    {
        Container::getInstance()->make('config')->set('saas.base_domain', 'example.com');
        Container::getInstance()->make('config')->set('saas.control_domains', ['example.com']);
        Container::getInstance()->make('config')->set('saas.domains.reserved_subdomains', ['admin']);
        $provisioner = new SiteProvisioner(
            $this->createMock(TenantManager::class),
            $this->createMock(DomainConfigWriter::class),
            $this->createMock(SiteAuditLogger::class),
        );

        foreach (['bad..example.com' => '无效', 'admin.example.com' => '系统保留'] as $host => $message) {
            try {
                $provisioner->provisionMany([[
                    'code'          => 'unsafe-site',
                    'database_name' => 'longos_unsafe_site',
                    'domains'       => [['host' => $host]],
                ]]);
                self::fail("Expected {$host} to be rejected.");
            } catch (RuntimeException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
    }
}
