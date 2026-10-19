<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LongosIdentityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = dirname(__DIR__, 2);
    }

    public function test_product_metadata_has_one_longos_identity(): void
    {
        $identity = require $this->root . '/config/longos.php';
        $composer = json_decode((string) file_get_contents($this->root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $package  = json_decode((string) file_get_contents($this->root . '/package.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('LONGOS', $identity['identity']['name']);
        self::assertSame('LONGOS Commerce SaaS', $identity['identity']['product']);
        self::assertSame('OSL-3.0', $identity['legal']['license']);
        self::assertSame('longos/commerce-saas', $composer['name']);
        self::assertSame('OSL-3.0', $composer['license']);
        self::assertSame('longos-commerce-saas', $package['name']);
    }

    public function test_primary_product_surfaces_use_longos_while_preserving_provenance(): void
    {
        $readme    = (string) file_get_contents($this->root . '/README.md');
        $readmeZh  = (string) file_get_contents($this->root . '/README-zh-CN.md');
        $license   = (string) file_get_contents($this->root . '/LICENSE');
        $installer = (string) file_get_contents($this->root . '/beike/Console/Commands/BeikeShopInstall.php');

        self::assertStringStartsWith('# LONGOS Commerce SaaS', $readme);
        self::assertStringStartsWith('# LONGOS Commerce SaaS', $readmeZh);
        self::assertStringContainsString("protected \$signature = 'longos:install", $installer);
        self::assertStringContainsString("protected \$aliases = ['beikeshop:install']", $installer);
        self::assertStringContainsString("\$this->option('fresh') ? 'migrate:fresh' : 'migrate'", $installer);
        self::assertStringContainsString("\$this->callArtisan('saas:install'", $installer);
        self::assertStringContainsString("setValues(['SAAS_ENABLED' => 'true'])", $installer);
        self::assertStringContainsString('LONGOS Product and Provenance Notice', $license);
        self::assertStringContainsString('Open Software License ("OSL") v 3.0', $license);
        self::assertStringContainsString('BeikeShop', $license);
    }
}
