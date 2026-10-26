<?php

use Barryvdh\Debugbar\Facades\Debugbar;
use Beike\Hook\Facades\Hook;
use Beike\Models\AdminUser;
use Beike\Models\Currency;
use Beike\Models\Customer;
use Beike\Models\Language;
use Beike\Plugin\Plugin;
use Beike\Repositories\CurrencyRepo;
use Beike\Services\CurrencyService;
use Beike\Services\TimezoneService;
use Carbon\Carbon;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use TorMorten\Eventy\Facades\Eventy;

/**
 * 获取后台设置到 settings 表的值
 *
 * @param      $key
 * @param null $default
 * @return mixed
 */
function setting($key, $default = null)
{
    return config("bk.{$key}", $default);
}

/**
 * 获取系统 settings
 *
 * @param      $key
 * @param null $default
 * @return mixed
 */
function system_setting($key, $default = null)
{
    $data = [
        'key'     => $key,
        'default' => $default,
        'setting' => setting("system.{$key}", $default),
    ];
    $data = hook_filter('helpers.system_setting', $data);

    return $data['setting'];
}

/**
 * Return the site selected from the current request host in SaaS mode.
 */
function current_site(): ?\App\Saas\Models\Site
{
    $containerKey = config('multitenancy.current_tenant_container_key');
    if (is_string($containerKey) && $containerKey !== '' && app()->bound($containerKey)) {
        return app($containerKey);
    }

    return app()->bound('saas.current_site') ? app('saas.current_site') : null;
}

/**
 * 获取后台设置到 settings 表的值
 *
 * @param      $key
 * @param null $default
 * @return mixed
 */
function plugin_setting($key, $default = null)
{
    return setting("plugin.{$key}", $default);
}

/**
 * Return the protected platform identity. Unlike a tenant's storefront name,
 * this value is used for the control plane, installer and administration UI.
 */
function platform_brand_name(): string
{
    return trim((string) config('longos.identity.name', 'LONGOS')) ?: 'LONGOS';
}

/**
 * Read the tenant storefront name from the native system settings.
 */
function site_brand_name(): string
{
    $storeName = trim((string) system_setting('base.store_name', ''));

    return $storeName === '' || strcasecmp($storeName, 'BeikeShop') === 0 ? platform_brand_name() : $storeName;
}

/**
 * Use a custom brand asset when provided, otherwise prefer a non-vendor
 * LONGOS default while retaining compatibility with the original image fields.
 */
function site_brand_asset(string $asset = 'shop_logo'): string
{
    $settings = [
        'shop_logo'  => ['base.logo', config('longos.identity.logo', 'image/longos-logo.svg'), 'image/logo.png'],
        'admin_logo' => ['base.admin_logo', config('longos.identity.logo', 'image/longos-logo.svg'), 'image/logo.png'],
        'favicon'    => ['base.favicon', config('longos.identity.favicon', 'image/longos-favicon.svg'), ''],
    ];
    [$baseKey, $fallback, $legacyDefault] = $settings[$asset] ?? $settings['shop_logo'];

    $baseAsset = trim((string) system_setting($baseKey, ''));
    if ($baseAsset !== '' && $baseAsset !== $legacyDefault) {
        return $baseAsset;
    }

    return $fallback;
}

/**
 * The administration area always uses the protected LONGOS mark.
 * Storefront branding remains independently configurable through site_brand_asset().
 */
function admin_brand_logo(): string
{
    return (string) config('longos.identity.logo', 'image/longos-logo.svg');
}

function site_brand_meta_title(): string
{
    $title      = (string) system_setting('base.meta_title', '');
    $title      = str_ireplace('BeikeShop', site_brand_name(), $title);

    return trim($title) ?: site_brand_name() . ' · Global Commerce';
}

function site_brand_meta_description(): string
{
    $description = (string) system_setting('base.meta_description', '');

    return str_ireplace('BeikeShop', site_brand_name(), $description);
}

/**
 * Build a stable canonical storefront URL while preserving meaningful list
 * state such as pagination and filters. Marketing and internal preview
 * parameters must never create duplicate indexable URLs.
 */
function storefront_canonical_url(): string
{
    if (! app()->bound('request')) {
        return url('/');
    }

    $query = request()->query();
    foreach (array_keys($query) as $key) {
        $normalized = strtolower((string) $key);
        if (str_starts_with($normalized, 'utm_')
            || str_starts_with($normalized, 'pk_')
            || in_array($normalized, ['dclid', 'fbclid', 'gclid', 'iframe', 'mc_cid', 'mc_eid', 'msclkid'], true)) {
            unset($query[$key]);
        }
    }

    ksort($query);
    $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    return request()->url() . ($queryString === '' ? '' : '?' . $queryString);
}

