<?php

namespace Tests\Feature;

use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class SaasPreflightTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_preflight_rejects_an_unconfigured_local_environment(): void
    {
        config([
            'app.env'               => 'local',
            'app.url'               => '',
            'saas.enabled'          => false,
            'saas.base_domain'      => null,
            'queue.default'         => 'sync',
            'session.secure'        => false,
            'beike.force_url_https' => false,
        ]);

        self::assertSame(1, Artisan::call('saas:preflight', [
            '--skip-database' => true,
            '--json'          => true,
        ]));

        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($result['ready']);
        self::assertGreaterThan(0, $result['summary']['failed']);
    }

    public function test_preflight_rejects_a_malformed_application_key(): void
    {
        config([
            'app.key'           => 'base64:not-valid-base64',
            'app.previous_keys' => ['base64:' . base64_encode(random_bytes(16))],
        ]);

        self::assertSame(1, Artisan::call('saas:preflight', [
            '--skip-database' => true,
            '--json'          => true,
        ]));

        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(
            [
                'name'    => '应用密钥',
                'status'  => 'FAIL',
                'message' => 'APP_KEY 缺失、Base64 无效或长度与加密算法不匹配',
            ],
            $result['checks'],
        );
    }

    public function test_preflight_rejects_an_incomplete_enabled_mail_relay_without_exposing_secrets(): void
    {
        config([
            'mail.relay.enabled'                      => true,
            'mail.default'                            => 'self_hosted',
            'mail.mailers.self_hosted.endpoint'       => 'http://mail-relay:3000',
            'mail.relay.longos_client_configured'     => true,
            'mail.relay.cloud_mail_client_configured' => true,
            'mail.relay.client_secrets_distinct'      => false,
            'mail.relay.domains'                      => 'mail.example.com',
            'mail.relay.allowed_sender_domains'       => ['unsigned.example.com'],
            'mail.relay.platform_sender_domain'       => 'unsigned.example.com',
            'mail.relay.public_url'                   => 'http://admin.example.com/_longos/mail-relay',
            'mail.from.address'                       => 'orders@unsigned.example.com',
        ]);

        self::assertSame(1, Artisan::call('saas:preflight', [
            '--skip-database' => true,
            '--json'          => true,
        ]));
        $output = Artisan::output();
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($result['ready']);
        self::assertContains(
            ['name' => '中继凭据隔离', 'status' => 'FAIL', 'message' => '站内投递与 Cloud Mail 应用网关不得共用同一内部中继密钥'],
            $result['checks'],
        );
        self::assertContains(
            ['name' => '平台发件域', 'status' => 'FAIL', 'message' => 'MAIL_RELAY_PLATFORM_SENDER_DOMAIN 必须是允许且已配置 DKIM 签名的发件域名'],
            $result['checks'],
        );
        self::assertStringNotContainsString('MAIL_RELAY_SHARED_SECRET=', $output);
        self::assertStringNotContainsString('CLOUD_MAIL_RELAY_SHARED_SECRET=', $output);
    }

    public function test_preflight_accepts_the_coolify_caddy_production_edge(): void
    {
        config([
            'longos.identity.owner'                   => 'LONGOS',
            'longos.auto_upgrade'                     => false,
            'app.env'                                 => 'production',
            'app.debug'                               => false,
            'app.key'                                 => 'base64:' . base64_encode(random_bytes(32)),
            'app.url'                                 => 'https://admin.example.com',
            'app.trust_hosts_enabled'                 => true,
            'beike.force_url_https'                   => true,
            'session.secure'                          => true,
            'session.domain'                          => null,
            'saas.enabled'                            => true,
            'saas.database_mode'                      => 'prefix',
            'saas.reject_unknown'                     => true,
            'saas.isolate_files'                      => true,
            'saas.idempotency.required'               => true,
            'saas.default_scheme'                     => 'https',
            'saas.base_domain'                        => 'example.com',
            'saas.domain_config.driver'               => 'caddy',
            'saas.domain_config.path'                 => storage_path('framework/testing/saas-external-domains.conf'),
            'saas.domain_config.manifest_path'        => storage_path('framework/testing/saas-external-domains.json'),
            'saas.tls.driver'                         => 'caddy',
            'saas.tls.email'                          => 'ops@longos-commerce.org',
            'saas.tls.external_control_domain'        => false,
            'saas.edge.platform'                      => 'coolify',
            'saas.dns_targets'                        => ['192.0.2.10'],
            'longos.network.proxy_token'              => bin2hex(random_bytes(32)),
            'queue.default'                           => 'database',
            'queue.connections.database.connection'   => 'saas',
            'queue.failed.driver'                     => 'database-uuids',
            'queue.failed.database'                   => 'saas',
            'mail.relay.enabled'                      => true,
            'mail.default'                            => 'self_hosted',
            'mail.mailers.self_hosted.endpoint'       => 'http://mail-relay:3000',
            'mail.relay.longos_client_configured'     => true,
            'mail.relay.cloud_mail_client_configured' => true,
            'mail.relay.client_secrets_distinct'      => true,
            'mail.relay.domains'                      => 'mail.example.com',
            'mail.relay.allowed_sender_domains'       => ['mail.example.com'],
            'mail.relay.platform_sender_domain'       => 'mail.example.com',
            'mail.relay.public_url'                   => 'https://admin.example.com/_longos/mail-relay',
            'mail.from.address'                       => 'orders@mail.example.com',
        ]);

        self::assertSame(0, Artisan::call('saas:preflight', [
            '--skip-database' => true,
            '--json'          => true,
        ]));
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        self::assertContains(
            ['name' => 'HTTPS 管理方式', 'status' => 'PASS', 'message' => 'Coolify Caddy On-Demand TLS 已启用'],
            $result['checks'],
        );
        self::assertContains(
            ['name' => '域名动态发布', 'status' => 'PASS', 'message' => 'Caddy ask 实时授权，无需重载代理'],
            $result['checks'],
        );
        config(['mail.relay.configured_from_address_rejected' => true]);
        self::assertSame(0, Artisan::call('saas:preflight', [
            '--skip-database' => true,
            '--json'          => true,
        ]));
        $fallbackResult = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(
            [
                'name'    => '发件地址覆盖值',
                'status'  => 'WARN',
                'message' => '已忽略无效或未签名的 MAIL_FROM_ADDRESS，并安全回退到平台发件域',
            ],
            $fallbackResult['checks'],
        );
        self::assertContains(
            ['name' => '商城核心路由', 'status' => 'PASS', 'message' => '首页、购物车、结账、地址联想与数据看板路由齐全'],
            $result['checks'],
        );
        self::assertContains(
            ['name' => '前端编译产物', 'status' => 'PASS', 'message' => '商城、后台与可视化编辑器资源已编译并与 manifest 一致'],
            $result['checks'],
        );
        self::assertContains(
            ['name' => '平台发件域', 'status' => 'PASS', 'message' => 'mail.example.com'],
            $result['checks'],
        );

        config(['mail.relay.public_url' => 'https://shop-a.example.com/_longos/mail-relay']);
        self::assertSame(1, Artisan::call('saas:preflight', [
            '--skip-database' => true,
            '--json'          => true,
        ]));
        $wrongHost = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(
            [
                'name'    => 'Cloud Mail 中继 URL',
                'status'  => 'FAIL',
                'message' => 'MAIL_RELAY_PUBLIC_URL 必须是主站控制域名下的 HTTPS /_longos/mail-relay URL',
            ],
            $wrongHost['checks'],
        );
    }

    public function test_preflight_rejects_unknown_deployment_platform_without_exposing_raw_values(): void
    {
        config([
            'saas.edge.platform' => 'secret-value-that-must-not-be-rendered',
        ]);

        Artisan::call('saas:preflight', [
            '--skip-database' => true,
            '--json'          => true,
        ]);
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        self::assertContains(
            [
                'name'    => 'Caddy 部署平台',
                'status'  => 'FAIL',
                'message' => 'LONGOS_DEPLOYMENT_PLATFORM 仅支持 coolify',
            ],
            $result['checks'],
        );

        $output = Artisan::output();
        self::assertStringNotContainsString('secret-value-that-must-not-be-rendered', $output);
    }

    public function test_prefix_mode_can_use_a_shared_database_separate_from_the_control_database(): void
    {
        $controlDatabase = tempnam(sys_get_temp_dir(), 'longos-control-');
        $sharedDatabase  = tempnam(sys_get_temp_dir(), 'longos-shared-');
        self::assertIsString($controlDatabase);
        self::assertIsString($sharedDatabase);

        $control = [
            'driver'                  => 'sqlite',
            'database'                => $controlDatabase,
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ];
        $tenant = [
            'driver'                  => 'sqlite',
            'database'                => $sharedDatabase,
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ];

        try {
            config([
                'database.default'            => 'saas',
                'database.connections.saas'   => $control,
                'database.connections.tenant' => $tenant,
            ]);
            DB::purge('saas');
            DB::purge('tenant');
            DB::setDefaultConnection('saas');

            self::assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]));

            $prefix = 'lt_fixture_';
            foreach ([
                'migrations', 'settings', 'admin_users', 'permissions', 'roles',
                'plugins', 'categories', 'customers', 'carts', 'products', 'orders',
            ] as $table) {
                Schema::connection('tenant')->create($prefix . $table, static function ($blueprint): void {
                    $blueprint->id();
                });
            }

            $site = Site::query()->create([
                'uuid'           => (string) Str::uuid(),
                'code'           => 'fixture',
                'name'           => 'Fixture Store',
                'database_name'  => 'logical_fixture',
                'status'         => Site::STATUS_ACTIVE,
                'locale'         => 'zh_cn',
                'timezone'       => 'Asia/Shanghai',
                'meta'           => ['database_mode' => 'prefix', 'database_prefix' => $prefix],
                'provisioned_at' => now(),
            ]);
            Domain::query()->create([
                'site_id'          => $site->id,
                'host'             => 'fixture.example.com',
                'scheme'           => 'https',
                'is_primary'       => true,
                'routing_behavior' => Domain::ROUTING_REDIRECT,
                'status'           => Domain::STATUS_ACTIVE,
                'origin'           => Domain::ORIGIN_PLATFORM,
                'published_at'     => now(),
            ]);

            config([
                'longos.identity.owner'                 => 'LONGOS',
                'longos.auto_upgrade'                   => false,
                'longos.network.proxy_token'            => str_repeat('a', 64),
                'app.env'                               => 'production',
                'app.debug'                             => false,
                'app.key'                               => 'base64:' . base64_encode(random_bytes(32)),
                'app.url'                               => 'https://admin.example.com',
                'app.trust_hosts_enabled'               => true,
                'beike.force_url_https'                 => true,
                'session.secure'                        => true,
                'session.domain'                        => null,
                'saas.enabled'                          => true,
                'saas.database_mode'                    => 'prefix',
                'saas.shared_database'                  => $sharedDatabase,
                'saas.reject_unknown'                   => true,
                'saas.isolate_files'                    => true,
                'saas.idempotency.required'             => true,
                'saas.default_scheme'                   => 'https',
                'saas.base_domain'                      => 'example.com',
                'saas.domain_config.driver'             => 'caddy',
                'saas.domain_config.path'               => storage_path('framework/testing/saas-prefix-domains.conf'),
                'saas.domain_config.manifest_path'      => storage_path('framework/testing/saas-prefix-domains.json'),
                'saas.tls.driver'                       => 'caddy',
                'saas.tls.email'                        => 'ops@longos-commerce.org',
                'saas.tls.external_control_domain'      => false,
                'saas.edge.platform'                    => 'coolify',
                'saas.dns_targets'                      => ['192.0.2.10'],
                'queue.default'                         => 'database',
                'queue.connections.database.connection' => 'saas',
                'queue.failed.driver'                   => 'database-uuids',
                'queue.failed.database'                 => 'saas',
                'cache.default'                         => 'array',
            ]);

            self::assertSame(0, Artisan::call('saas:preflight', ['--json' => true]));
            $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            self::assertContains(
                ['name' => '共享数据库目标', 'status' => 'PASS', 'message' => $sharedDatabase],
                $result['checks'],
            );
            self::assertContains(
                ['name' => '共享租户表结构', 'status' => 'PASS', 'message' => '全部已上线共享库租户均包含核心业务、插件、权限和管理员表'],
                $result['checks'],
            );
        } finally {
            DB::disconnect('saas');
            DB::disconnect('tenant');
            @unlink($controlDatabase);
            @unlink($sharedDatabase);
        }
    }

    public function test_preflight_accepts_a_complete_control_plane(): void
    {
        $connection = [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ];
        config([
            'database.default'                  => 'saas',
            'database.connections.saas'         => $connection,
            'database.connections.tenant_admin' => $connection,
        ]);
        DB::purge('saas');
        DB::setDefaultConnection('saas');

        self::assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]));
        self::assertTrue(Schema::connection('saas')->hasTable('saas_sites'));

        $site = Site::query()->create([
            'uuid'           => (string) Str::uuid(),
            'code'           => 'main',
            'name'           => 'LONGOS Main',
            'database_name'  => 'tenant_main',
            'status'         => Site::STATUS_ACTIVE,
            'locale'         => 'zh_cn',
            'timezone'       => 'Asia/Shanghai',
            'provisioned_at' => now(),
        ]);
        Domain::query()->create([
            'site_id'          => $site->id,
            'host'             => 'main.shops.example.com',
            'scheme'           => 'https',
            'is_primary'       => true,
            'routing_behavior' => Domain::ROUTING_REDIRECT,
            'status'           => Domain::STATUS_ACTIVE,
            'origin'           => Domain::ORIGIN_PLATFORM,
            'published_at'     => now(),
        ]);

        config([
            'longos.identity.owner'                 => 'LONGOS',
            'longos.auto_upgrade'                   => false,
            'longos.network.proxy_token'            => str_repeat('a', 64),
            'app.env'                               => 'production',
            'app.debug'                             => false,
            'app.key'                               => 'base64:' . base64_encode(random_bytes(32)),
            'app.url'                               => 'https://admin.example.com',
            'app.trust_hosts_enabled'               => true,
            'beike.force_url_https'                 => true,
            'session.secure'                        => true,
            'session.domain'                        => null,
            'saas.enabled'                          => true,
            'saas.database_mode'                    => 'database',
            'saas.reject_unknown'                   => true,
            'saas.isolate_files'                    => true,
            'saas.idempotency.required'             => true,
            'saas.default_scheme'                   => 'https',
            'saas.base_domain'                      => 'shops.example.com',
            'saas.domain_config.driver'             => 'caddy',
            'saas.domain_config.path'               => storage_path('framework/testing/saas-domains.conf'),
            'saas.domain_config.manifest_path'      => storage_path('framework/testing/saas-domains.json'),
            'saas.tls.driver'                       => 'caddy',
            'saas.tls.email'                        => 'ops@longos.com',
            'queue.default'                         => 'database',
            'queue.connections.database.connection' => 'saas',
            'queue.failed.driver'                   => 'database-uuids',
            'queue.failed.database'                 => 'saas',
        ]);

        self::assertSame(0, Artisan::call('saas:preflight', ['--json' => true]));
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($result['ready']);
        self::assertSame(0, $result['summary']['failed']);
        self::assertContains(
            ['name' => '主域名不变式', 'status' => 'PASS', 'message' => '每个已上线站点恰有一个已启用主域名'],
            $result['checks'],
        );
    }

    public function test_cloudflare_required_mode_requires_a_server_level_coolify_policy(): void
    {
        $this->configureCaddyPreflight([
            'longos.cloudflare.mode' => 'required',
        ]);

        self::assertSame(1, Artisan::call('saas:preflight', [
            '--skip-database' => true,
            '--json'          => true,
        ]));
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        self::assertContains(
            [
                'name'    => 'Cloudflare 严格入口',
                'status'  => 'FAIL',
                'message' => 'Coolify 集成 Caddy 的项目级标签无法安全修改服务器全局来源限制；请使用 optional/off，或在 Coolify 的服务器级 Caddy 动态配置中完成严格回源后再扩展此检查',
            ],
            $result['checks'],
        );
    }

    public function test_optional_cloudflare_token_is_advisory_and_does_not_manage_caddy_certificates(): void
    {
        $this->configureCaddyPreflight([
            'longos.cloudflare.mode'                 => 'optional',
            'longos.cloudflare.api_token_configured' => true,
        ]);

        self::assertSame(0, Artisan::call('saas:preflight', [
            '--skip-database' => true,
            '--json'          => true,
        ]));
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        self::assertContains(
            [
                'name'    => 'Cloudflare API Token',
                'status'  => 'PASS',
                'message' => 'Token 仅供 LONGOS DNS 自动化使用；证书仍由 Coolify Caddy 的 On-Demand TLS 管理',
            ],
            $result['checks'],
        );
    }

    /** @param array<string, mixed> $overrides */
    private function configureCaddyPreflight(array $overrides = []): void
    {
        config(array_merge([
            'longos.identity.owner'                              => 'LONGOS',
            'longos.auto_upgrade'                                => false,
            'longos.network.proxy_token'                         => str_repeat('a', 64),
            'app.env'                                            => 'production',
            'app.debug'                                          => false,
            'app.key'                                            => 'base64:' . base64_encode(random_bytes(32)),
            'app.url'                                            => 'https://admin.example.com',
            'app.trust_hosts_enabled'                            => true,
            'beike.force_url_https'                              => true,
            'session.secure'                                     => true,
            'session.domain'                                     => null,
            'saas.enabled'                                       => true,
            'saas.database_mode'                                 => 'database',
            'saas.reject_unknown'                                => true,
            'saas.isolate_files'                                 => true,
            'saas.idempotency.required'                          => true,
            'saas.default_scheme'                                => 'https',
            'saas.base_domain'                                   => 'shops.example.com',
            'saas.domain_config.driver'                          => 'caddy',
            'saas.domain_config.path'                            => storage_path('framework/testing/saas-domains.conf'),
            'saas.domain_config.manifest_path'                   => storage_path('framework/testing/saas-domains.json'),
            'saas.tls.driver'                                    => 'caddy',
            'saas.tls.email'                                     => 'ops@longos.com',
            'saas.dns_targets'                                   => ['192.0.2.10'],
            'queue.default'                                      => 'database',
            'queue.connections.database.driver'                  => 'database',
            'queue.connections.database.connection'              => 'saas',
            'queue.failed.driver'                                => 'database-uuids',
            'queue.failed.database'                              => 'saas',
            'longos.cloudflare.mode'                             => 'optional',
            'longos.cloudflare.api_token_configured'             => false,
            'longos.cloudflare.api_token_file_configured'        => false,
            'longos.cloudflare.api_token_environment_configured' => false,
        ], $overrides));
    }
}
