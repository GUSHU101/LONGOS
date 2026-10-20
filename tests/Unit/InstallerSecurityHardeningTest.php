<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class InstallerSecurityHardeningTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_deployment_secrets_are_streamed_instead_of_exposed_as_process_arguments(): void
    {
        $entrypoint = $this->read('docker/nginx/start.sh');
        $command    = $this->read('beike/Console/Commands/BeikeShopInstall.php');

        self::assertStringContainsString("printf '%s\\0%s\\0'", $entrypoint);
        self::assertStringContainsString('--secrets-stdin', $entrypoint);
        self::assertStringNotContainsString('--db-password=', $entrypoint);
        self::assertStringNotContainsString('--admin-password=', $entrypoint);
        self::assertStringContainsString('{--secrets-stdin', $command);
        self::assertStringContainsString('explode("\\0", $payload)', $command);
        self::assertStringContainsString('php://stdin', $command);
    }

    public function test_redeployment_preserves_application_identity_and_uses_least_privilege_permissions(): void
    {
        $compose     = $this->read('docker-compose.yml');
        $entrypoint  = $this->read('docker/nginx/start.sh');
        $permissions = $this->read('scripts/container-permissions.sh');

        self::assertStringContainsString('APP_KEY_OVERRIDE:-base64:', $compose);
        self::assertStringContainsString('if [ ! -f storage/installed ]; then', $entrypoint);
        self::assertStringContainsString('saas:upgrade --no-maintenance --if-needed', $entrypoint);
        self::assertStringNotContainsString('key:generate', $entrypoint);
        self::assertStringContainsString('chmod', $permissions);
        self::assertStringNotContainsString('chmod -R 777', $permissions);
        self::assertStringContainsString('plugins public/cache public/plugin', $permissions);
        self::assertStringNotContainsString('database docs lang plugins public', $permissions);
    }

    public function test_image_dependencies_are_pinned_and_lockfile_driven(): void
    {
        $dockerfile = $this->read('docker/nginx/Dockerfile');

        self::assertStringContainsString('FROM composer:2.8.12', $dockerfile);
        self::assertStringContainsString('FROM node:22.22.0-bookworm-slim', $dockerfile);
        self::assertStringContainsString('FROM php:8.2.34-fpm-bookworm', $dockerfile);
        self::assertStringContainsString('COPY composer.json composer.lock', $dockerfile);
        self::assertStringContainsString('COPY package.json package-lock.json', $dockerfile);
        self::assertStringContainsString('composer install', $dockerfile);
        self::assertStringContainsString('composer dump-autoload', $dockerfile);
        self::assertStringContainsString('npm ci --ignore-scripts', $dockerfile);
        self::assertStringNotContainsString('npm install ', $dockerfile);
        self::assertStringContainsString(
            'COPY --from=composer-binary /usr/bin/composer /usr/local/bin/composer',
            $dockerfile
        );
        self::assertSame(1, substr_count($dockerfile, '/usr/bin/composer'));
    }

    public function test_deployment_never_mutates_the_host_or_bypasses_coolify_caddy(): void
    {
        $installer = $this->read('install.sh');
        $compose   = $this->read('docker-compose.yml');

        self::assertStringNotContainsString('configure_docker_registry_mirror()', $installer);
        self::assertStringNotContainsString('systemctl restart docker', $installer);
        self::assertStringNotContainsString('data["registry-mirrors"]', $installer);
        self::assertStringContainsString('No server-side install command is required.', $installer);
        self::assertStringContainsString('- "caddy_ingress_network=coolify"', $compose);
        self::assertStringNotContainsString("\n    ports:\n", $compose);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->root . '/' . $path);
    }
}