/** Validated native brand tokens used by the storefront and administration UI. */
function site_brand_style(): array
{
    $color = static function (mixed $value, string $fallback): string {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? strtoupper($value) : $fallback;
    };
    $preset = (string) system_setting('base.storefront_preset', 'minimal');

    return [
        'primary_color' => $color(system_setting('base.brand_primary_color', ''), '#155EEF'),
        'accent_color'  => $color(system_setting('base.brand_accent_color', ''), '#6D28D9'),
        'border_radius' => min(32, max(0, (int) system_setting('base.brand_border_radius', 12))),
        'preset'        => in_array($preset, ['minimal', 'soft', 'editorial'], true) ? $preset : 'minimal',
    ];
}

/**
 * 获取插件静态文件
 *
 * @param $code     , 插件编码
 * @param $filePath , 相对于插件目录 static 的文件路径
 */
function plugin_asset($code, $filePath): string
{
    return shop_route('plugin.asset', ['code' => $code, 'path' => $filePath]);
}

/**
 * Generate a tenant-hosted, expiring link for read-only order details.
 *
 * Guest order numbers and email addresses are not authentication secrets and
 * must never be used as query-string credentials.
 */
function order_access_url(mixed $order): string
{
    $number = is_object($order) ? ($order->number ?? null) : $order;
    if (! is_scalar($number) || trim((string) $number) === '') {
        throw new InvalidArgumentException('A valid order number is required.');
    }

    $days = max(1, min(365, (int) config('longos.order_access_link_days', 30)));

    $relative = URL::temporarySignedRoute(
        'shop.orders.show',
        now()->addDays($days),
        ['number' => (string) $number],
        absolute: false,
    );

    return rtrim(shop_route('home.index'), '/') . '/' . ltrim($relative, '/');
}

/**
 * Generate a short-lived, host-independent storefront URL.
 *
 * Payment providers return through the tenant's retained platform domain,
 * while the shopper normally keeps their session cookie on the current
 * primary/custom domain. A relative signature survives that host boundary
 * and still prevents an order number from becoming an access credential.
 */
function signed_shop_route(string $route, array $params = [], ?int $minutes = null): string
{
    $minutes ??= (int) config('longos.payment_action_link_minutes', 180);
    $minutes = max(5, min(1440, $minutes));

    $relative = URL::temporarySignedRoute(
        'shop.' . $route,
        now()->addMinutes($minutes),
        $params,
        absolute: false,
    );

    return rtrim(shop_route('home.index'), '/') . '/' . ltrim($relative, '/');
}

/** Generate a provider-return-safe order result URL. */
function order_checkout_result_url(mixed $order, ?string $paymentStatus = null): string
{
    $number = is_object($order) ? ($order->number ?? null) : $order;
    if (! is_scalar($number) || trim((string) $number) === '') {
        throw new InvalidArgumentException('A valid order number is required.');
    }

    $params = ['order_number' => (string) $number];
    if (in_array($paymentStatus, ['cancelled', 'error', 'unavailable'], true)) {
        $params['payment_status'] = $paymentStatus;
    }

    return signed_shop_route('checkout.success', $params);
}

/** Generate a provider-return-safe retry-payment URL. */
function order_payment_url(mixed $order, bool $manual = false): string
{
    $number = is_object($order) ? ($order->number ?? null) : $order;
    if (! is_scalar($number) || trim((string) $number) === '') {
        throw new InvalidArgumentException('A valid order number is required.');
    }

    $params = ['number' => (string) $number];
    if ($manual) {
        $params['manual'] = 1;
    }

    return signed_shop_route('orders.pay', $params);
}

/**
 * 获取后台管理前缀名称, 默认为 admin
 */
function admin_name(): string
{
    if (config('saas.enabled')) {
        // Routes are cached once for the whole SaaS application. A per-tenant
        // admin prefix would produce links that cannot match that shared cache.
        return Str::snake((string) (config('beike.admin_name') ?: 'admin'));
    }

    if ($envAdminName = config('beike.admin_name')) {
        return Str::snake($envAdminName);
    } elseif ($settingAdminName = system_setting('base.admin_name')) {
        return Str::snake($settingAdminName);
    }

    return 'admin';
}

/**
 * 获取后台设置项
 */
function load_settings()
{
    if (is_installer() || ! file_exists(__DIR__ . '/../storage/installed')) {
        return;
    }
    $result = \Beike\Repositories\SettingRepo::getGroupedSettings();
    config(['bk' => $result]);
}

/**
 * 获取后台链接
 *
 * @param       $route
 * @param mixed $params
 * @return string
 */
function admin_route($route, $params = []): string
{
    $adminName = admin_name();

    return route("{$adminName}.{$route}", $params);
}

/**
 * 获取前台链接
 *
 * @param       $route
 * @param mixed $params
 * @return string
 */
