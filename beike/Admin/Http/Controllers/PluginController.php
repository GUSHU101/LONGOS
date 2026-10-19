<?php

/**
 * SettingController.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-06-29 16:02:15
 * @modified   2022-06-29 16:02:15
 */

namespace Beike\Admin\Http\Controllers;

use Beike\Admin\Http\Resources\PluginResource;
use Beike\Admin\Services\MarketingService;
use Beike\Plugin\Plugin;
use Beike\Repositories\PluginRepo;
use Beike\Repositories\SettingRepo;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use League\CommonMark\GithubFlavoredMarkdownConverter;

class PluginController extends Controller
{
    /**
     * @throws Exception
     */
    public function index()
    {
        $plugins         = app('plugin')->getPlugins();
        $data['plugins'] = array_values(PluginResource::collection($plugins)->jsonSerialize());
        $data            = hook_filter('admin.plugin.index.data', $data);

        $this->updateVersion($data['plugins']);

        return view('admin::pages.plugins.index', $data);
    }

    /**
     * A lightweight, read-only health check for every local plugin. It is kept
     * separate from installation so merchants can find an incomplete upgrade
     * before enabling a feature on the storefront.
     */
    public function diagnostics(): JsonResponse
    {
        $checks = app('plugin')->getPlugins()->map(function (Plugin $plugin): array {
            $path      = rtrim($plugin->getPath(), DIRECTORY_SEPARATOR);
            $issues    = [];
            $manifest  = $path . DIRECTORY_SEPARATOR . 'config.json';
            $bootstrap = $path . DIRECTORY_SEPARATOR . 'Bootstrap.php';

            if (! is_file($manifest)) {
                $issues[] = '缺少 config.json 清单文件。';
            } else {
                try {
                    json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $issues[] = 'config.json 不是有效 JSON。';
                }
            }

            if (! is_file($bootstrap)) {
                $issues[] = '缺少 Bootstrap.php 入口文件。';
            }

            $migrationFiles = glob($path . DIRECTORY_SEPARATOR . 'Migrations' . DIRECTORY_SEPARATOR . '*.php') ?: [];
            $pending        = $this->pendingPluginMigrations($migrationFiles);
            if ($pending !== []) {
                $issues[] = '有 ' . count($pending) . ' 个待执行迁移：' . implode('、', $pending);
            }

            $configuration = $this->pluginConfigurationStatus($plugin);
            if ($plugin->getEnabled() && $configuration['missing'] !== []) {
                $issues[] = '缺少必填配置：' . implode('、', $configuration['missing']);
            }

            if ($plugin->code === 'pixel_tracking' && $plugin->getEnabled()
                                                   && $this->trackingChannelsEmpty()) {
                $issues[] = '像素追踪已启用，但尚未配置 Meta 或 TikTok 像素通道。';
            }

            if ($plugin->getEnabled() && in_array($plugin->code, ['paypal', 'stripe', 'pixel_tracking'], true)) {
                $issues = array_merge($issues, $this->integrationConfigurationIssues($plugin->code));
            }

            $names = is_array($plugin->name) ? $plugin->name : ['en' => (string) $plugin->name];

            return [
                'code'               => $plugin->code,
                'name'               => $names[locale()] ?? $names['en'] ?? $plugin->code,
                'installed'          => PluginRepo::installed($plugin->code),
                'enabled'            => $plugin->getEnabled(),
                'status'             => $issues === [] ? 'healthy' : 'attention',
                'issues'             => $issues,
                'configuration'      => $configuration,
                'pending_migrations' => count($pending),
            ];
        })->values();

        return response()->json([
            'plugins'   => $checks,
            'healthy'   => $checks->where('status', 'healthy')->count(),
            'attention' => $checks->where('status', 'attention')->count(),
        ]);
    }

    /**
     * @return mixed
     */
    public function shipping()
    {
        $type            = 'shipping';
        $plugins         = app('plugin')->getPlugins();
        $plugins         = $plugins->where('type', $type);
        $data['plugins'] = array_values(PluginResource::collection($plugins)->jsonSerialize());
        $data['type']    = $type;
        $data            = hook_filter('admin.plugin.index.data', $data);
        $this->updateVersion($data['plugins']);

        return view('admin::pages.plugins.index', $data);
    }

