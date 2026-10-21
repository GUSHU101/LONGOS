<?php

namespace Beike\Shop\Providers;

use App\Mail\MailSenderProfileService;
use App\Saas\Models\Site;
use Beike\Libraries\Tax;
use Beike\Mail\Providers\LongosRelayServiceProvider;
use Beike\Mail\Providers\SendCloudServiceProvider;
use Beike\Mail\Providers\SendGridServiceProvider;
use Beike\Models\Customer;
use Beike\Services\TimezoneService;
use Beike\Shop\View\Components\AccountSidebar;
use Beike\Shop\View\Components\Alert;
use Beike\Shop\View\Components\Breadcrumb;
use Beike\Shop\View\Components\NoData;
use Beike\Shop\View\Components\SearchPopover;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\View\FileViewFinder;

class ShopServiceProvider extends ServiceProvider
{
    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        // Long-lived workers reuse the same config repository between tenants.
        // Keep an immutable application baseline so every tenant refresh starts
        // from the configured defaults rather than the previous tenant's values.
        if (! $this->app->bound('saas.mail_baseline')) {
            $this->app->instance('saas.mail_baseline', [
                'mail'          => config('mail'),
                'mailgun'       => config('services.mailgun'),
                'app_asset_url' => config('app.asset_url'),
            ]);
        }

        $this->app->singleton('tax', function () {
            return new Tax;
        });