function shop_route($route, $params = [], $isLang = true): string
{
    $routeName = 'shop.' . $route;

    // Shop routes are loaded lazily outside the storefront context. Admin
    // layout components can nevertheless include the shop-home link while
    // the admin route collection is being booted. Do not turn that harmless
    // link into a fatal RouteNotFoundException.
    if ($routeName === 'shop.home.index' && ! \Illuminate\Support\Facades\Route::has($routeName)) {
        return url('/');
    }

    return route($routeName, $params);
}

/**
 * Build a provider callback on the tenant's retained platform domain.
 * Custom primary domains may be replaced while a payment is still pending;
 * the platform host remains the stable operational ingress for POST callbacks.
 */
function tenant_operational_route(string $route, array $params = []): string
{
    $routeName = 'shop.' . $route;
    $relative  = route($routeName, $params, false);
    $site      = current_site();

    if ((bool) config('saas.enabled') && $site) {
        $domain = $site->domains()
            ->routable()
            ->where('origin', \App\Saas\Models\Domain::ORIGIN_PLATFORM)
            ->orderByDesc('is_primary')
            ->first();
        if ($domain) {
            return rtrim("{$domain->scheme}://{$domain->host}", '/') . '/' . ltrim($relative, '/');
        }
    }

    return route($routeName, $params);
}

function get_base_url($url)
{
    $parsed = parse_url($url);

    // 组合协议部分（如 "https://"）
    $scheme = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';

    // 处理用户认证（如 "user:pass@"）
    $userPass = '';
    if (isset($parsed['user'])) {
        $userPass = $parsed['user'];
        if (isset($parsed['pass'])) {
            $userPass .= ':' . $parsed['pass'];
        }
        $userPass .= '@';
    }

    // 组合域名和端口（如 "example.com:8080"）
    $host = $parsed['host'] ?? '';
    $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

    return $scheme . $userPass . $host . $port;
}

/**
 * 获取 category, product, brand, page, static, custom 路由链接
 *
 * @param $type
 * @param $value
 * @return string
 * @throws Exception
 */
function type_route($type, $value): string
{
    return \Beike\Libraries\Url::getInstance()->link($type, $value);
}

/**
 * 获取 category, product, brand, page, static, custom 链接名称
 *
 * @param       $type
 * @param       $value
 * @param array $texts
 * @return string
 */
function type_label($type, $value, array $texts = []): string
{
    return \Beike\Libraries\Url::getInstance()->label($type, $value, $texts);
}

/**
 * 处理配置链接
 *
 * @param $link
 * @return array
 * @throws Exception
 */
function handle_link($link): array
{
    $type  = $link['type']  ?? '';
    $value = $link['value'] ?? '';
    $texts = $link['text']  ?? [];

    $link['link'] = type_route($type, $value);
    $link['text'] = type_label($type, $value, $texts);

    return $link;
}

/**
 * 是否访问的后端
 * @return bool
 */
function is_admin(): bool
{
    $adminName = admin_name();
    $uri       = request()->getRequestUri();
    if (Str::startsWith($uri, "/{$adminName}")) {
        return true;
    }

    return false;
}

/**
 * 是否访问安装页面
 * @return bool
 */
function is_installer(): bool
{
    $path = parse_url(request()->getRequestUri(), PHP_URL_PATH) ?: '/';

    return $path === '/installer' || Str::startsWith($path, '/installer/');
}

/**
 * 获取当前路由
 *
 * @return string
 */
function current_route(): string
{
    if (Route::getCurrentRoute()) {
        return Route::getCurrentRoute()->getName();
    }

    return '';
}

/**
 * 是否为当前访问路由
 *
 * @param $routeName
 * @return bool
 */
function equal_route($routeName): bool
{
    if (is_array($routeName)) {
        return in_array(Route::getCurrentRoute()->getName(), $routeName);
    }

    return $routeName == Route::getCurrentRoute()->getName();
}

/**
 * 获取后台当前登录用户
 *
 * @return mixed
 */
function current_user(): ?AdminUser
{
    $user = auth()->guard(AdminUser::AUTH_GUARD)->user();
    if (empty($user)) {
        $user = registry('admin_user');
    }

    return $user;
}

/**
 * 获取前台当前登录客户
 *
 * @return mixed
 */
function current_customer(): mixed
{
    return auth()->guard(Customer::AUTH_GUARD)->user();
}

/**
 * 获取 session id
 */
function get_session_id(): string
{
    $headerSessionId = request()->header('Bksessionid');
    if ($headerSessionId) {
        return $headerSessionId;
    }

    return session()->getId();
}

/**
 * 获取语言列表
 *
 * @return array
 */
function locales(): array
{
    return [[
        'name' => 'English',
        'code' => 'en',
    ]];
}

/**
 * 获取当前语言
 *
 * @return string
 */
function locale(): string
{
    // Content records are maintained in English for both the storefront and
    // administration screens. Interface translations use App's locale.
    return 'en';
}

/**
 * 获取后台当前语言
 *
 * @return string
 */
function admin_locale(): string
{
    return 'zh_cn';
}