    /**
     * @return mixed
     */
    public function payment()
    {
        $type            = 'payment';
        $plugins         = app('plugin')->getPlugins();
        $plugins         = $plugins->where('type', $type);
        $data['plugins'] = array_values(PluginResource::collection($plugins)->jsonSerialize());
        $data['type']    = $type;
        $data            = hook_filter('admin.plugin.index.data', $data);

        $this->updateVersion($data['plugins']);

        return view('admin::pages.plugins.index', $data);
    }

    /**
     * @return mixed
     */
    public function marketing()
    {
        $type            = 'marketing';
        $plugins         = app('plugin')->getPlugins();
        $plugins         = $plugins->where('type', $type);
        $data['plugins'] = array_values(PluginResource::collection($plugins)->jsonSerialize());
        $data['type']    = $type;
        $data            = hook_filter('admin.plugin.index.data', $data);
        $this->updateVersion($data['plugins']);

        return view('admin::pages.plugins.index', $data);
    }

    /**
     * @return mixed
     */
    public function analysis()
    {
        $type            = 'analysis';
        $plugins         = app('plugin')->getPlugins();
        $plugins         = $plugins->where('type', $type);
        $data['plugins'] = array_values(PluginResource::collection($plugins)->jsonSerialize());
        $data['type']    = $type;
        $data            = hook_filter('admin.plugin.index.data', $data);
        $this->updateVersion($data['plugins']);

        return view('admin::pages.plugins.index', $data);
    }

    /**
     * @return mixed
     */
    public function feature()
    {
        $type            = 'feature';
        $plugins         = app('plugin')->getPlugins();
        $plugins         = $plugins->where('type', $type);
        $data['plugins'] = array_values(PluginResource::collection($plugins)->jsonSerialize());
        $data['type']    = $type;
        $data            = hook_filter('admin.plugin.index.data', $data);
        $this->updateVersion($data['plugins']);

        return view('admin::pages.plugins.index', $data);
    }

    /**
     * @return mixed
     */
    public function theme()
    {
        $type            = 'theme';
        $plugins         = app('plugin')->getPlugins();
        $plugins         = $plugins->where('type', $type);
        $data['plugins'] = array_values(PluginResource::collection($plugins)->jsonSerialize());
        $data['type']    = $type;
        $data            = hook_filter('admin.plugin.index.data', $data);
        $this->updateVersion($data['plugins']);

        return view('admin::pages.plugins.index', $data);
    }

    public function service()
    {
        $type            = 'service';
        $plugins         = app('plugin')->getPlugins();
        $plugins         = $plugins->where('type', $type);
        $data['plugins'] = array_values(PluginResource::collection($plugins)->jsonSerialize());
        $data['type']    = $type;
        $data            = hook_filter('admin.plugin.index.data', $data);
        $this->updateVersion($data['plugins']);

        return view('admin::pages.plugins.index', $data);
    }

    /**
     * @param Request $request
     * @param         $code
     * @return JsonResponse
     * @throws Exception
     */
    public function install(Request $request, $code): JsonResponse
    {
        try {
            $plugin = app('plugin')->getPluginOrFail($code);
            PluginRepo::installPlugin($plugin);

            $aspectConfig = storage_path('aspect/config/html.config');
            if (file_exists($aspectConfig)) {
                @unlink($aspectConfig);
            }

            return json_success(trans('common.success'));
        } catch (\Exception $e) {
            return json_fail($e->getMessage());
        }
    }

    /**
     * @param Request $request
     * @param         $code
     * @return JsonResponse
     * @throws Exception
     */
    public function uninstall(Request $request, $code): JsonResponse
    {
        try {

            $plugin = app('plugin')->getPluginOrFail($code);
            PluginRepo::uninstallPlugin($plugin);

            return json_success(trans('common.success'));
        } catch (\Exception $e) {
            return json_fail($e->getMessage());
        }
    }