        // 注册邮件服务提供者
        $this->app->register(LongosRelayServiceProvider::class);
        $this->app->register(SendCloudServiceProvider::class);
        $this->app->register(SendGridServiceProvider::class);
    }

    /**
     * @throws \Exception
     */
    public function boot()
    {
        $uri = request()->getRequestUri();
        if (is_installer()) {
            return;
        }
        $this->loadRoutesFrom(__DIR__ . '/../Routes/shop.php');

        $apiRoute = __DIR__ . '/../../ShopAPI/Routes/api.php';
        if (file_exists($apiRoute)) {
            $this->loadRoutesFrom($apiRoute);
        }

        $this->registerGuard();
        if ($this->app->environment('testing')) {
            // A workstation may contain storage/installed and a real database
            // configuration. PHPUnit chooses its isolated connection after
            // providers boot; tenant switch tasks refresh settings once that
            // connection is ready.
            config(['bk' => []]);
        } else {
            $this->refreshTenantConfiguration();
        }

        if (Str::startsWith($uri, '/admin')) {
            return;
        }

        $this->registerFileSystem();
        $this->mergeConfigFrom(__DIR__ . '/../../Config/beike.php', 'beike');
        $this->loadThemeViewPath();
        $this->loadComponents();
    }

    /**
     * Reload settings that differ per SaaS site. Queue workers call this
     * between jobs after TenantManager has switched the database.
     */
    public function refreshTenantConfiguration(?Site $site = null): void
    {
        $this->restoreRuntimeBaseline();
        config(['bk' => []]);
        load_settings();
        TimezoneService::applyConfigured(($site ?? current_site())?->timezone);
        config(['saas.loaded_site_id' => ($site ?? current_site())?->id]);
        $this->loadMailConfig();
        if ($this->app->resolved('mail.manager')) {
            $this->app->make('mail.manager')->forgetMailers();
        }
        $this->registerCDNUrl();

        if ($this->app->resolved('view.finder')) {
            $this->app->make('view.finder')->setPaths($this->themeViewPaths());
        }
    }

    /** Reset mutable per-tenant state without querying another site's DB. */
    public function resetTenantConfiguration(): void
    {
        $this->restoreRuntimeBaseline();
        config([
            'bk'                  => [],
            'saas.loaded_site_id' => null,
        ]);

        if ($this->app->resolved('mail.manager')) {
            $this->app->make('mail.manager')->forgetMailers();
        }
        if (! $this->app->runningInConsole() && $this->app->resolved('url')) {
            $this->app->make('url')->useAssetOrigin(config('app.asset_url'));
        }
        if ($this->app->resolved('view.finder')) {
            $this->app->make('view.finder')->setPaths((array) config('view.paths', []));
        }
    }

    private function restoreRuntimeBaseline(): void
    {
        $baseline = $this->app->bound('saas.mail_baseline')
            ? $this->app->make('saas.mail_baseline')
            : [];

        if (array_key_exists('mail', $baseline)) {
            config(['mail' => $baseline['mail']]);
        }
        config(['services.mailgun' => $baseline['mailgun'] ?? null]);
        if (array_key_exists('app_asset_url', $baseline)) {
            config(['app.asset_url' => $baseline['app_asset_url']]);
        }
    }

    /**
     * 注册前端客户
     */
    protected function registerGuard()
    {
        Config::set('auth.guards.' . Customer::AUTH_GUARD, [
            'driver'   => 'session',
            'provider' => 'shop_customer',
        ]);

        Config::set('auth.providers.shop_customer', [
            'driver' => 'eloquent',
            'model'  => Customer::class,
        ]);
    }

    /**
     * 注册上传文件系统
     */
    protected function registerFileSystem()
    {
        Config::set('filesystems.disks.upload', [
            'driver'      => 'local',
            'root'        => public_path(tenant_upload_prefix()),
            'permissions' => [
                'file' => [
                    'public'  => 0644,
                    'private' => 0600,
                ],
                'dir'  => [
                    'public'  => 0755,
                    'private' => 0755,
                ],
            ],
        ]);
    }

    /**
     * 加载邮件配置, 从后台 mail 取值, 并覆盖到  config/mail 和 config/services
     */
    protected function loadMailConfig()
    {
        // refreshTenantConfiguration() restores this value from the immutable
        // application baseline before loading tenant settings. Console startup
        // and control-plane commands may legitimately have no current tenant,
        // so the sender profile resolver cannot always build a tenant-scoped
        // identity. Never let that empty contextual result erase the signed
        // Compose/environment fallback.
        $baselineMail = trim((string) config('mail.from.address'));
        $mailEngine   = strtolower((string) system_setting('base.mail_engine'));
        if ($mailEngine === '' && config('mail.relay.enabled')) {
            $mailEngine = 'self_hosted';
        }
        $defaultSender = app(MailSenderProfileService::class)->resolve(MailSenderProfileService::DEFAULT);
        $storeMail     = trim($defaultSender['address']);
        $storeName     = trim($defaultSender['name']);

        // system_setting returns an explicitly stored empty value instead of the
        // supplied default. Resolve fallbacks after reading it so a deliberately
        // blank optional field really falls back to the store contact settings.
        if ($storeMail === '') {
            $storeMail = trim((string) system_setting('base.email', ''));
        }
        if ($storeMail === '') {
            $storeMail = $baselineMail;
        }
        if ($storeName === '') {
            $storeName = (string) system_setting('base.store_name', \config('app.name'));
        }

        if (empty($mailEngine)) {
            return;
        }

        // 验证邮件配置
        $this->validateMailConfig($mailEngine, $storeMail);

        Config::set('mail.default', $mailEngine);
        Config::set('mail.from.address', $storeMail);
        Config::set('mail.from.name', $storeName ?: system_setting('base.store_name', \config('app.name')));

        if ($mailEngine === 'smtp' && ($setting = $this->mailArraySetting('smtp'))) {
            $setting['transport']  = 'smtp';
            $setting['encryption'] = in_array(strtolower((string) ($setting['encryption'] ?? 'tls')), ['none', 'null', ''], true)
                ? null
                : strtolower((string) $setting['encryption']);
            $setting['timeout']    = max(1, min(60, (int) ($setting['timeout'] ?? 10)));
            // Use the SMTP account only as a fallback. An explicitly configured
            // sender address must stay intact for verified-domain providers.
            if (empty($storeMail) && filter_var($setting['username'] ?? '', FILTER_VALIDATE_EMAIL)) {
                Config::set('mail.from.address', $setting['username']);
            }
            Config::set('mail.mailers.smtp', $setting);
        } elseif ($mailEngine === 'mailgun' && ($setting = $this->mailArraySetting('mailgun'))) {
            Config::set('services.mailgun', $setting);
        } elseif ($mailEngine === 'sendmail' && ($setting = $this->mailArraySetting('sendmail'))) {
            $setting['transport'] = 'sendmail';
            Config::set('mail.mailers.sendmail', $setting);
        } elseif ($mailEngine === 'sendcloud' && ($setting = $this->mailArraySetting('sendcloud'))) {
            $setting['transport'] = 'sendcloud';
            Config::set('mail.mailers.sendcloud', $setting);
        } elseif ($mailEngine === 'sendgrid' && ($setting = $this->mailArraySetting('sendgrid'))) {
            $setting['transport'] = 'sendgrid';
            Config::set('mail.mailers.sendgrid', $setting);
        }
    }

    /**
     * Old imports or plugins can leave malformed settings behind. Avoid a
     * type error while the application is booting and leave a diagnostic log.
     */
    protected function mailArraySetting(string $name): array
    {
        $setting = system_setting("base.{$name}", []);
        if (is_array($setting)) {
            return $setting;
        }

        if ($setting !== null && $setting !== '') {
            \Log::warning("邮件配置 {$name} 格式无效，已忽略该配置。");
        }

        return [];
    }

    /**
     * 验证邮件配置
     *
     * @param string $mailEngine
     * @param string $storeMail
     * @return void
     */
    protected function validateMailConfig(string $mailEngine, string $storeMail): void
    {
        // 验证发件人地址
        if (empty($storeMail)) {
            \Log::warning('邮件配置警告: 发件人地址未设置，请在后台系统设置中配置邮箱地址');

            return;
        }

        if (! filter_var($storeMail, FILTER_VALIDATE_EMAIL)) {
            \Log::warning("邮件配置警告: 发件人地址格式无效 ({$storeMail})，请在后台系统设置中配置正确的邮箱地址");

            return;
        }

        // 检查第三方邮件服务的特殊要求
        if (in_array($mailEngine, ['sendcloud', 'sendgrid', 'mailgun'])) {
            $exampleDomains = ['example.com', 'test.com', 'localhost', '127.0.0.1'];
            $domain         = substr(strrchr($storeMail, '@'), 1);

            if (in_array($domain, $exampleDomains)) {
                \Log::warning("邮件配置警告: {$mailEngine} 不支持示例域名地址 ({$storeMail})，请配置真实的邮箱地址。建议使用已验证的域名，如 noreply@yourdomain.com");
            }
        }
    }

    /**
     * 从后台获取CDN url 并注册
     *
     * @return void
     */
    protected function registerCDNUrl(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        $baseline = $this->app->bound('saas.mail_baseline')
            ? $this->app->make('saas.mail_baseline')
            : [];
        $defaultAssetUrl = $baseline['app_asset_url'] ?? null;
        $cdnUrl          = is_admin() ? '' : trim((string) system_setting('base.cdn_url', ''));
        $assetUrl        = $cdnUrl !== '' ? $cdnUrl : $defaultAssetUrl;

        config(['app.asset_url' => $assetUrl]);

        if ($this->app->resolved('url')) {
            $this->app->make('url')->useAssetOrigin($assetUrl);
        }
    }

    /**
     * 加载主体模板路径
     */
    protected function loadThemeViewPath()
    {
        $this->app->singleton('view.finder', function ($app): FileViewFinder {
            return new FileViewFinder($app['files'], $this->themeViewPaths());
        });
    }

    /** @return array<int, string> */
    private function themeViewPaths(): array
    {
        $paths = (array) config('view.paths', []);
        $theme = trim((string) system_setting('base.theme', ''));

        return $theme === '' ? $paths : array_merge([base_path("themes/{$theme}")], $paths);
    }

    /**
     * 加载视图组件
     */
    protected function loadComponents()
    {
        $this->loadViewComponentsAs('shop', [
            'sidebar'        => AccountSidebar::class,
            'no-data'        => NoData::class,
            'alert'          => Alert::class,
            'breadcrumb'     => Breadcrumb::class,
            'search-popover' => SearchPopover::class,
        ]);
    }
}