/**
 * 货币格式化
 *
 * @param        $price
 * @param string $currency
 * @param string $value
 * @param bool   $format
 * @return string
 */
function currency_format($price, string $currency = '', string $value = '', bool $format = true): string|float
{
    if (! $currency) {
        $currency = is_admin() ? 'USD' : current_currency_code();
    }

    return CurrencyService::getInstance()->format($price, $currency, $value, $format);
}

/**
 * 获取指定货币汇率
 *
 * @return string
 */
function current_currency_rate(): float
{
    $currency = current_currency_code();

    return CurrencyService::getInstance()->rateFor($currency);
}

/**
 * 时间格式化
 *
 * @param null $datetime
 * @return false|string
 */
function time_format($datetime = null)
{
    $format   = 'Y-m-d H:i:s';
    $timezone = TimezoneService::normalize(config('app.timezone', 'UTC'));

    if ($datetime instanceof \DateTimeInterface) {
        return Carbon::instance($datetime)->setTimezone($timezone)->format($format);
    }
    if (is_int($datetime)) {
        return Carbon::createFromTimestamp($datetime, $timezone)->format($format);
    }

    return Carbon::now($timezone)->format($format);
}

/**
 * 获取插件根目录
 *
 * @param string $path
 * @return string
 */
function plugin_path(string $path = ''): string
{
    return base_path('plugins') . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : $path);
}

/**
 * @param $code
 * @return Plugin|null
 */
function plugin($code): ?Plugin
{
    return app('plugin')->getPlugin($code);
}

/**
 * 插件图片缩放
 *
 * @param     $pluginCode
 * @param     $image
 * @param int $width
 * @param int $height
 * @return mixed|void
 * @throws Exception
 */
function plugin_resize($pluginCode, $image, int $width = 100, int $height = 100)
{
    if (Str::startsWith($image, 'http')) {
        return $image;
    }

    $plugin        = plugin($pluginCode);
    $pluginDirName = $plugin->getDirname();

    return (new \Beike\Services\ImageService($image))->setPluginDirName($pluginDirName)->resize($width, $height);
}

/**
 * Get origin image from plugin
 *
 * @param $pluginCode
 * @param $image
 * @return mixed|void
 * @throws Exception
 */
function plugin_origin($pluginCode, $image)
{
    if (Str::startsWith($image, 'http')) {
        return $image;
    }

    $plugin        = plugin($pluginCode);
    $pluginDirName = $plugin->getDirname();

    return (new \Beike\Services\ImageService($image))->setPluginDirName($pluginDirName)->originUrl();
}

/**
 * 图片缩放
 *
 * @param     $image
 * @param int $width
 * @param int $height
 * @return mixed|void
 * @throws Exception
 */
function image_resize($image, int $width = 100, int $height = 100)
{
    $image = $image['src'] ?? $image;

    if (Str::startsWith($image, 'http')) {
        return $image;
    }

    // Keep animated GIFs intact, but allow WebP uploads to use the normal
    // derivative pipeline. Product imports are predominantly WebP; returning
    // the original here made every responsive srcset candidate point at the
    // same full-size file.
    if (str_contains(strtolower($image), '.gif')) {
        return image_origin($image);
    }

    return (new \Beike\Services\ImageService($image))->resize($width, $height);
}

/**
 * Return a cached responsive image and enqueue a missing derivative without
 * performing image decoding in the storefront request.
 */
function image_cached_resize($image, int $width = 100, int $height = 100, bool $schedule = true): string
{
    $image = $image['src'] ?? $image;

    if (Str::startsWith($image, 'http') || str_contains(strtolower((string) $image), '.gif')) {
        return image_origin($image);
    }

    return (new \Beike\Services\ImageService($image))->cachedResize($width, $height, $schedule);
}

/**
 * 获取原图地址
 * @throws Exception
 */
function image_origin($image)
{
    $image = $image['src'] ?? $image;

    if (Str::startsWith($image, 'http')) {
        return $image;
    }

    return (new \Beike\Services\ImageService($image))->originUrl();
}

/**
 * 获取后台开启的所有语言
 *
 * @return Collection
 */
function languages(): Collection
{
    return collect(['en']);
}

/**
 * 当前语言名称
 *
 * @return string
 */
function current_language()
{
    $code = locale();

    return Language::query()->where('code', $code)->first();
}

/**
 * 获取后台所有语言包列表
 *
 * @return array
 */
function admin_languages(): array
{
    return ['zh_cn'];
}

/**
 * 获取语言包列表
 * @return array
 */
function language_packages(): array
{
    $languageDir = base_path('lang');

    return array_values(array_diff(scandir($languageDir), ['..', '.', '.DS_Store']));
}

/**
 * @return Builder[]|\Illuminate\Database\Eloquent\Collection
 */
function currencies()
{
    return CurrencyRepo::listEnabled();
}