    /**
     * @param Request $request
     * @param         $code
     * @return View
     * @throws Exception
     */
    public function edit(Request $request, $code): View
    {
        try {
            $plugin     = app('plugin')->getPluginOrFail($code);
            $columnView = $plugin->getColumnView();
            $view       = $columnView ?: 'admin::pages.plugins.form';

            $data = [
                'view'             => $view,
                'plugin'           => $plugin,
                'pluginReadmeHtml' => $this->getPluginReadmeHtml($plugin),
            ];
            $data = hook_filter('admin.plugin.edit.data', $data);

            return view($view, $data);
        } catch (\Exception $e) {
            $plugin = app('plugin')->getPlugin($code);
            $data   = [
                'error'       => $e->getMessage(),
                'plugin_code' => $code,
                'plugin'      => $plugin,
            ];

            return view('admin::pages.plugins.error', $data);
        }
    }

    private function getPluginReadmeHtml(Plugin $plugin): ?string
    {
        $readmePath = rtrim($plugin->getPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'README.md';
        if (! is_file($readmePath)) {
            return null;
        }

        $markdown = file_get_contents($readmePath);
        if (! is_string($markdown) || trim($markdown) === '') {
            return null;
        }

        $converter = new GithubFlavoredMarkdownConverter([
            // Plugin documentation is rendered inside the privileged admin
            // surface. Markdown is supported, but embedded raw HTML must not
            // become an administration XSS primitive.
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return (string) $converter->convert($markdown);
    }

    /**
     * @param Request $request
     * @param         $code
     * @return mixed
     * @throws Exception
     */
    public function update(Request $request, $code)
    {
        $fields = $request->all();
        $plugin = app('plugin')->getPluginOrFail($code);
        $fields = $this->preserveBlankPluginSecrets($plugin, $fields);
        $fields = $this->preserveBlankPixelTrackingTokens($plugin, $fields);
        if (method_exists($plugin, 'validate')) {
            $validator = $plugin->validate($fields);
            if ($validator->fails()) {
                return back()->withErrors($validator)->withInput();
            }
        }

        if ($plugin->code === 'pixel_tracking') {
            $fields['meta_pixels']   = \Plugin\PixelTracking\Services\PixelProfiles::normalizeForStorage($fields['meta_pixels'] ?? '[]', 'meta');
            $fields['tiktok_pixels'] = \Plugin\PixelTracking\Services\PixelProfiles::normalizeForStorage($fields['tiktok_pixels'] ?? '[]', 'tiktok');
        }

        // Saving an enabled plugin from the web UI also applies migrations
        // introduced by a newer package. This keeps panel deployments usable
        // without requiring a tenant merchant to run Artisan manually.
        if ((bool) ($fields['status'] ?? $plugin->getEnabled())) {
            PluginRepo::migrateDatabase($plugin);
        }

        $data = ['plugin_code' => $code, 'fields' => $fields];
        hook_action('admin.plugin.update.before', $data);

        SettingRepo::update('plugin', $code, $fields);

        hook_action('admin.plugin.update.after', $data);

        return redirect($this->getRedirect())->with('success', trans('common.updated_success'));
    }

    /**
     * Password fields are intentionally not rendered with their stored value.
     * A blank submission therefore means "keep the existing secret", not
     * "erase it". This is shared by every plugin using password columns.
     */
    private function preserveBlankPluginSecrets(Plugin $plugin, array $fields): array
    {
        foreach ($plugin->getColumns() as $column) {
            $name = $column['name'] ?? '';
            $type = strtolower((string) ($column['type'] ?? ''));
            if ($name === '' || ! in_array($type, ['password', 'secret'], true) || ! array_key_exists($name, $fields)) {
                continue;
            }

            if (trim((string) $fields[$name]) !== '') {
                continue;
            }

            $existing = plugin_setting("{$plugin->code}.{$name}", '');
            if ($existing !== '') {
                $fields[$name] = $existing;
            }
        }

        return $fields;
    }

    /**
     * Pixel profiles are edited through a visual card interface. Keep a stored
     * server token when its password input is intentionally left empty, so a
     * merchant can edit an ID or test code without ever exposing the token.
     */
    private function preserveBlankPixelTrackingTokens(Plugin $plugin, array $fields): array
    {
        if ($plugin->code !== 'pixel_tracking') {
            return $fields;
        }

        foreach (['meta_pixels' => 'pixel_id', 'tiktok_pixels' => 'pixel_code'] as $field => $identifier) {
            if (! array_key_exists($field, $fields)) {
                continue;
            }

            $incoming = json_decode((string) $fields[$field], true);
            $stored   = json_decode((string) plugin_setting("pixel_tracking.{$field}", '[]'), true);
            if (! is_array($incoming) || ! is_array($stored)) {
                continue;
            }

            $tokens = [];
            foreach ($stored as $profile) {
                $key = trim((string) ($profile[$identifier] ?? ''));
                if ($key !== '' && ! empty($profile['access_token'])) {
                    $tokens[$key] = (string) $profile['access_token'];
                }
            }

            foreach ($incoming as &$profile) {
                $key         = trim((string) ($profile[$identifier] ?? ''));
                $originalKey = trim((string) ($profile['_saved_identifier'] ?? $key));
                if ($key !== '' && trim((string) ($profile['access_token'] ?? '')) === '' && isset($tokens[$originalKey])) {
                    $profile['access_token'] = $tokens[$originalKey];
                }
            }
            unset($profile);

            $fields[$field] = json_encode($incoming, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $fields;
    }

    /**
     * @param Request $request
     * @param         $code
     * @return JsonResponse
     */
    public function updateStatus(Request $request, $code): JsonResponse
    {
        try {
            $plugin = app('plugin')->getPluginOrFail($code);
            $status = $request->get('status');
            if ((bool) $status) {
                // Also apply migrations introduced after a plugin was originally installed.
                // This is safe because Laravel skips migrations already recorded in the migrations table.
                PluginRepo::migrateDatabase($plugin);
            }
            SettingRepo::update('plugin', $code, ['status' => $status]);

            return json_success($status ? trans('admin/common.text_enabled') : trans('admin/common.text_closed'));
        } catch (\Exception $e) {
            return json_fail($e->getMessage());
        }
    }

    private function updateVersion(&$plugins): void
    {
        // Plugin packages and their source code are shared by every SaaS site.
        // Tenant administrators may configure locally approved plugins, but
        // they must neither query nor be offered platform-wide updates.
        if (config('saas.enabled') && ! (bool) data_get(current_site()?->meta, 'control_site', false)) {
            foreach ($plugins as &$item) {
                $item['can_update'] = false;
            }

            return;
        }

        // 判断版本是否可以更新
        $freePluginCodes = config('app.free_plugin_codes');
        $pluginCodes     = \Beike\Models\Plugin::pluck('code')->toArray();
        $pluginCodes     = array_diff($pluginCodes, $freePluginCodes);
        $pluginCodes     = implode('|', $pluginCodes);
        $pluginVersion   = MarketingService::getInstance()->checkPluginVersion($pluginCodes);
        $pluginData      = data_get($pluginVersion, 'data');

        if ($pluginData) {
            $pluginData = array_filter($pluginData, function ($value) {
                return $value !== false;
            });
        }

        foreach ($plugins as &$item) {
            if ($item['can_update'] && isset($pluginData[$item['code']])) {
                $newVersion     = str_replace('v', '', $pluginData[$item['code']]);
                $currentVersion = str_replace('v', '', $item['version']);

                if (version_compare($newVersion, $currentVersion) > 0) {
                    $item['can_update'] = true;
                } else {
                    $item['can_update'] = false;
                }
            } else {
                $item['can_update'] = false;
            }
        }
    }

    public function pluginTicketExpired(Request $request)
    {
        try {
            $pluginCode = $request->get('plugin_code');

            return MarketingService::getInstance()->checkPluginTicketExpired($pluginCode);
        } catch (\Exception $e) {
            return json_fail($e->getMessage());
        }
    }

    private function pendingPluginMigrations(array $files): array
    {
        if ($files === []) {
            return [];
        }

        try {
            if (! Schema::hasTable('migrations')) {
                return array_map(static fn (string $file) => basename($file, '.php'), $files);
            }

            $names     = array_map(static fn (string $file) => basename($file, '.php'), $files);
            $completed = DB::table('migrations')->whereIn('migration', $names)->pluck('migration')->all();

            return array_values(array_diff($names, $completed));
        } catch (\Throwable) {
            // A diagnostics page must not fail merely because the database is
            // temporarily unavailable; installation will still validate it.
            return [];
        }
    }

    /** Summarise only fields that must be supplied by a merchant to operate. */
    private function pluginConfigurationStatus(Plugin $plugin): array
    {
        $required = [];
        $missing  = [];

        try {
            foreach ($plugin->getColumns() as $column) {
                $name = (string) ($column['name'] ?? '');
                if ($name === '' || $name === 'status' || empty($column['required'])) {
                    continue;
                }

                $required[] = $name;
                $value      = $column['value'] ?? null;
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    $missing[] = (string) ($column['label'] ?? $name);
                }
            }
        } catch (\Throwable) {
            // Diagnostics must remain useful while a database is recovering.
        }

        return [
            'required' => count($required),
            'complete' => max(0, count($required) - count($missing)),
            'missing'  => $missing,
        ];
    }

    private function trackingChannelsEmpty(): bool
    {
        foreach (['meta_pixels', 'tiktok_pixels'] as $key) {
            $value = json_decode((string) plugin_setting("pixel_tracking.{$key}", '[]'), true);
            if (is_array($value) && $value !== []) {
                return false;
            }
        }

        return true;
    }

    /** @return string[] */
    private function integrationConfigurationIssues(string $code): array
    {
        if ($code === 'stripe') {
            $testMode = filter_var(plugin_setting('stripe.test_mode', true), FILTER_VALIDATE_BOOLEAN);
            $public   = trim((string) plugin_setting('stripe.publishable_key', ''));
            $secret   = trim((string) plugin_setting('stripe.secret_key', ''));
            $webhook  = trim((string) plugin_setting('stripe.webhook_secret', ''));
            $prefix   = $testMode ? 'test' : 'live';
            $issues   = [];
            if (preg_match('/^pk_' . $prefix . '_[A-Za-z0-9_]{16,}$/', $public) !== 1) {
                $issues[] = 'Stripe Publishable key 与当前测试/正式模式不匹配。';
            }
            if (preg_match('/^sk_' . $prefix . '_[A-Za-z0-9_]{16,}$/', $secret) !== 1) {
                $issues[] = 'Stripe Secret key 与当前测试/正式模式不匹配。';
            }
            if (preg_match('/^whsec_[A-Za-z0-9_]{16,}$/', $webhook) !== 1) {
                $issues[] = 'Stripe Webhook signing secret 无效。';
            }

            return $issues;
        }

        if ($code === 'paypal') {
            $settings    = (array) plugin_setting('paypal', []);
            $environment = ! empty($settings['sandbox_mode']) ? 'sandbox' : 'live';
            $apiMode     = strtolower(trim((string) ($settings['api_mode'] ?? 'rest')));
            $issues      = [];
            $required    = $apiMode === 'nvp'
                ? ["{$environment}_api_username", "{$environment}_api_password", "{$environment}_api_signature"]
                : ["{$environment}_client_id", "{$environment}_secret"];
            foreach ($required as $field) {
                if (trim((string) ($settings[$field] ?? '')) === '') {
                    $issues[] = 'PayPal 当前环境缺少配置：' . $field . '。';
                }
            }
            $merchantId = strtoupper(trim((string) ($settings["{$environment}_merchant_id"] ?? '')));
            if ($merchantId !== '' && preg_match('/^[2-9A-HJ-NP-Z]{13}$/', $merchantId) !== 1) {
                $issues[] = 'PayPal Merchant ID 格式无效。';
            }

            return $issues;
        }

        $issues = [];
        foreach (['meta_pixels' => 'meta', 'tiktok_pixels' => 'tiktok'] as $field => $channel) {
            $value = plugin_setting("pixel_tracking.{$field}", '[]');
            if ($message = \Plugin\PixelTracking\Services\PixelProfiles::validationError($value, $channel)) {
                $issues[] = ($channel === 'meta' ? 'Meta' : 'TikTok') . ' 像素配置无效：' . $message;
            }
        }
        if (! in_array(plugin_setting('pixel_tracking.server_tracking', true), [false, 0, '0'], true)) {
            foreach (array_merge(
                \Plugin\PixelTracking\Services\PixelProfiles::meta(),
                \Plugin\PixelTracking\Services\PixelProfiles::tiktok(),
            ) as $profile) {
                if (trim((string) ($profile['access_token'] ?? '')) === '') {
                    $identifier = $profile['pixel_id'] ?? $profile['pixel_code'] ?? 'unknown';
                    $issues[]   = '服务端追踪缺少访问令牌：' . $identifier . '。';
                }
            }
        }

        return $issues;
    }
}
