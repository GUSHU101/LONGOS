<?php

namespace Tests\Unit;

use App\Saas\DomainConfigWriter;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

class SaasDomainConfigWriterTest extends TestCase
{
    public function test_it_renders_a_caddy_authorization_audit_snapshot(): void
    {
        $writer = new DomainConfigWriter(new Filesystem);

        $caddy = $writer->renderCaddy(['a.example.com', 'b.example.com']);
        self::assertStringContainsString('On-Demand TLS', $caddy);
        self::assertStringContainsString('# active: a.example.com', $caddy);
        self::assertStringContainsString('# active: b.example.com', $caddy);
        self::assertStringNotContainsString('server_name', $caddy);
        self::assertStringNotContainsString('ServerAlias', $caddy);
    }

    public function test_domain_operation_lock_is_reentrant(): void
    {
        $previousContainer = Container::getInstance();
        $directory         = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'longos-domain-lock-' . bin2hex(random_bytes(6));
        mkdir($directory, 0777, true);

        try {
            $container = new Container;
            $container->instance('config', new Repository([
                'saas' => ['domain_config' => ['manifest_path' => $directory . DIRECTORY_SEPARATOR . 'domains.json']],
            ]));
            Container::setInstance($container);
            $writer = new DomainConfigWriter(new Filesystem);

            $result = $writer->synchronized(
                fn (): string => $writer->synchronized(fn (): string => 'locked')
            );

            self::assertSame('locked', $result);
            self::assertFileExists($directory . DIRECTORY_SEPARATOR . 'domains.json.lock');
        } finally {
            Container::setInstance($previousContainer);
            @unlink($directory . DIRECTORY_SEPARATOR . 'domains.json.lock');
            @rmdir($directory);
        }
    }

}