/**
 * 获取当前货币
 *
 * @return string
 */
function current_currency_code(): string
{
    $registeredCurrency = registry('currency');
    if (is_string($registeredCurrency) && trim($registeredCurrency) !== '') {
        return strtoupper(trim($registeredCurrency));
    }

    return 'USD';
}

/**
 * 获取当前货币
 *
 * @return string
 */
function current_currency_id(): string
{
    $currencyCode = current_currency_code();
    $currency     = \Beike\Models\Currency::query()->where('code', $currencyCode)->first();

    return $currency->id ?? 0;
}

/**
 * 数量格式化, 用于商品、订单统计
 *
 * @param $quantity
 * @return mixed|string
 */
function quantity_format($quantity)
{
    if ($quantity > 1000000000000) {
        return round($quantity / 1000000000000, 1) . 'T';
    } elseif ($quantity > 1000000000) {
        return round($quantity / 1000000000, 1) . 'B';
    } elseif ($quantity > 1000000) {
        return round($quantity / 1000000, 1) . 'M';
    } elseif ($quantity > 1000) {
        return round($quantity / 1000, 1) . 'K';
    }

    return $quantity;
}

/**
 * 返回json序列化结果
 */
function json_success($message, $data = []): JsonResponse
{
    $data = [
        'status'  => 'success',
        'message' => $message,
        'data'    => $data,
    ];

    return response()->json($data);
}

/**
 * 返回json序列化结果
 */
function json_fail($message, $data = [], $status = 422): JsonResponse
{
    $data = [
        'status'  => 'fail',
        'message' => $message,
        'data'    => $data,
    ];

    return response()->json($data, $status);
}

if (! function_exists('sub_string')) {
    /**
     * @param        $string
     * @param int    $length
     * @param string $dot
     * @return string
     */
    function sub_string($string, int $length = 16, string $dot = '...'): string
    {
        $strLength = mb_strlen($string);
        if ($length <= 0) {
            return $string;
        } elseif ($strLength <= $length) {
            return $string;
        }

        return mb_substr($string, 0, $length) . $dot;
    }
}

/**
 * 根据 $builder 对象输出SQL语句
 * @param mixed $builder
 * @return string|string[]|null
 */
function to_sql($builder): array|string|null
{
    $sql = $builder->toSql();
    foreach ($builder->getBindings() as $binding) {
        $value = is_numeric($binding) ? $binding : "'" . $binding . "'";
        $sql   = preg_replace('/\?/', $value, $sql, 1);
    }

    return $sql;
}

/**
 * 递归创建文件夹
 * @param $directoryPath
 */
function create_directories($directoryPath)
{
    $path        = '';
    $directories = explode('/', $directoryPath);
    foreach ($directories as $directory) {
        $path = $path . '/' . $directory;
        if (! is_dir(public_path($path))) {
            @mkdir(public_path($path), 0755);
        }
    }
}

/**
 * 是否安装 debugbar
 *
 * @return bool
 */
function has_debugbar(): bool
{
    return class_exists(\Barryvdh\Debugbar\Facades\Debugbar::class);
}

/**
 * PHP 代码 hook filter 埋点
 *
 * @param $hookKey
 * @param $hookValue
 * @return mixed
 */
function hook_filter($hookKey, $hookValue): mixed
{
    if (config('app.debug') && has_debugbar()) {
        Debugbar::log('HOOK === hook_filter: ' . $hookKey);
    }

    return Eventy::filter($hookKey, $hookValue);
}

/**
 * PHP 代码 hook action 埋点
 *
 * @param $hookKey
 * @param $hookValue
 */
function hook_action($hookKey, $hookValue)
{
    if (config('app.debug') && has_debugbar()) {
        Debugbar::log('HOOK === hook_action: ' . $hookKey);
    }
    Eventy::action($hookKey, $hookValue);
}

/**
 * 添加 Filter, 执行 PHP 逻辑
 *
 * @param     $hookKey
 * @param     $callback
 * @param int $priority
 * @param int $arguments
 * @return mixed
 */
function add_hook_filter($hookKey, $callback, int $priority = 20, int $arguments = 1): mixed
{
    return Eventy::addFilter(
        $hookKey,
        \Beike\Plugin\RuntimeGuard::wrapFilter($callback),
        $priority,
        $arguments,
    );
}

/**
 * 添加 Action, 执行 PHP 逻辑
 *
 * @param     $hookKey
 * @param     $callback
 * @param int $priority
 * @param int $arguments
 */
function add_hook_action($hookKey, $callback, int $priority = 20, int $arguments = 1)
{
    Eventy::addAction(
        $hookKey,
        \Beike\Plugin\RuntimeGuard::wrapAction($callback),
        $priority,
        $arguments,
    );
}

/**
 * 采用 Hook 修改 Blade 代码
 *
 * @param     $hookKey
 * @param     $callback
 * @param int $priority
 */
