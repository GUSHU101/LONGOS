<?php

/**
 * SettingService.php
 *
 * @copyright  2023 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2023-12-13 12:12:57
 * @modified   2023-12-13 12:12:57
 */

namespace Beike\Admin\Services;

use App\Mail\MailSenderProfileService;
use Beike\Repositories\SettingRepo;
use Beike\Repositories\StorePolicyRepo;
use Beike\Services\CurrencyService;
use Beike\Services\StorePolicyTemplateService;
use Beike\Services\TimezoneService;
use Exception;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SettingService
{
    /**
     * 保存系统设置
     * @throws \Throwable
     */
    public static function storeSettings(array $settings): void
    {
        $settings = array_diff_key($settings, array_flip(['_token', '_method', 'return_url']));
        // A SaaS deployment has one shared route cache. Tenant-level admin
        // prefixes would make generated links diverge from the cached routes.
        if (config('saas.enabled')) {
            unset($settings['admin_name']);
        }
        $hasRateKeyField = array_key_exists('rate_api_key', $settings);
        $rateKeyEntered  = $hasRateKeyField && trim((string) $settings['rate_api_key']) !== '';
        $oldRateKey      = $hasRateKeyField
            ? self::decryptRateApiKey((string) system_setting('base.rate_api_key', ''))
            : '';
        $settings   = self::prepareMailSettings($settings);
        $settings   = self::prepareCurrencySettings($settings);
        $settings   = self::prepareStorePolicyProfile($settings);
        $settings   = self::prepareStorePolicies($settings);
        $settings   = self::prepareBrandSettings($settings);
        $settings   = self::prepareTimezoneSettings($settings);

        // The settings page is the activation boundary for automatic local
        // currency. Verify and persist a complete snapshot before changing the
        // credential. This removes the former manual CLI step and, on failure,
        // preserves both the saved key and the last known-good rate table.
        if ($hasRateKeyField) {
            $newRateKey     = self::decryptRateApiKey((string) $settings['rate_api_key']);
            $rateKeyChanged = ! hash_equals($oldRateKey, $newRateKey);
            $rateStatus     = CurrencyService::getInstance()->rateStatus();
            if ($newRateKey !== '' && ($rateKeyEntered || $rateKeyChanged || ! ($rateStatus['available'] ?? false))) {
                try {
                    $rates = CurrencyService::getInstance()->verifyAndRefreshRates($newRateKey);
                } catch (Throwable $exception) {
                    throw new Exception('汇率已获取但无法写入数据库，请确认 exchange_rates 表已迁移且数据库账号具有写入权限。', 0, $exception);
                }
                if ($rates === []) {
                    $reason = (string) (CurrencyService::getInstance()->lastRefreshOutcome()['reason'] ?? 'provider_failed');
                    $detail = match ($reason) {
                        'invalid_or_inactive_key' => 'API Key 无效或账户未激活',
                        'provider_quota_reached'  => 'ExchangeRate-API 账户配额已用尽',
                        'provider_unavailable'    => 'ExchangeRate-API 服务暂时不可用',
                        'network_error'           => '服务器无法建立出站 HTTPS 连接',
                        'provider_payload_incomplete', 'provider_payload_invalid', 'provider_rejected' => '汇率服务返回的数据不完整',
                        default => '无法验证汇率服务',
                    };

                    throw new Exception("无法更新汇率：{$detail}。原有密钥和汇率快照均未修改。");
                }
            }
        }

        // Storefront content is English; the administration interface is Chinese.
        $settings['admin_locale'] = 'zh_cn';
        $settings['locale']       = 'en';
        // Product prices and checkout settlement always use USD. This is an
        // invariant, not a setting: visitor currencies are display-only.
        $settings['currency']     = 'USD';

        if (isset($settings['admin_locale']) && ! in_array($settings['admin_locale'], admin_languages(), true)) {
            throw new Exception('后台主语言必须使用项目已提供的后台语言包。');
        }

        // 验证 admin_name 字段，仅支持小写字母和数字
        if (isset($settings['admin_name'])) {
            $adminName = $settings['admin_name'];
            if (! preg_match('/^[a-z0-9]+$/', $adminName)) {
                throw new Exception(trans('admin/setting.admin_name_error'));
            }
        }

        DB::transaction(static function () use ($settings): void {
            foreach ($settings as $key => $value) {
                SettingRepo::storeValue($key, $value, 'base', 'system', false);
            }
        });
        SettingRepo::clearCache();

        if (array_key_exists('app_timezone', $settings)) {
            $timezone = TimezoneService::apply($settings['app_timezone']);
            self::syncCurrentSiteTimezone($timezone);
        }
    }

    /** Reject invalid imports and canonicalize legacy PHP timezone aliases. */
    private static function prepareTimezoneSettings(array $settings): array
    {
        if (! array_key_exists('app_timezone', $settings)) {
            return $settings;
        }

        $timezone = trim((string) $settings['app_timezone']);
        if (! TimezoneService::isSupported($timezone)) {
            throw new Exception(trans('admin/setting.app_timezone_invalid'));
        }

        $settings['app_timezone'] = TimezoneService::normalize($timezone);

        return $settings;
    }

    /** Keep the control-plane tenant record aligned for switches and workers. */
    private static function syncCurrentSiteTimezone(string $timezone): void
    {
        $site = function_exists('current_site') ? current_site() : null;
        if (! $site || ! $site->getKey() || $site->timezone === $timezone) {
            return;
        }

        try {
            $site->newQuery()->whereKey($site->getKey())->update(['timezone' => $timezone]);
            $site->timezone = $timezone;
        } catch (Throwable $exception) {
            // Tenant settings are the runtime source of truth. A temporary
            // control database failure is visible in logs and can self-heal on
            // the next save without rolling back an otherwise valid setting.
            Log::warning('Unable to synchronize the tenant timezone metadata.', [
                'site_id'   => $site->getKey(),
                'timezone'  => $timezone,
                'exception' => $exception,
            ]);
        }
    }

    private static function prepareCurrencySettings(array $settings): array
    {
        if (! array_key_exists('rate_api_key', $settings)) {
            return $settings;
        }

        if (array_key_exists('rate_api_key', $settings)) {
            $storedRateApiKey  = (string) system_setting('base.rate_api_key', '');
            $currentRateApiKey = self::decryptRateApiKey($storedRateApiKey);
            $rateApiKey        = trim((string) $settings['rate_api_key']);
            if ($rateApiKey === '') {
                $settings['rate_api_key'] = $storedRateApiKey;
                $rateApiKey               = $currentRateApiKey;
            }
            if ($rateApiKey !== '' && preg_match('/^[A-Za-z0-9_-]{8,128}$/', $rateApiKey) !== 1) {
                throw new Exception('汇率 API Key 格式不正确。');
            }
            // Upgrade legacy plaintext credentials on the next settings save,
            // including when the password field is intentionally left blank.
            if ($rateApiKey !== '' && ! str_starts_with(trim((string) $settings['rate_api_key']), 'encrypted:')) {
                $settings['rate_api_key'] = 'encrypted:' . Crypt::encryptString($rateApiKey);
            }
        }

        return $settings;
    }

    /** Keep CSS tokens and uploaded brand asset references safe at the write boundary. */
    private static function prepareBrandSettings(array $settings): array
    {
        foreach (['brand_primary_color', 'brand_accent_color'] as $key) {
            if (! array_key_exists($key, $settings)) {
                continue;
            }
            $value = strtoupper(trim((string) $settings[$key]));
            if (! preg_match('/^#[0-9A-F]{6}$/', $value)) {
                throw new Exception('品牌颜色必须是完整的十六进制颜色，例如 #155EEF。');
            }
            $settings[$key] = $value;
        }

        if (array_key_exists('brand_border_radius', $settings)) {
            $radius = filter_var($settings['brand_border_radius'], FILTER_VALIDATE_INT);
            if ($radius === false || $radius < 0 || $radius > 32) {
                throw new Exception('全局圆角必须是 0 到 32 之间的整数。');
            }
            $settings['brand_border_radius'] = $radius;
        }

        if (array_key_exists('storefront_preset', $settings)
            && ! in_array($settings['storefront_preset'], ['minimal', 'soft', 'editorial'], true)) {
            throw new Exception('请选择有效的前台视觉预设。');
        }

        foreach (['logo', 'favicon', 'placeholder'] as $key) {
            if (! array_key_exists($key, $settings)) {
                continue;
            }
            $value = trim((string) $settings[$key]);
            if ($value !== '' && ! self::isSafeBrandAsset($value)) {
                throw new Exception('品牌图片地址无效，请通过图片选择器重新上传。');
            }
            $settings[$key] = $value;
        }

        if (array_key_exists('store_name', $settings)) {
            $settings['store_name'] = mb_substr(trim(strip_tags((string) $settings['store_name'])), 0, 100);
            if ($settings['store_name'] === '') {
                throw new Exception('店铺名称不能为空。');
            }
        }

        return $settings;
    }

    private static function isSafeBrandAsset(string $value): bool
    {
        if (preg_match('/[\x00-\x1F\x7F]/u', $value)
            || str_contains($value, '\\')
            || str_contains($value, '..')
            || str_starts_with($value, '//')) {
            return false;
        }
        if (preg_match('#^https?://#i', $value)) {
            return filter_var($value, FILTER_VALIDATE_URL) !== false;
        }

        return ! str_contains($value, ':') && preg_match('~^/?[^?#]+$~u', $value) === 1;
    }

    private static function prepareStorePolicies(array $settings): array
    {
        if (! array_key_exists('store_policies', $settings)) {
            return $settings;
        }

        if (! is_array($settings['store_policies'])) {
            throw new Exception(trans('admin/setting.policies_invalid'));
        }

        foreach (StorePolicyRepo::DEFINITIONS as $key => $definition) {
            $policy  = is_array($settings['store_policies'][$key] ?? null) ? $settings['store_policies'][$key] : [];
            $title   = is_scalar($policy['title'] ?? null) ? (string) $policy['title'] : '';
            $content = is_scalar($policy['content'] ?? null) ? (string) $policy['content'] : '';
            if (mb_strlen($title) > 120) {
                throw new Exception(trans('admin/setting.policy_title_too_long', ['title' => $definition['default_title']]));
            }
            if (mb_strlen($content) > 200000) {
                throw new Exception(trans('admin/setting.policy_content_too_long', ['title' => $definition['default_title']]));
            }
        }

        $settings['store_policies'] = StorePolicyRepo::normalizeSettings($settings['store_policies']);
        foreach ($settings['store_policies'] as $policy) {
            if ($policy['enabled'] && ! StorePolicyRepo::hasMeaningfulContent($policy['content'])) {
                throw new Exception(trans('admin/setting.policy_content_required', ['title' => $policy['title']]));
            }
        }

        return $settings;
    }

    private static function prepareStorePolicyProfile(array $settings): array
    {
        if (! array_key_exists('store_policy_profile', $settings)) {
            return $settings;
        }

        try {
            $settings['store_policy_profile'] = StorePolicyTemplateService::normalizeProfile(
                $settings['store_policy_profile']
            );
        } catch (\InvalidArgumentException $e) {
            throw new Exception($e->getMessage());
        }

        return $settings;
    }

    private static function decryptRateApiKey(string $value): string
    {
        $value = trim($value);
        if (! str_starts_with($value, 'encrypted:')) {
            return $value;
        }

        try {
            return trim(Crypt::decryptString(substr($value, strlen('encrypted:'))));
        } catch (\Throwable) {
            throw new Exception('已保存的汇率 API Key 无法解密。请重新填写并保存新的 Key。');
        }
    }

    /**
     * Keep secret values out of the HTML form while preserving them when an
     * administrator saves unrelated mail settings. Blank secret inputs mean
     * “keep the existing credential”, not “erase it”.
     */
    private static function prepareMailSettings(array $settings): array
    {
        if (! array_key_exists('mail_engine', $settings)) {
            return $settings;
        }

        $engine         = strtolower(trim((string) $settings['mail_engine']));
        $allowedEngines = ['', 'self_hosted', 'smtp', 'sendmail', 'mailgun', 'sendcloud', 'sendgrid', 'log'];
        if (! in_array($engine, $allowedEngines, true)) {
            throw new Exception('不支持的邮件引擎。');
        }
        $settings['mail_engine'] = $engine;

        if (array_key_exists('mail_log_retention_days', $settings)) {
            $retentionDays = filter_var($settings['mail_log_retention_days'], FILTER_VALIDATE_INT);
            if ($retentionDays === false || $retentionDays < 1 || $retentionDays > 365) {
                throw new Exception(trans('admin/setting.mail_log_retention_invalid'));
            }
            $settings['mail_log_retention_days'] = $retentionDays;
        }

        self::preserveBlankSecret($settings, 'smtp', 'password');
        self::preserveBlankSecret($settings, 'mailgun', 'secret');
        self::preserveBlankSecret($settings, 'sendcloud', 'api_key');
        self::preserveBlankSecret($settings, 'sendgrid', 'api_key');

        $legacy = isset($settings['mail_from']) && is_array($settings['mail_from'])
            ? $settings['mail_from']
            : (array) system_setting('base.mail_from', []);
        $profiles = isset($settings['mail_senders']) && is_array($settings['mail_senders'])
            ? $settings['mail_senders']
            : (array) system_setting('base.mail_senders', []);
        $senderProfiles           = app(MailSenderProfileService::class)->prepareForStorage($profiles, $legacy, $engine);
        $settings['mail_senders'] = $senderProfiles;
        // Keep the legacy key synchronized for plugins and imports that still
        // read base.mail_from directly.
        $settings['mail_from'] = [
            'address' => $senderProfiles[MailSenderProfileService::DEFAULT]['address'],
            'name'    => $senderProfiles[MailSenderProfileService::DEFAULT]['name'],
        ];
        $fromAddress = $settings['mail_from']['address'];

        // A transport without a valid sender is rejected by every provider,
        // usually only after an order has been placed. Validate it on save.
        if ($engine !== '') {
            $fromAddress = $fromAddress ?: trim((string) system_setting('base.email', ''));
            if (! filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('请先填写有效的发件邮箱，或在基础设置中填写有效的联系邮箱。');
            }
        }

        if ($engine === 'smtp') {
            $smtp = $settings['smtp'] ?? [];
            if (! is_array($smtp) || trim((string) ($smtp['host'] ?? '')) === '') {
                throw new Exception('SMTP 主机不能为空。');
            }

            $host = trim((string) $smtp['host']);
            if (mb_strlen($host) > 253 || preg_match('/[\s\x00-\x1F\x7F]/', $host)) {
                throw new Exception('SMTP 主机格式不正确。请只填写主机名或 IP 地址，不要包含协议和端口。');
            }

            $port = filter_var($smtp['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            if ($port === false) {
                throw new Exception('SMTP 端口必须是 1 至 65535 之间的数字。');
            }

            $encryption = strtolower(trim((string) ($smtp['encryption'] ?? 'tls')));
            if (! in_array($encryption, ['tls', 'ssl', 'none'], true)) {
                throw new Exception('SMTP 加密方式仅支持 TLS、SSL 或无加密。');
            }

            $timeout = filter_var($smtp['timeout'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 60]]);
            if ($timeout === false) {
                throw new Exception('SMTP 超时时间必须是 1 至 60 秒之间的数字。');
            }

            $smtp['host']       = $host;
            $smtp['username']   = trim((string) ($smtp['username'] ?? ''));
            $smtp['port']       = $port;
            $smtp['encryption'] = $encryption;
            $smtp['timeout']    = $timeout;
            $settings['smtp']   = $smtp;
        }

        if ($engine === 'sendmail') {
            $path = trim((string) ($settings['sendmail']['path'] ?? ''));
            if ($path === '' || preg_match('/[\r\n\x00]/', $path)) {
                throw new Exception('Sendmail 执行路径不能为空，且不能包含换行符。');
            }
            $settings['sendmail']['path'] = $path;
        }

        foreach (['mailgun' => ['domain', 'secret'], 'sendcloud' => ['api_user', 'api_key'], 'sendgrid' => ['api_key']] as $provider => $fields) {
            if ($engine !== $provider) {
                continue;
            }
            foreach ($fields as $field) {
                if (trim((string) ($settings[$provider][$field] ?? '')) === '') {
                    throw new Exception("{$provider} 的 {$field} 不能为空。");
                }
            }

            if (in_array($provider, ['sendcloud', 'sendgrid'], true)) {
                $endpoint = rtrim(trim((string) ($settings[$provider]['endpoint'] ?? '')), '/');
                if (! filter_var($endpoint, FILTER_VALIDATE_URL) || parse_url($endpoint, PHP_URL_SCHEME) !== 'https') {
                    throw new Exception("{$provider} 的 API 端点必须是有效的 HTTPS 地址。");
                }
                $settings[$provider]['endpoint'] = $endpoint;
            }
        }

        foreach (['mail_alert', 'mail_customer'] as $key) {
            $values         = $settings[$key] ?? [];
            $values         = is_array($values) ? $values : [$values];
            $settings[$key] = array_values(array_intersect(['register', 'order', 'return'], $values));
        }

        return $settings;
    }

    private static function preserveBlankSecret(array &$settings, string $group, string $field): void
    {
        if (! isset($settings[$group]) || ! is_array($settings[$group]) || ! array_key_exists($field, $settings[$group])) {
            return;
        }

        if (trim((string) $settings[$group][$field]) !== '') {
            return;
        }

        $existing = system_setting("base.{$group}.{$field}", '');
        if ($existing !== '') {
            $settings[$group][$field] = $existing;
        }
    }
}
