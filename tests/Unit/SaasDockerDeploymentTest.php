<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SaasDockerDeploymentTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = dirname(__DIR__, 2);
    }

    public function test_coolify_caddy_is_the_only_public_edge(): void
    {
        $compose = $this->read('docker-compose.yml');

        self::assertStringContainsString('caddy.on_demand_tls.ask:', $compose);
        self::assertStringContainsString('caddy_ingress_network: coolify', $compose);
        self::assertStringContainsString('/_longos/tls/authorize', $compose);
        self::assertStringContainsString('caddy_0: "https://"', $compose);
        self::assertStringContainsString('caddy_0.tls.on_demand: ""', $compose);
        self::assertStringContainsString('caddy_0.0_respond: "@longosTlsAsk 404"', $compose);
        self::assertStringContainsString('caddy_0.reverse_proxy: "{{upstreams 80}}"', $compose);
        self::assertStringContainsString('X-Longos-Proxy-Token ${SERVICE_PASSWORD_64_PROXYTOKEN}', $compose);
        self::assertStringContainsString('X-Longos-Edge-Remote {http.request.remote.host}', $compose);
        self::assertStringContainsString("coolify:\n    external: true", $compose);
        self::assertStringNotContainsString(':80:80', $compose);
        self::assertStringNotContainsString(':443:443', $compose);
        self::assertStringNotContainsString('caddy-nginx:', $compose);
        self::assertStringNotContainsString('baota', strtolower($compose));
    }

    public function test_coolify_generates_and_reuses_every_sensitive_stack_value(): void
    {
        $compose = $this->read('docker-compose.yml');

        foreach ([
            'SERVICE_REALBASE64_32_APPKEY',
            'SERVICE_PASSWORD_64_DBROOT',
            'SERVICE_USER_DBUSER',
            'SERVICE_PASSWORD_64_DBPASSWORD',
            'SERVICE_PASSWORD_64_ADMIN',
            'SERVICE_PASSWORD_64_PROXYTOKEN',
            'SERVICE_PASSWORD_64_MAILRELAY',
            'SERVICE_PASSWORD_64_CLOUDMAIL',
        ] as $variable) {
            self::assertStringContainsString($variable, $compose);
        }

        self::assertStringContainsString('LONGOS_UPDATER_ENABLED: "false"', $compose);
        self::assertStringContainsString('APP_KEY_OVERRIDE:-base64:', $compose);
        self::assertStringContainsString('DOMAIN: ${DOMAIN:?Set the LONGOS control domain}', $compose);
        self::assertStringContainsString('ADMIN_EMAIL: ${ADMIN_EMAIL:-admin@${DOMAIN}}', $compose);
        self::assertStringContainsString('SAAS_TLS_EMAIL: ${SAAS_TLS_EMAIL:-${ADMIN_EMAIL:-admin@${DOMAIN}}}', $compose);
        self::assertStringNotContainsString('${ADMIN_EMAIL:?', $compose);
        self::assertStringContainsString("env('SAAS_CONTROL_DOMAINS', '')", $this->read('config/saas.php'));
        self::assertStringContainsString('SAAS_TLS_EXTERNAL_CONTROL_DOMAIN: "false"', $compose);
        self::assertStringContainsString('MAIL_RELAY_SHARED_SECRET: ${SERVICE_PASSWORD_64_MAILRELAY}', $compose);
        self::assertStringContainsString('CLOUD_MAIL_RELAY_SHARED_SECRET: ${SERVICE_PASSWORD_64_CLOUDMAIL}', $compose);
        self::assertStringContainsString('CLOUDFLARE_API_TOKEN: ${CLOUDFLARE_API_TOKEN:-}', $compose);
        self::assertStringContainsString('LONGOS_AUTO_CONFIGURE_MAIL_DNS: ${LONGOS_AUTO_CONFIGURE_MAIL_DNS:-true}', $compose);
    }

    public function test_image_is_immutable_and_dependencies_are_lockfile_driven(): void
    {
        $dockerfile   = $this->read('docker/nginx/Dockerfile');
        $dockerignore = $this->read('.dockerignore');
        $package      = json_decode($this->read('package.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('FROM composer:2.8.12 AS composer-binary', $dockerfile);
        self::assertStringContainsString('FROM node:22.22.0-bookworm-slim AS browser-assets', $dockerfile);
        self::assertStringContainsString('FROM php:8.2.34-fpm-bookworm AS runtime-base', $dockerfile);
        self::assertStringContainsString('FROM runtime-base AS php-dependencies', $dockerfile);
        self::assertStringContainsString('FROM runtime-base AS runtime', $dockerfile);
        self::assertStringContainsString('COPY --from=composer-binary /usr/bin/composer /usr/local/bin/composer', $dockerfile);
        self::assertStringContainsString('ENV NODE_OPTIONS=--max-old-space-size=1536', $dockerfile);
        self::assertStringContainsString('ARG PHP_BUILD_JOBS=2', $dockerfile);
        self::assertStringContainsString('docker-php-ext-install -j"${PHP_BUILD_JOBS}"', $dockerfile);
        self::assertStringContainsString('composer install', $dockerfile);
        self::assertStringContainsString('--no-dev', $dockerfile);
        self::assertStringContainsString('--no-autoloader', $dockerfile);
        self::assertStringContainsString('COPY beike ./beike', $dockerfile);
        self::assertStringContainsString('composer dump-autoload', $dockerfile);
        self::assertStringContainsString('--optimize', $dockerfile);
        self::assertStringContainsString('npm ci --ignore-scripts', $dockerfile);
        self::assertStringContainsString('npm run production', $dockerfile);
        self::assertStringContainsString('COPY --from=php-dependencies /build/vendor ./vendor', $dockerfile);
        self::assertSame(1, substr_count($dockerfile, '/usr/bin/composer'));
        self::assertStringNotContainsString('--ignore-platform-req', $dockerfile);
        self::assertSame(
            1,
            preg_match('/docker-php-ext-install.*?(?=\\s+&& php -r)/s', $dockerfile, $compiledExtensionBlock)
        );
        foreach (['pdo_mysql', 'pdo_pgsql', 'exif', 'pcntl', 'bcmath', 'gd', 'intl', 'zip', 'opcache'] as $extension) {
            self::assertMatchesRegularExpression('/^\\s*' . preg_quote($extension, '/') . '\\s*\\\\?$/m', $compiledExtensionBlock[0]);
        }
        self::assertStringContainsString('Missing required PHP extensions:', $dockerfile);
        self::assertStringContainsString('extension_loaded($extension)', $dockerfile);
        self::assertStringContainsString('"Zend OPcache"', $dockerfile);
        foreach (['curl', 'mbstring', 'posix', 'dom', 'simplexml', 'xml', 'xmlreader', 'xmlwriter'] as $extension) {
            self::assertStringContainsString('"' . $extension . '"', $dockerfile);
            self::assertDoesNotMatchRegularExpression('/^\\s*' . preg_quote($extension, '/') . '\\s*\\\\?$/m', $compiledExtensionBlock[0]);
        }
        self::assertStringContainsString("\n.env\n", $dockerignore);
        self::assertStringContainsString("\nnode_modules\n", $dockerignore);
        self::assertStringContainsString("\nvendor\n", $dockerignore);
        self::assertStringContainsString("\ntests\n", $dockerignore);
        self::assertStringContainsString("\nplugins/BeikeShopTestSandbox\n", $dockerignore);
        self::assertStringContainsString("\ndocs\n", $dockerignore);
        self::assertStringContainsString("\nscripts/*\n!scripts/container-permissions.sh\n", $dockerignore);
        self::assertStringContainsString("\nbootstrap/cache/*\n!bootstrap/cache/.gitignore\n", $dockerignore);
        self::assertStringContainsString("\npublic/build\n", $dockerignore);
        self::assertSame(
            'npm run production && npm test && npm run audit:production',
            $package['scripts']['check'] ?? null,
            'A clean checkout must build the Mix manifest before any HTTP/preflight test renders an asset-backed view.'
        );
    }

    public function test_entrypoint_automates_first_install_and_safe_upgrade(): void
    {
        $entrypoint = $this->read('docker/nginx/start.sh');

        $installAt = strpos($entrypoint, 'php artisan longos:install');
        $upgradeAt = strpos($entrypoint, 'php artisan saas:upgrade --no-maintenance --if-needed');
        $permissionsAt = strrpos($entrypoint, 'sh scripts/container-permissions.sh');
        $webProcessesAt = strrpos($entrypoint, "\n    run_web_processes");

        self::assertIsInt($installAt);
        self::assertIsInt($upgradeAt);
        self::assertIsInt($permissionsAt);
        self::assertIsInt($webProcessesAt);
        self::assertGreaterThan($upgradeAt, $permissionsAt);
        self::assertLessThan($webProcessesAt, $permissionsAt);
        self::assertStringContainsString('flock -x 9', $entrypoint);
        self::assertStringContainsString('storage/framework/longos-deploy.lock', $entrypoint);
        self::assertMatchesRegularExpression('/flock -x 9\s+ensure_runtime_tree\s+initialize_or_upgrade[\s\S]{0,600}?sh scripts\/container-permissions\.sh\s+flock -u 9/', $entrypoint);
        self::assertStringContainsString('sync_release_tree /opt/longos-seed/plugins plugins', $entrypoint);
        self::assertStringContainsString('.longos-release-manifest', $entrypoint);
        self::assertStringContainsString('.${name}.longos-old', $entrypoint);
        self::assertStringContainsString('mv "$previous" "$target_root/$name"', $entrypoint);
        self::assertStringContainsString('grep -Fqx -- "$old_name" "$next_manifest"', $entrypoint);
        self::assertStringNotContainsString('cp -a /opt/longos-seed/plugins/. plugins/', $entrypoint);
        self::assertStringContainsString('--secrets-stdin', $entrypoint);
        self::assertStringNotContainsString('--admin-password=', $entrypoint);
        self::assertStringNotContainsString('--db-password=', $entrypoint);
        self::assertStringContainsString('php artisan optimize', $entrypoint);
        self::assertStringContainsString('refresh_package_manifest', $entrypoint);
        self::assertStringContainsString('rm -f bootstrap/cache/packages.php bootstrap/cache/services.php', $entrypoint);
        self::assertStringContainsString('php-fpm -F &', $entrypoint);
        self::assertStringContainsString('kill -0 "$php_pid"', $entrypoint);
        self::assertStringContainsString('shutdown_web_processes', $entrypoint);
        self::assertStringNotContainsString('php-fpm -D', $entrypoint);
    }

    public function test_background_services_and_state_are_persistent(): void
    {
        $compose = $this->read('docker-compose.yml');
        $relay   = $this->read('docker/mail-relay/entrypoint.sh');
        $ci      = $this->read('.github/workflows/ci.yml');

        self::assertStringContainsString('command: ["worker"]', $compose);
        self::assertStringContainsString('command: ["scheduler"]', $compose);
        self::assertStringContainsString('condition: service_healthy', $compose);
        self::assertMatchesRegularExpression('/mail-relay:\\R(?:.*\\R){0,5}\\s*condition: service_started/', $compose);
        self::assertStringContainsString('longos-storage:/var/www/html/storage', $compose);
        self::assertStringContainsString('mysql-data:/var/lib/mysql', $compose);
        self::assertStringContainsString('mail-relay-dkim:/var/lib/dkim', $compose);
        self::assertStringContainsString('mail-relay-state:/var/lib/mail-relay-state', $compose);
        self::assertStringContainsString('mysql:8.4.11', $compose);
        self::assertStringContainsString('FROM node:22.22.0-bookworm-slim', $this->read('docker/mail-relay/Dockerfile'));
        self::assertStringNotContainsString('longos-app:latest', $compose);
        self::assertStringNotContainsString('longos-mail-relay:latest', $compose);
        self::assertStringContainsString('postfix status >/dev/null 2>&1', $relay);
        self::assertStringContainsString('pgrep -x opendkim >/dev/null 2>&1', $relay);
        self::assertStringContainsString('A required mail relay process exited', $relay);
        self::assertStringContainsString('--tries=5 --backoff=30', $this->read('docker/nginx/start.sh'));
        self::assertStringContainsString('wait_for_healthy worker', $ci);
        self::assertStringContainsString('http://127.0.0.1/_longos/tls/authorize', $ci);
        self::assertStringContainsString('unregistered.example.test', $ci);
        self::assertStringContainsString('dkim_before=', $ci);
        self::assertStringContainsString('test "$dkim_after" = "$dkim_before"', $ci);
    }

    public function test_public_php_execution_and_health_check_remain_fail_closed(): void
    {
        $nginx      = $this->read('docker/nginx/nginx-site.conf');
        $dockerfile = $this->read('docker/nginx/Dockerfile');
        $phpIni     = $this->read('docker/nginx/php-production.ini');
        $front      = $this->read('public/index.php');
        $health     = $this->read('app/Http/Controllers/HealthController.php');
        $compose    = $this->read('docker-compose.yml');

        self::assertStringContainsString('location = /index.php', $nginx);
        self::assertStringContainsString('location ~ \.php(?:/|$)', $nginx);
        self::assertStringNotContainsString('location ~ \.php$ {', $nginx);
        self::assertStringContainsString('location ^~ /build/', $nginx);
        self::assertStringContainsString('max-age=31536000, immutable', $nginx);
        self::assertStringContainsString('max-age=300', $nginx);
        self::assertStringNotContainsString('expires 30d;', $nginx);
        self::assertStringContainsString("DB::connection(\$connection)->select('SELECT 1')", $health);
        self::assertStringContainsString("['status' => 'degraded'], 503", $health);
        self::assertStringContainsString('http://127.0.0.1/api/health', $compose);
        self::assertStringContainsString('COPY docker/nginx/php-production.ini /usr/local/etc/php/conf.d/zz-longos-production.ini', $dockerfile);
        self::assertStringContainsString('display_errors = Off', $phpIni);
        self::assertStringContainsString('display_startup_errors = Off', $phpIni);
        self::assertStringContainsString('log_errors = On', $phpIni);
        self::assertStringContainsString('expose_php = Off', $phpIni);
        self::assertStringNotContainsString("touch(\$htaPath)", $front);
        self::assertStringNotContainsString("copy(\$htaExamplePath, \$htaPath)", $front);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->root . '/' . $path);
    }
}