function add_hook_blade($hookKey, $callback, int $priority = 0)
{
    Hook::listen($hookKey, \Beike\Plugin\RuntimeGuard::wrapBlade($callback), $priority);
}

/**
 * 检测系统是否已安装
 *
 * @return bool
 */
function installed(): bool
{
    return file_exists(storage_path('installed'));
}

/**
 * 是否为移动端访问
 *
 * @return bool
 */
function is_mobile(): bool
{
    return (new \Jenssegers\Agent\Agent)->isMobile();
}

/**
 * 当前访问协议是否为 https
 *
 * @return bool
 */
function is_secure(): bool
{
    if (! empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return true;
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    } elseif (! empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off') {
        return true;
    } elseif (isset($_SERVER['SERVER_PORT']) && intval($_SERVER['SERVER_PORT']) === 443) {
        return true;
    } elseif (isset($_SERVER['REQUEST_SCHEME']) && strtolower($_SERVER['REQUEST_SCHEME']) === 'https') {
        return true;
    }

    return false;
}

/**
 * 每页商品显示数量
 *
 * @return int
 */
function perPage(): int
{
    return (int) system_setting('base.product_per_page', 20);
}

/**
 * @param      $key
 * @param      $value
 * @param bool $force
 */
function register($key, $value, bool $force = false)
{
    \Beike\Libraries\Registry::set($key, $value, $force);
}

/**
 * @param      $key
 * @param null $default
 * @return mixed
 */
function registry($key, $default = null): mixed
{
    return \Beike\Libraries\Registry::get($key, $default);
}

/**
 * 处理域名, 去除协议前缀
 *
 * @param $domain
 * @return string
 */
function clean_domain($domain): string
{
    $domain = trim($domain);
    if (empty($domain)) {
        return '';
    }

    return trim(str_replace(['http://', 'https://'], '', $domain));
}

/**
 * Check domain ha license.
 * 删除版权信息, 请先购买授权 https://beikeshop.com/vip/subscription
 *
 * @return bool
 * @throws Exception
 */
function check_license(): bool
{
    $configLicenceCode      = system_setting('base.license_code');
    $configLicenseExpiredAt = system_setting('base.license_expired_at');
    $appDomain              = clean_domain(request()->getHost());

    try {
        $domain         = new \Utopia\Domains\Domain($appDomain);
        $registerDomain = $domain->getRegisterable();
    } catch (\Exception $e) {
        $registerDomain = '';
    }

    if (filter_var($appDomain, FILTER_VALIDATE_IP)) {
        $registerDomain = $appDomain;
    }

    if (empty($registerDomain)) {
        return true;
    }

    // $configLicenceCode 有值 并且 $configLicenseExpiredAt 大于当前时间 返回 true
    if (! empty($configLicenceCode)) {
        if (! empty($configLicenseExpiredAt)) {
            $configLicenseDate = date('Y-m-d', strtotime($configLicenseExpiredAt));
            $todayDate         = date('Y-m-d');

            return $configLicenseDate >= $todayDate;
        }

        return $configLicenceCode == md5(mb_substr(md5($registerDomain), 2, 8));
    }

    return false;
}

/**
 * @param $sourceFolder
 * @param $zipPath
 * @return ZipArchive
 */
function zip_folder($sourceFolder, $zipPath): ZipArchive
{
    $zip = new ZipArchive;
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
        $dirIterator = new RecursiveDirectoryIterator($sourceFolder);
        $iterator    = new RecursiveIteratorIterator($dirIterator);
        foreach ($iterator as $file) {
            if (! $dirIterator->isDot()) {
                $filePath     = $file->getPathname();
                $relativePath = substr($filePath, strlen($sourceFolder));
                if ($file->isDir()) {
                    $zip->addEmptyDir($relativePath);
                } else {
                    $zip->addFile($filePath, $relativePath);
                }
            }
        }
        $zip->close();
    }

    return $zip;
}

/**
 * 移动文件夹
 *
 * @param $sourcePath
 * @param $destinationPath
 */
function move_dir($sourcePath, $destinationPath)
{
    $baseSourceName = basename($sourcePath);
    $files          = File::allFiles($sourcePath);
    if (empty($files)) {
        File::ensureDirectoryExists("{$destinationPath}{$baseSourceName}");
    } else {
        foreach ($files as $file) {
            $relativePath = $file->getRelativePath();
            $newBasePath  = "{$destinationPath}{$baseSourceName}/{$relativePath}/";
            $newFilePath  = $newBasePath . $file->getFilename();
            File::ensureDirectoryExists($newBasePath);
            File::move($file->getPathname(), $newFilePath);
        }
    }
    File::deleteDirectory($sourcePath);
}

/**
 * 是否有开启的翻译工具
 *
 * @return bool
 */

/**
 * @return string
 */
