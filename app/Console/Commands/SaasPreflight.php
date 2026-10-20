<?php

namespace App\Console\Commands;

use App\Saas\CaddyEdgeStatus;
use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use App\Saas\Support\SensitiveValueRedactor;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SaasPreflight extends Command
{
    protected $signature = 'saas:preflight
        {--skip-database : 仅检查应用和部署配置}
        {--strict : 将部署告警也视为失败}
        {--json : 输出可供 CI/CD 解析的 JSON}';

    protected $description = '以只读方式检查 LONGOS SaaS 上线所需的运行时、安全配置和控制库不变式';

    /** @var array<int, array{name: string, status: string, message: string}> */
    private array $checks = [];

    public function handle(): int
    {
        $this->checks = [];
        $this->checkRuntime();
        $this->checkApplication();
        $this->checkCommerceApplication();
        $this->checkSaasConfiguration();
        $this->checkDeploymentConfiguration();
        $this->checkMailConfiguration();

        if (! $this->option('skip-database')) {
            $this->checkControlDatabase();
            $this->checkProvisioningDatabase();
            $this->checkCacheDatabase();
        }

        $summary = [
            'passed'   => count(array_filter($this->checks, static fn (array $check): bool => $check['status'] === 'PASS')),
            'warnings' => count(array_filter($this->checks, static fn (array $check): bool => $check['status'] === 'WARN')),
            'failed'   => count(array_filter($this->checks, static fn (array $check): bool => $check['status'] === 'FAIL')),
        ];
        $ready = $summary['failed'] === 0 && (! $this->option('strict') || $summary['warnings'] === 0);

        if ($this->option('json')) {
            $this->line(json_encode([
                'product' => (string) config('longos.identity.product', 'LONGOS Commerce SaaS'),
                'ready'   => $ready,
                'strict'  => (bool) $this->option('strict'),
                'summary' => $summary,
                'checks'  => $this->checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['检查项', '结果', '说明'], array_map(
                static fn (array $check): array => [$check['name'], $check['status'], $check['message']],
                $this->checks,
            ));
            $this->newLine();
            $message = "通过 {$summary['passed']} / 告警 {$summary['warnings']} / 失败 {$summary['failed']}";
            $ready ? $this->components->info("上线预检通过：{$message}") : $this->components->error("上线预检未通过：{$message}");
        }

        return $ready ? self::SUCCESS : self::FAILURE;
    }

    private function checkRuntime(): void
    {
        $this->requirement('PHP 版本', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION, '至少需要 PHP 8.2');

        $missing = array_values(array_filter(
            ['bcmath', 'curl', 'exif', 'fileinfo', 'gd', 'iconv', 'intl', 'json', 'mbstring', 'openssl', 'pdo', 'simplexml', 'zip'],
            static fn (string $extension): bool => ! extension_loaded($extension),
        ));
        $this->requirement('PHP 扩展', $missing === [], '已安装必需扩展', '缺少：' . implode(', ', $missing));
        $this->advisory('长驻进程扩展', extension_loaded('pcntl'), 'pcntl 已启用', 'Linux 队列 Worker 应启用 pcntl 以正确处理退出信号');
        $this->advisory('PHP OPcache', extension_loaded('Zend OPcache'), 'OPcache 已启用', '生产环境应启用 OPcache');
    }

    private function checkApplication(): void
    {
        $this->requirement('LONGOS 归属', strtoupper((string) config('longos.identity.owner')) === 'LONGOS', '产品归属为 LONGOS', 'LONGOS_OWNER 必须为 LONGOS');
        $this->requirement('运行环境', config('app.env') === 'production', 'APP_ENV=production', '当前 APP_ENV=' . (string) config('app.env'));
        $this->requirement('调试模式', config('app.debug') === false, 'APP_DEBUG=false', '生产环境必须关闭 APP_DEBUG');
        $cipher         = (string) config('app.cipher');
        $applicationKey = $this->decodeApplicationKey((string) config('app.key'));
        $this->requirement(
            '应用密钥',
            $applicationKey !== null && Encrypter::supported($applicationKey, $cipher),
            "APP_KEY 与 {$cipher} 匹配",
            'APP_KEY 缺失、Base64 无效或长度与加密算法不匹配',
        );
        $previousKeys        = array_values((array) config('app.previous_keys', []));
        $decodedPreviousKeys = array_map(fn (mixed $key): ?string => $this->decodeApplicationKey((string) $key), $previousKeys);
        $validPreviousKeys   = ! in_array(false, array_map(
            static fn (?string $key): bool => $key !== null && Encrypter::supported($key, $cipher),
            $decodedPreviousKeys,
        ), true)
            && count($decodedPreviousKeys) === count(array_unique($decodedPreviousKeys, SORT_STRING))
            && ($applicationKey === null || ! in_array($applicationKey, $decodedPreviousKeys, true));
        $this->requirement(
            '历史密钥',
            $validPreviousKeys,
            $previousKeys === [] ? '未配置退役密钥' : '退役密钥格式有效且不重复',
            'APP_PREVIOUS_KEYS 包含无效、重复或与当前 APP_KEY 相同的密钥',
        );

        $url = trim((string) config('app.url'));
        $this->requirement('应用 URL', filter_var($url, FILTER_VALIDATE_URL) !== false && str_starts_with(strtolower($url), 'https://'), $url, 'APP_URL 必须是完整 HTTPS URL');
        $this->requirement('HTTPS 强制', (bool) config('beike.force_url_https'), 'APP_FORCE_HTTPS=true', '未启用 APP_FORCE_HTTPS');
        $this->requirement('安全 Cookie', (bool) config('session.secure'), 'SESSION_SECURE_COOKIE 已启用', 'Session Cookie 可通过非 HTTPS 发送');
        $this->requirement('HttpOnly Cookie', (bool) config('session.http_only'), '已禁止 JavaScript 读取 Session Cookie', 'SESSION_HTTP_ONLY 必须启用');
        $this->requirement('SameSite Cookie', in_array(config('session.same_site'), ['lax', 'strict'], true), (string) config('session.same_site'), 'SESSION_SAME_SITE 必须为 lax 或 strict');
        $this->requirement('租户 Cookie 边界', config('session.domain') === null || config('session.domain') === '', '使用 Host-only Cookie', 'SESSION_DOMAIN 不得跨租户子域共享');
    }

    /**
     * Verify the storefront/admin release as a complete product rather than
     * treating a bootable Laravel kernel as proof that checkout is usable.
     * These checks catch partial release extraction and missing build artifacts
     * before the edge is enabled and customers encounter a HTTP 500 page.
     */
    private function checkCommerceApplication(): void
    {
        $requiredRoutes = [
            'shop.home.index',
            'shop.carts.index',
            'shop.carts.store',
            'shop.checkout.index',
            'shop.checkout.update',
            'shop.checkout.confirm',
            'shop.checkout.address-suggestions',
            'admin.dashboard.overview',
            'admin.dashboard.export',
        ];
        $missingRoutes = array_values(array_filter(
            $requiredRoutes,
            static fn (string $name): bool => ! app('router')->has($name),
        ));
        $this->requirement(
            '商城核心路由',
            $missingRoutes === [],
            '首页、购物车、结账、地址联想与数据看板路由齐全',
            '缺少路由：' . implode(', ', $missingRoutes),
        );

        $requiredFiles = [
            'themes/default/home.blade.php',
            'themes/default/cart/cart.blade.php',
            'themes/default/checkout.blade.php',
            'themes/default/checkout/_address_inline.blade.php',
            'themes/default/checkout/success.blade.php',
            'resources/beike/admin/views/pages/dashboard/charts_data.blade.php',
            'resources/beike/admin/js/admin-dashboard.js',
            'beike/Shop/Services/AddressAutocompleteService.php',
            'beike/Services/ShippingMethodService.php',
            'plugins/FlatShipping/Bootstrap.php',
            'plugins/Paypal/Controllers/PaypalController.php',
            'plugins/Paypal/Views/checkout/payment.blade.php',
            'plugins/Stripe/Controllers/StripeController.php',
            'plugins/Stripe/Views/checkout/payment.blade.php',
            'plugins/PixelTracking/Services/ServerTrackingService.php',
        ];
        $missingFiles = array_values(array_filter(
            $requiredFiles,
            static fn (string $path): bool => ! is_file(base_path($path)) || filesize(base_path($path)) === 0,
        ));
        $this->requirement(
            '商城功能文件',
            $missingFiles === [],
            '结账、地址、运费、PayPal、Stripe、像素追踪与看板文件齐全',
            '发布包不完整：' . implode(', ', $missingFiles),
        );

        $pluginContracts = [
            'FlatShipping' => [\Plugin\FlatShipping\Bootstrap::class, 'getQuotes'],
            'PayPal'       => [\Plugin\Paypal\Services\PaypalService::class, null],
            'Stripe'       => [\Plugin\Stripe\Services\StripeService::class, null],
            '像素追踪'     => [\Plugin\PixelTracking\Services\ServerTrackingService::class, null],
        ];
        $invalidPlugins = [];
        foreach ($pluginContracts as $label => [$class, $method]) {
            if (! class_exists($class) || ($method !== null && ! method_exists($class, $method))) {
                $invalidPlugins[] = $label;
            }
        }
        $this->requirement(
            '插件运行契约',
            $invalidPlugins === [],
            '运费、PayPal、Stripe 与服务端追踪可由 Composer 正常加载',
            '无法加载：' . implode(', ', $invalidPlugins),
        );

        $addressEndpoint = trim((string) config('services.address_lookup.endpoint'));
        $endpointParts   = parse_url($addressEndpoint);
        $endpointValid   = is_array($endpointParts)
            && strtolower((string) ($endpointParts['scheme'] ?? '')) === 'https'
            && trim((string) ($endpointParts['host'] ?? '')) !== ''
            && (int) ($endpointParts['port'] ?? 443) === 443
            && ! isset($endpointParts['user'])
            && ! isset($endpointParts['pass']);
        $this->requirement(
            '地址联想服务',
            $endpointValid,
            $addressEndpoint,
            'ADDRESS_LOOKUP_ENDPOINT 必须是不带账号密码的公网 HTTPS 443 URL',
        );

        $manifestPath = public_path('mix-manifest.json');
        $manifest     = null;
        if (is_file($manifestPath)) {
            try {
                $decoded  = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
                $manifest = is_array($decoded) ? $decoded : null;
            } catch (Throwable) {
                $manifest = null;
            }
        }

        $requiredAssets = [
            '/build/beike/admin/js/app.js',
            '/build/beike/admin/js/puck-builder.js',
            '/build/beike/admin/css/app.css',
            '/build/beike/shop/default/js/app.js',
            '/build/beike/shop/default/css/app.css',
        ];
        $missingAssets = [];
        foreach ($requiredAssets as $asset) {
            $compiled = is_array($manifest) ? ($manifest[$asset] ?? '') : '';
            $path     = is_string($compiled) ? (string) parse_url($compiled, PHP_URL_PATH) : '';
            if ($path === '' || ! is_file(public_path(ltrim($path, '/'))) || filesize(public_path(ltrim($path, '/'))) === 0) {
                $missingAssets[] = $asset;
            }
        }
        $this->requirement(
            '前端编译产物',
            $manifest !== null && $missingAssets === [],
            '商城、后台与可视化编辑器资源已编译并与 manifest 一致',
            '缺少或损坏：' . implode(', ', $missingAssets ?: ['public/mix-manifest.json']),
        );
    }

    private function checkSaasConfiguration(): void
    {
        $this->requirement('SaaS 开关', (bool) config('saas.enabled'), 'SAAS_ENABLED=true', 'SaaS 尚未启用');
        $databaseMode = (string) config('saas.database_mode', 'prefix');
        $this->requirement(
            '租户数据模式',
            in_array($databaseMode, ['prefix', 'database'], true),
            $databaseMode === 'prefix' ? '共享数据库 + 独立租户表前缀' : '每租户独立数据库',
            'SAAS_DATABASE_MODE 仅支持 prefix 或 database',
        );
        $this->requirement('Host 白名单', (bool) config('app.trust_hosts_enabled'), 'TRUST_HOSTS_ENABLED=true', '未启用可信 Host 验证');
        $this->requirement('未知域名隔离', (bool) config('saas.reject_unknown'), '未绑定 Host 将被拒绝', 'SAAS_REJECT_UNKNOWN_DOMAINS 必须为 true');
        $this->requirement('租户文件隔离', (bool) config('saas.isolate_files'), '已启用', 'SAAS_ISOLATE_FILES 必须为 true');
        $this->requirement('幂等保护', (bool) config('saas.idempotency.required'), '批量建站必须提供幂等键', 'SAAS_REQUIRE_IDEMPOTENCY_KEY 必须为 true');
        $this->requirement('默认协议', config('saas.default_scheme') === 'https', 'https', 'SAAS_DEFAULT_SCHEME 必须为 https');
        $this->requirement(
            '发布升级模式',
            ! (bool) config('longos.auto_upgrade'),
            '使用显式 saas:upgrade 发布',
            'SaaS 生产环境必须关闭 LONGOS_AUTO_UPGRADE，请使用 saas:upgrade 逐租户迁移',
        );

        $baseDomain = trim((string) config('saas.base_domain'));
        $this->requirement('自动子域名', $this->isValidHostname($baseDomain), $baseDomain, 'SAAS_BASE_DOMAIN 未配置或无效');
    }

    private function checkDeploymentConfiguration(): void
    {
        $queue = (string) config('queue.default');
        $this->requirement('队列驱动', $queue !== 'sync', $queue, '生产环境不得使用 sync 队列');
        if ((string) config("queue.connections.{$queue}.driver") === 'database') {
            $this->requirement(
                '队列控制库',
                config("queue.connections.{$queue}.connection") === 'saas',
                '数据库队列固定使用 saas 连接',
                'QUEUE_DB_CONNECTION 必须为 saas，否则租户任务可能落入租户库',
            );
        }
        if (str_starts_with((string) config('queue.failed.driver'), 'database')) {
            $this->requirement(
                '失败任务控制库',
                config('queue.failed.database') === 'saas',
                '失败任务固定使用 saas 连接',
                'QUEUE_FAILED_DB_CONNECTION 必须为 saas',
            );
        }

        $cache = (string) config('cache.default');
        $this->advisory('共享缓存', ! in_array($cache, ['array', 'file', 'null'], true), $cache, '多节点或 onOneServer 调度建议使用 Redis/Memcached/数据库缓存');
        $this->advisory('配置缓存', app()->configurationIsCached(), '已缓存', '发布后应执行 php artisan config:cache');
        $this->advisory('路由缓存', app()->routesAreCached(), '已缓存', '发布后建议执行 php artisan route:cache');
        $this->requirement(
            '日志轮转',
            config('logging.default') !== 'single'
                && (config('logging.default') !== 'stack' || in_array('daily', (array) config('logging.channels.stack.channels'), true)),
            '已使用可轮转的日志通道',
            '生产环境不得使用无上限的 single 日志',
        );
        $this->requirement(
            '更新回滚保留',
            ! (bool) config('longos.updater.enabled') || (int) config('longos.updater.retain_backups') >= 2,
            (bool) config('longos.updater.enabled') ? '至少保留 2 个可回滚版本' : '后台更新器已关闭',
            'LONGOS_UPDATE_RETAIN_BACKUPS 至少应为 2',
        );

        $runtimePaths = [storage_path('framework'), storage_path('logs'), app()->bootstrapPath('cache')];
        $unwritable   = array_values(array_filter($runtimePaths, static fn (string $path): bool => ! is_dir($path) || ! is_writable($path)));
        $this->requirement(
            '运行目录权限',
            $unwritable === [],
            'storage/framework、storage/logs 与 bootstrap/cache 可写',
            '不可写：' . implode(', ', $unwritable),
        );

        $driver = (string) config('saas.domain_config.driver');
        $this->requirement('Web 服务器', $driver === 'caddy', $driver, '生产环境必须使用 Coolify Caddy，并设置 SAAS_WEB_SERVER=caddy');

        $tlsDriver = strtolower(trim((string) config('saas.tls.driver')));
        $this->requirement(
            'HTTPS 管理方式',
            $tlsDriver === 'caddy',
            'Coolify Caddy On-Demand TLS 已启用',
            '生产环境必须设置 SAAS_TLS_DRIVER=caddy',
        );
        if ($tlsDriver === 'caddy') {
            $tlsEmail = trim((string) config('saas.tls.email'));
            $this->requirement('ACME 联系邮箱', $this->isPublicEmail($tlsEmail), $tlsEmail, 'SAAS_TLS_EMAIL 必须是有效的真实邮箱，不能使用 example/local/test/invalid 保留域');
            $proxyToken = (string) config('longos.network.proxy_token');
            $this->requirement('反向代理认证', strlen($proxyToken) >= 32 && $proxyToken !== 'development-only-change-me', 'Caddy 到 Laravel 的代理头已认证', 'LONGOS_PROXY_TOKEN 必须为至少 32 字符的随机值');
            $this->requirement('Caddy 边缘一致性', $driver === 'caddy', 'Web 与 TLS 均由 Caddy 边缘管理', 'SAAS_TLS_DRIVER=caddy 时 SAAS_WEB_SERVER 也必须为 caddy');
            $this->requirement(
                '主站证书管理',
                ! (bool) config('saas.tls.external_control_domain'),
                '主站和租户证书均由 Coolify Caddy 管理',
                'Coolify 部署必须设置 SAAS_TLS_EXTERNAL_CONTROL_DOMAIN=false',
            );
        }

        $edgePlatform = CaddyEdgeStatus::platform(config('saas.edge.platform', 'coolify'));
        $this->requirement(
            'Caddy 部署平台',
            $edgePlatform !== 'invalid',
            $edgePlatform,
            'LONGOS_DEPLOYMENT_PLATFORM 仅支持 coolify',
        );

        $cloudflareMode = strtolower((string) config('longos.cloudflare.mode', 'optional'));
        $this->requirement(
            'Cloudflare CDN 模式',
            in_array($cloudflareMode, ['off', 'optional', 'required'], true),
            match ($cloudflareMode) {
                'off'      => '未启用 Cloudflare 感知',
                'required' => '仅允许 Cloudflare 回源',
                default    => '支持 Cloudflare CDN，也允许直连域名',
            },
            'LONGOS_CLOUDFLARE_MODE 仅支持 off、optional 或 required',
        );
        if ($cloudflareMode === 'required') {
            $this->requirement(
                'Cloudflare 严格入口',
                false,
                '',
                'Coolify 集成 Caddy 的项目级标签无法安全修改服务器全局来源限制；请使用 optional/off，或在 Coolify 的服务器级 Caddy 动态配置中完成严格回源后再扩展此检查',
            );
        } elseif ((bool) config('longos.cloudflare.api_token_configured')) {
            $this->advisory(
                'Cloudflare API Token',
                true,
                'Token 仅供 LONGOS DNS 自动化使用；证书仍由 Coolify Caddy 的 On-Demand TLS 管理',
                '',
            );
        }

        $configPath   = (string) config('saas.domain_config.path');
        $manifestPath = (string) config('saas.domain_config.manifest_path');
        $pathsValid   = $configPath !== '' && $manifestPath !== '' && $this->normalizedPath($configPath) !== $this->normalizedPath($manifestPath);
        $this->requirement('域名配置路径', $pathsValid, "{$configPath} | {$manifestPath}", '配置文件与清单路径必须非空且不同');
        if ($pathsValid) {
            $this->requirement('域名路径可写', $this->canWritePath($configPath) && $this->canWritePath($manifestPath), '目标或其最近父目录可写', '应用进程无法写入域名配置路径');
        }

        $this->requirement(
            '域名动态发布',
            $driver === 'caddy' && $tlsDriver === 'caddy',
            'Caddy ask 实时授权，无需重载代理',
            '必须由 Coolify Caddy 统一处理域名和证书',
        );
        $dnsTargets = (array) config('saas.dns_targets');
        $this->advisory('DNS 目标', $dnsTargets !== [], implode(', ', $dnsTargets), '未配置 SAAS_DNS_TARGETS，无法确认解析是否指向正确入口');
    }

    private function checkMailConfiguration(): void
    {
        $enabled = (bool) config('mail.relay.enabled');
        $this->advisory(
            '内置邮件引擎',
            $enabled,
            '已启用 Postfix/OpenDKIM 中继',
            '未启用内置邮件引擎；订单及账户邮件可能依赖外部邮件器',
        );
        if (! $enabled) {
            return;
        }

        $this->requirement(
            '默认邮件器',
            config('mail.default') === 'self_hosted',
            'self_hosted',
            'LONGOS_MAIL_RELAY_ENABLED=true 时 MAIL_MAILER 必须为 self_hosted',
        );
        $this->requirement(
            'LONGOS 中继凭据',
            (bool) config('mail.relay.longos_client_configured'),
            '已配置独立 HMAC 凭据',
            'MAIL_RELAY_SHARED_SECRET 必须至少包含 32 个字符',
        );
        $this->requirement(
            'Cloud Mail 网关内部凭据',
            (bool) config('mail.relay.cloud_mail_client_configured'),
            '已配置独立 HMAC 凭据',
            'CLOUD_MAIL_RELAY_SHARED_SECRET 必须至少包含 32 个字符',
        );
        $this->requirement(
            '中继凭据隔离',
            (bool) config('mail.relay.client_secrets_distinct'),
            '站内投递与 Cloud Mail 应用网关的内部凭据相互独立',
            '站内投递与 Cloud Mail 应用网关不得共用同一内部中继密钥',
        );

        $endpoint      = trim((string) config('mail.mailers.self_hosted.endpoint'));
        $endpointParts = parse_url($endpoint);
        $endpointHost  = strtolower((string) ($endpointParts['host'] ?? ''));
        $endpointSafe  = filter_var($endpoint, FILTER_VALIDATE_URL) !== false
            && (($endpointParts['scheme'] ?? '') === 'https'
                || (($endpointParts['scheme'] ?? '') === 'http'
                    && in_array($endpointHost, ['mail-relay', '127.0.0.1', 'localhost'], true)));
        $this->requirement(
            '邮件中继端点',
            $endpointSafe,
            $endpoint,
            'MAIL_RELAY_ENDPOINT 必须使用 HTTPS，或指向私有 mail-relay/localhost HTTP 端点',
        );

        $signingDomains = array_values(array_filter(array_map(
            static fn (string $domain): string => strtolower(trim($domain)),
            explode(',', (string) config('mail.relay.domains')),
        )));
        $allowedDomains = array_values(array_filter(array_map(
            static fn (string $domain): string => strtolower(trim($domain)),
            (array) config('mail.relay.allowed_sender_domains', []),
        )));
        $domainsValid = $signingDomains !== []
            && $allowedDomains          !== []
            && array_diff($allowedDomains, $signingDomains) === []
            && collect(array_unique([...$signingDomains, ...$allowedDomains]))
                ->every(fn (string $domain): bool => $this->isValidHostname($domain));
        $this->requirement(
            'DKIM 发件域',
            $domainsValid,
            implode(', ', $signingDomains),
            '每个 MAIL_RELAY_ALLOWED_SENDER_DOMAINS 域名都必须是有效且已加入 MAIL_RELAY_DOMAINS 的域名',
        );

        $platformSenderDomain = strtolower(trim((string) config('mail.relay.platform_sender_domain')));
        $this->requirement(
            '平台发件域',
            $this->isValidHostname($platformSenderDomain)
                && in_array($platformSenderDomain, $allowedDomains, true)
                && in_array($platformSenderDomain, $signingDomains, true),
            $platformSenderDomain,
            'MAIL_RELAY_PLATFORM_SENDER_DOMAIN 必须是允许且已配置 DKIM 签名的发件域名',
        );

        $fromAddress       = strtolower(trim((string) config('mail.from.address')));
        $fromDomain        = strtolower((string) substr(strrchr($fromAddress, '@') ?: '', 1));
        $fromAddressValid  = filter_var($fromAddress, FILTER_VALIDATE_EMAIL) !== false;
        $fromDomainAllowed = in_array($fromDomain, $allowedDomains, true);
        $this->requirement(
            '默认发件地址',
            $fromAddressValid && $fromDomainAllowed,
            $fromAddress,
            ! $fromAddressValid
                ? 'MAIL_FROM_ADDRESS 不是有效邮箱地址'
                : "MAIL_FROM_ADDRESS 的发件域 {$fromDomain} 未加入允许且已签名的发件域列表",
        );
        $this->advisory(
            '发件地址覆盖值',
            ! (bool) config('mail.relay.configured_from_address_rejected'),
            '未发现无效的 MAIL_FROM_ADDRESS 覆盖值',
            '已忽略无效或未签名的 MAIL_FROM_ADDRESS，并安全回退到平台发件域',
        );

        $publicUrl       = trim((string) config('mail.relay.public_url'));
        $parsedPublicUrl = parse_url($publicUrl);
        $publicUrlParts  = is_array($parsedPublicUrl) ? $parsedPublicUrl : [];
        $publicUrlHost   = strtolower(trim((string) ($publicUrlParts['host'] ?? ''), '.'));
        $publicUrlPath   = '/' . trim((string) ($publicUrlParts['path'] ?? ''), '/');
        $controlHosts    = array_values(array_filter(array_map(
            static fn (mixed $host): string => strtolower(trim((string) $host, " .\t\n\r\0\x0B")),
            (array) config('saas.control_domains', []),
        )));
        if ($controlHosts === []) {
            $fallbackControlHost = strtolower(trim((string) parse_url((string) config('app.url'), PHP_URL_HOST), '.'));
            $controlHosts        = $fallbackControlHost !== '' ? [$fallbackControlHost] : [];
        }
        $publicUrlValid = filter_var($publicUrl, FILTER_VALIDATE_URL) !== false
            && strtolower((string) ($publicUrlParts['scheme'] ?? '')) === 'https'
            && $this->isValidHostname($publicUrlHost)
            && $publicUrlPath === '/_longos/mail-relay'
            && ! isset($publicUrlParts['user'])
            && ! isset($publicUrlParts['pass'])
            && ! isset($publicUrlParts['query'])
            && ! isset($publicUrlParts['fragment'])
            && (! (bool) config('saas.enabled') || in_array($publicUrlHost, $controlHosts, true));
        $this->requirement(
            'Cloud Mail 中继 URL',
            $publicUrlValid,
            $publicUrl,
            'MAIL_RELAY_PUBLIC_URL 必须是主站控制域名下的 HTTPS /_longos/mail-relay URL',
        );
    }

    private function checkControlDatabase(): void
    {
        try {
            DB::connection('saas')->getPdo();
            $this->recordPass('控制库连接', '连接成功');

            $schema        = Schema::connection('saas');
            $tables        = ['saas_sites', 'saas_domains', 'saas_mail_sender_domains', 'saas_site_events', 'saas_idempotency_records'];
            $queue         = (string) config('queue.default');
            if ((string) config("queue.connections.{$queue}.driver") === 'database'
                && config("queue.connections.{$queue}.connection")   === 'saas') {
                $tables[] = (string) config("queue.connections.{$queue}.table", 'jobs');
            }
            if (str_starts_with((string) config('queue.failed.driver'), 'database')
                && config('queue.failed.database') === 'saas') {
                $tables[] = (string) config('queue.failed.table', 'failed_jobs');
            }
            $tables        = array_values(array_unique($tables));
            $missingTables = array_values(array_filter($tables, static fn (string $table): bool => ! $schema->hasTable($table)));
            $this->requirement('控制库表', $missingTables === [], '控制表齐全', '缺少：' . implode(', ', $missingTables));
            if ($missingTables !== []) {
                return;
            }

            $requiredColumns = [
                'saas_sites'   => ['provisioning_token', 'provisioning_heartbeat_at', 'health_status'],
                'saas_domains' => [
                    'origin', 'verification_token', 'routing_behavior', 'verification_error',
                    'published_at', 'last_probe_at', 'publication_error',
                ],
            ];
            $missingColumns = [];
            foreach ($requiredColumns as $table => $columns) {
                foreach ($columns as $column) {
                    if (! $schema->hasColumn($table, $column)) {
                        $missingColumns[] = "{$table}.{$column}";
                    }
                }
            }
            $this->requirement('控制库版本', $missingColumns === [], '已包含最新 SaaS 字段', '尚未迁移：' . implode(', ', $missingColumns));
            if ($missingColumns !== []) {
                return;
            }

            $siteCount   = Site::query()->count();
            $activeCount = Site::query()->where('status', Site::STATUS_ACTIVE)->count();
            $this->requirement('站点注册表', $siteCount > 0, "{$siteCount} 个站点，{$activeCount} 个已上线", '尚未注册首站，请先执行 saas:install');
            $this->requirement('已上线站点', $activeCount > 0, "{$activeCount} 个已上线站点", '没有 active 站点');

            $missingPrimary = Site::query()
                ->where('status', Site::STATUS_ACTIVE)
                ->whereDoesntHave('domains', static fn ($query) => $query->where('status', Domain::STATUS_ACTIVE)->where('is_primary', true))
                ->pluck('code')
                ->all();
            $duplicatePrimary = Domain::query()
                ->select('site_id')
                ->where('status', Domain::STATUS_ACTIVE)
                ->where('is_primary', true)
                ->whereHas('site', static fn ($query) => $query->where('status', Site::STATUS_ACTIVE))
                ->groupBy('site_id')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('site_id')
                ->all();
            $this->requirement(
                '主域名不变式',
                $missingPrimary === [] && $duplicatePrimary === [],
                '每个已上线站点恰有一个已启用主域名',
                '缺失主域名站点：' . implode(', ', $missingPrimary) . '；重复主域名站点 ID：' . implode(', ', $duplicatePrimary),
            );

            $activatingDomains = Domain::query()->where('status', Domain::STATUS_ACTIVATING)->pluck('host')->all();
            $this->requirement(
                '域名发布状态',
                $activatingDomains === [],
                '无停留在 activating 的域名',
                '存在未完成发布的域名：' . implode(', ', $activatingDomains),
            );

            $insecureDomains = Domain::query()
                ->where('status', Domain::STATUS_ACTIVE)
                ->where('scheme', '!=', 'https')
                ->whereHas('site', static fn ($query) => $query->where('status', Site::STATUS_ACTIVE))
                ->pluck('host')
                ->all();
            $this->requirement(
                '租户域名 HTTPS',
                $insecureDomains === [],
                '全部已上线域名均使用 HTTPS',
                '以下已上线域名未使用 HTTPS：' . implode(', ', $insecureDomains),
            );

            $unpublishedDomains = Domain::query()
                ->where('status', Domain::STATUS_ACTIVE)
                ->whereNull('published_at')
                ->whereHas('site', static fn ($query) => $query->where('status', Site::STATUS_ACTIVE))
                ->pluck('host')
                ->all();
            $this->requirement(
                '域名发布凭据',
                $unpublishedDomains === [],
                '全部已上线域名均记录完成发布时间',
                '以下 active 域名缺少发布凭据：' . implode(', ', $unpublishedDomains),
            );

            $incomplete = Site::query()
                ->whereIn('status', [Site::STATUS_PROVISIONING, Site::STATUS_READY, Site::STATUS_ACTIVATING, Site::STATUS_FAILED])
                ->count();
            $this->advisory('未完成建站', $incomplete === 0, '无', "{$incomplete} 个站点仍处于建站中或失败状态");
        } catch (Throwable $exception) {
            $this->recordFailure('控制库连接', $this->safeMessage($exception->getMessage()));
        }
    }

    private function checkProvisioningDatabase(): void
    {
        if (config('saas.database_mode', 'prefix') === 'prefix') {
            $this->checkSharedTenantDatabase();

            return;
        }

        try {
            $connection = DB::connection('tenant_admin');
            $connection->getPdo();
            $driver    = $connection->getDriverName();
            $supported = in_array($driver, ['mysql', 'pgsql'], true)
                || ($driver === 'sqlite' && app()->environment('testing'));
            $this->requirement(
                '建站数据库连接',
                $supported,
                "tenant_admin 连接成功（{$driver}）",
                "tenant_admin 驱动 {$driver} 不支持自动建库",
            );
            if (! $supported || $driver === 'sqlite') {
                return;
            }

            $allowed = false;
            if ($driver === 'mysql') {
                $grants = $connection->select('SHOW GRANTS FOR CURRENT_USER');
                $text   = strtoupper(implode(' ', array_merge(...array_map(
                    static fn (object $row): array => array_values((array) $row),
                    $grants,
                ))));
                $canCreate = preg_match('/GRANT (?:ALL PRIVILEGES|[^;]*\bCREATE\b[^;]*) ON \\*\\.\\*/', $text) === 1;
                $globalAll = str_contains($text, 'GRANT ALL PRIVILEGES ON *.*');
                $sameUser  = (string) $connection->getConfig('username')
                    === (string) config('database.connections.tenant.username');
                $allowed = $canCreate
                    && (($sameUser && $globalAll) || str_contains($text, 'WITH GRANT OPTION'));
            } elseif ($driver === 'pgsql') {
                $runtimeUser = (string) config('database.connections.tenant.username');
                $row         = $connection->selectOne(
                    'SELECT (r.rolcreatedb OR r.rolsuper)'
                    . ' AND (r.rolsuper OR current_user = ? OR pg_has_role(current_user, ?, \'MEMBER\')) AS allowed'
                    . ' FROM pg_roles r WHERE r.rolname = current_user',
                    [$runtimeUser, $runtimeUser],
                );
                $allowed = $this->databaseBoolean($row?->allowed ?? null);
            }

            $this->requirement(
                '自动建库权限',
                $allowed,
                'tenant_admin 具备建库并授权运行账号的权限',
                'tenant_admin 缺少建库权限、GRANT OPTION，或无法把 PostgreSQL 所有权授予运行角色',
            );
        } catch (Throwable $exception) {
            $this->recordFailure('建站数据库连接', $this->safeMessage($exception->getMessage()));
        }
    }

    private function checkSharedTenantDatabase(): void
    {
        $connectionName = 'saas_shared_preflight';

        try {
            $configured = trim((string) config('saas.shared_database', ''));
            if ($configured === '') {
                $this->recordFailure('共享数据库目标', 'SAAS_SHARED_DATABASE 和 DB_DATABASE 均为空');

                return;
            }

            // Prefix mode may intentionally keep the control plane in a
            // separate database. Test the tenant runtime credentials against
            // the configured shared database instead of assuming that it must
            // be identical to the `saas` control connection.
            $settings             = (array) config('database.connections.tenant', []);
            $settings['database'] = $configured;
            $settings['prefix']   = '';
            $settings['url']      = null;
            config(["database.connections.{$connectionName}" => $settings]);
            DB::purge($connectionName);

            $connection = DB::connection($connectionName);
            $connection->getPdo();
            $driver    = $connection->getDriverName();
            $supported = in_array($driver, ['mysql', 'pgsql'], true)
                || ($driver === 'sqlite' && app()->environment('testing'));
            $this->requirement(
                '共享租户数据库',
                $supported,
                "运行账号连接成功（{$driver}），无需 root 或自动建库权限",
                "数据库驱动 {$driver} 不支持共享租户模式",
            );
            if (! $supported) {
                return;
            }

            $actual     = trim((string) $connection->getDatabaseName());
            $this->requirement(
                '共享数据库目标',
                $configured !== '' && $configured === $actual,
                $actual,
                '租户运行连接未指向 SAAS_SHARED_DATABASE',
            );

            $prefixes = [];
            $invalid  = [];
            foreach (Site::query()->get() as $site) {
                if (! $site->usesSharedDatabase()) {
                    continue;
                }

                try {
                    $prefix = $site->getDatabasePrefix();
                    if (isset($prefixes[$prefix])) {
                        $invalid[] = "{$site->code} 与 {$prefixes[$prefix]} 前缀重复";
                    } else {
                        $prefixes[$prefix] = $site->code;
                    }
                } catch (Throwable $exception) {
                    $invalid[] = "{$site->code}: " . $this->safeMessage($exception->getMessage());
                }
            }
            $this->requirement(
                '租户表命名空间',
                $invalid === [],
                count($prefixes) . ' 个共享库租户前缀有效且互不重复',
                implode('；', $invalid),
            );

            $missingNamespaces = [];
            $schema            = $connection->getSchemaBuilder();
            foreach (Site::query()->whereIn('status', [Site::STATUS_ACTIVE, Site::STATUS_SUSPENDED])->get() as $site) {
                if (! $site->usesSharedDatabase()) {
                    continue;
                }

                $prefix  = $site->getDatabasePrefix();
                $missing = array_values(array_filter(
                    [
                        'migrations', 'settings', 'admin_users', 'permissions', 'roles',
                        'plugins', 'categories', 'customers', 'carts', 'products', 'orders',
                    ],
                    static fn (string $table): bool => ! $schema->hasTable($prefix . $table),
                ));
                if ($missing !== []) {
                    $missingNamespaces[] = "{$site->code}: " . implode(', ', $missing);
                }
            }
            $this->requirement(
                '共享租户表结构',
                $missingNamespaces === [],
                '全部已上线共享库租户均包含核心业务、插件、权限和管理员表',
                '缺少租户表：' . implode('；', $missingNamespaces),
            );
        } catch (Throwable $exception) {
            $this->recordFailure('共享租户数据库', $this->safeMessage($exception->getMessage()));
        } finally {
            DB::disconnect($connectionName);
            DB::purge($connectionName);
            config()->offsetUnset("database.connections.{$connectionName}");
        }
    }

    private function checkCacheDatabase(): void
    {
        if (config('cache.default') !== 'database') {
            return;
        }

        try {
            $connection = config('cache.stores.database.connection') ?: config('database.default');
            $schema     = Schema::connection((string) $connection);
            $tables     = [
                (string) config('cache.stores.database.table', 'cache'),
                (string) config('cache.stores.database.lock_table', 'cache_locks'),
            ];
            $missing = array_values(array_filter($tables, static fn (string $table): bool => ! $schema->hasTable($table)));
            $this->requirement(
                '数据库缓存表',
                $missing === [],
                '缓存与分布式锁表已就绪',
                '缺少：' . implode(', ', $missing),
            );
        } catch (Throwable $exception) {
            $this->recordFailure('数据库缓存表', $this->safeMessage($exception->getMessage()));
        }
    }

    private function requirement(string $name, bool $condition, string $success, string $failure): void
    {
        $condition ? $this->recordPass($name, $success) : $this->recordFailure($name, $failure);
    }

    private function advisory(string $name, bool $condition, string $success, string $warning): void
    {
        $this->checks[] = ['name' => $name, 'status' => $condition ? 'PASS' : 'WARN', 'message' => $condition ? $success : $warning];
    }

    private function recordPass(string $name, string $message): void
    {
        $this->checks[] = ['name' => $name, 'status' => 'PASS', 'message' => $message];
    }

    private function recordFailure(string $name, string $message): void
    {
        $this->checks[] = ['name' => $name, 'status' => 'FAIL', 'message' => $message];
    }

    private function isValidHostname(string $host): bool
    {
        return $host !== ''
            && strlen($host) <= 253
            && str_contains($host, '.')
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private function isPublicEmail(string $email): bool
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));

        return $domain !== ''
            && ! in_array($domain, ['example.com', 'example.net', 'example.org', 'localhost'], true)
            && preg_match('/(?:^|\.)(?:example|invalid|local|test)$/', $domain) !== 1;
    }

    private function normalizedPath(string $path): string
    {
        return strtolower(rtrim(str_replace('\\', '/', $path), '/'));
    }

    private function canWritePath(string $path): bool
    {
        if (@is_file($path)) {
            return @is_writable($path);
        }

        $directory = dirname($path);
        while (! @is_dir($directory)) {
            $parent = dirname($directory);
            if ($parent === $directory) {
                return false;
            }
            $directory = $parent;
        }

        return @is_writable($directory);
    }

    private function safeMessage(string $message): string
    {
        return mb_substr(SensitiveValueRedactor::redact($message), 0, 500);
    }

    private function decodeApplicationKey(string $key): ?string
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        if (! str_starts_with($key, 'base64:')) {
            return $key;
        }

        $decoded = base64_decode(substr($key, 7), true);

        return is_string($decoded) ? $decoded : null;
    }

    private function databaseBoolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}