function beike_api_url(): string
{
    //    $adminLocale = admin_locale();
    //    if ($adminLocale == 'zh_cn') {
    //        return str_replace('beikeshop.com', 'beikeshop.cn', $apiUrl);
    //    }

    return config('beike.api_url');
}

/**
 * @return string
 */
function beike_url(): string
{
    $url         = config('beike.official_website');
    $adminLocale = admin_locale();
    if ($adminLocale == 'zh_cn') {
        return str_replace('beikeshop.com', 'beikeshop.cn', $url);
    }

    return $url;
}

/**
 * 检测当前访问域名和 .env 配置域名是否一致
 *
 * @return array
 */
function get_domain_compare_info(): array
{
    $request       = request();
    $appUrl        = (string) config('app.url', '');
    $envDomainRaw  = clean_domain($appUrl);
    $envRootDomain = get_domain($envDomainRaw);

    $host = strtolower((string) $request->getHost());
    $port = (int) $request->getPort();

    if (in_array($port, [80, 443], true)) {
        $requestDomainRaw = clean_domain($host);
    } else {
        $requestDomainRaw = $host . ':' . $port;
    }

    $requestRootDomain = get_domain($requestDomainRaw);
    $isConfigured      = $envDomainRaw !== '' && $envDomainRaw !== 'localhost';
    $isSameDomain      = $isConfigured        && $envRootDomain === $requestRootDomain;

    return [
        'same_domain'           => $isSameDomain,
        'app_url'               => $appUrl,
        'app_url_domain'        => $envDomainRaw,
        'app_url_root_domain'   => $envRootDomain,
        'current_domain'        => $requestDomainRaw,
        'current_root_domain'   => $requestRootDomain,
        'is_app_url_configured' => $isConfigured,
    ];
}

/**
 * 检测当前访问域名和 .env 配置域名是否一致
 *
 * @return bool
 */
function check_same_domain(): bool
{
    return (bool) data_get(get_domain_compare_info(), 'same_domain', false);
}

/**
 * @return bool
 */
function is_miniapp(): bool
{
    return \request()->header('platform') == 'miniapp';
}

/**
 * 返回当前域名的主域名
 * @param null $domain
 * @return string
 */
function get_domain($domain = null)
{
    // 如果没有传入域名参数，则使用当前浏览器的域名
    if (! $domain) {
        // 获取主机名并移除可能的端口号
        $host   = request()->getHttpHost() ?: ($_SERVER['HTTP_HOST'] ?? '');
        $domain = parse_url($host, PHP_URL_HOST) ?: $host;
    } else {
        // 移除 URL 中的协议部分（如 http:// 或 https://）
        $domain = parse_url($domain, PHP_URL_HOST) ?: $domain;
    }

    // 移除端口号
    $domain = preg_replace('/:\d+$/', '', $domain);

    // 常见的多级顶级域名列表
    $known_tlds = [
        'co.uk', 'gov.uk', 'ac.uk', 'org.uk', 'com.au', 'net.au',
        'com.cn', 'net.cn', 'org.cn', 'gov.cn', 'ac.cn',
    ];

    // 提取顶级域名部分
    $parts = explode('.', $domain);
    $count = count($parts);

    if ($count > 2) {
        // 处理类似 'example.co.uk' 或 'sub.example.co.uk' 的域名
        $last_two   = implode('.', array_slice($parts, -2));
        $last_three = implode('.', array_slice($parts, -3));

        if (in_array($last_two, $known_tlds)) {
            return implode('.', array_slice($parts, -3));
        } elseif (in_array($last_three, $known_tlds)) {
            return implode('.', array_slice($parts, -4));
        }
    }

    // 返回提取的主域名
    return implode('.', array_slice($parts, -2));
}

function getDBDriver()
{
    return config('database.connections.' . config('database.default') . '.driver');  // 如: mysql, sqlite, postgresql, sqlsrv
}

function is_associative_array($array): bool
{
    if (! is_array($array)) {
        return false;
    }

    $keys = array_keys($array);

    return array_keys($keys) !== $keys;
}

function isTwoDimensionalArray($array): bool
{
    if (! is_array($array)) {
        return false;
    }

    $filtered = array_filter($array, 'is_array');

    return count($filtered) > 0;
}

function replace_url($url): string
{
    return $url;
}

/**
 * 根据后台设置商品图片的原始尺寸比例，与调用处传过来的宽，计算图片的高
 *
 * @param $width
 * @return int
 */
function calculate_height_by_ratio($width): int
{
    $originWidth  = system_setting('base.product_image_origin_width', '800');
    $originHeight = system_setting('base.product_image_origin_height', '800');
    $ratio        = $originHeight / $originWidth;

    return round($width * $ratio);
}

if (! function_exists('image_path')) {
    /**
     * Get the path to the public/image folder.
     *
     * @param string $path
     * @return string
     * @throws BindingResolutionException
     */
    function image_path(string $path = ''): string
    {
        $publicPath = app()->make('path.public');
        $imagePath  = $publicPath . DIRECTORY_SEPARATOR . 'image';

        if (empty($path)) {
            return $imagePath;
        }

        return $imagePath . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }
}

if (! function_exists('tenant_files_are_isolated')) {
    function tenant_files_are_isolated(): bool
    {
        $site = current_site();

        return $site !== null && (bool) data_get($site->meta, 'isolate_files', config('saas.isolate_files', true));
    }
}

if (! function_exists('tenant_catalog_prefix')) {
    function tenant_catalog_prefix(): string
    {
        $prefix = 'image/catalog';

        return tenant_files_are_isolated() ? $prefix . '/tenants/' . current_site()->code : $prefix;
    }
}

if (! function_exists('tenant_upload_prefix')) {
    function tenant_upload_prefix(): string
    {
        $prefix = 'upload';

        return tenant_files_are_isolated() ? $prefix . '/tenants/' . current_site()->code : $prefix;
    }
}

if (! function_exists('catalog_path')) {
    /**
     * Get the path to the public/image/catalog folder.
     *
     * @param string $path
     * @return string
     * @throws BindingResolutionException
     */
    function catalog_path(string $path = ''): string
    {
        $publicPath  = app()->make('path.public');
        $catalogPath = $publicPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, tenant_catalog_prefix());

        if (empty($path)) {
            return $catalogPath;
        }

        return $catalogPath . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }
}

/**
 * 从 URL 中删除指定的查询参数
 *
 * @param string       $url
 * @param string|array $keys
 * @return string
 */
function remove_url_param(string $url, $keys): string
{
    if (! $url) {
        return $url;
    }

    // 支持传入单个 key 或多个 key
    $keys = (array) $keys;

    $parsed = parse_url($url);

    $query = [];
    if (isset($parsed['query'])) {
        parse_str($parsed['query'], $query);

        // 移除参数
        foreach ($keys as $key) {
            unset($query[$key]);
        }
    }

    // 重建 URL
    $cleanUrl = $parsed['path'];

    if (! empty($query)) {
        $cleanUrl .= '?' . http_build_query($query);
    }

    return $cleanUrl;
}

function getClientIp(?Request $request = null): ?string
{
    $request = $request ?? request();
    $ip      = $request->ip();

    // Laravel applies forwarded-address headers only when TrustProxies is
    // configured. Never read client supplied headers here: the IP is also
    // persisted on orders and drives automatic currency selection.
    return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
}

/**
 * 安全获取用户访问的 Host
 * 优先使用 app.url 配置，再使用 SERVER_NAME/SERVER_ADDR 等服务端可信变量，避免直接信任客户端传入的 Host 请求头
 *
 * @return string
 */
function get_safe_host(): string
{
    // 允许的 Host 白名单（从配置文件读取）
    $allowedHosts      = config('beike.allowed_hosts', []);
    $allowedHostsLower = array_map('strtolower', $allowedHosts);

    // 1. 优先使用 app.url 配置（应用 canonical URL，支持多域名场景）
    $appUrl = (string) config('app.url', '');
    if ($appUrl !== '') {
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        if (empty($appHost)) {
            $appHost = explode('/', clean_domain($appUrl))[0] ?? '';
            $appHost = explode(':', $appHost)[0];
        }
        if (! empty($appHost)) {
            $hostLower = strtolower($appHost);
            if (empty($allowedHosts) || in_array($hostLower, $allowedHostsLower, true)) {
                return $appHost;
            }
        }
    }

    // 2. 再从服务端变量取
    $serverName = $_SERVER['SERVER_NAME'] ?? null;
    $serverAddr = $_SERVER['SERVER_ADDR'] ?? null;

    // 3. 使用 SERVER_NAME（服务端配置的主机名）
    if (! empty($serverName)) {
        $nameLower = strtolower($serverName);
        if (empty($allowedHosts) || in_array($nameLower, $allowedHostsLower, true)) {
            return $serverName;
        }
    }

    // 4. 再退回 SERVER_ADDR（服务端 IP）
    if (! empty($serverAddr)) {
        // 一般只要是你机器 IP 就可以认为是可信，不强制在白名单
        return $serverAddr;
    }

    // 5. 如果配置了白名单，可以在严格校验后使用 HTTP_HOST 兜底
    $httpHost = $_SERVER['HTTP_HOST'] ?? null;
    if (! empty($httpHost) && ! empty($allowedHosts)) {
        // 去掉端口，只比对域名部分
        $hostWithoutPort = strtolower(explode(':', $httpHost)[0]);
        if (in_array($hostWithoutPort, $allowedHostsLower, true)) {
            return $hostWithoutPort;
        }
    }

    // 6. 最后一层兜底：返回配置中的第一个允许 host
    if (! empty($allowedHosts)) {
        return $allowedHosts[0];
    }

    // 再不行就返回空字符串
    return '';
}
