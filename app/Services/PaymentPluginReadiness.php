<?php

namespace App\Services;

use App\Services\PlatformPayment\PlatformPaymentSettings;
use Plugin\Paypal\Services\PaypalSettings;

final class PaymentPluginReadiness
{
    /** @return list<string> */
    public static function issues(string $code): array
    {
        return match (strtolower(trim($code))) {
            'stripe'              => self::stripeIssues(),
            'paypal'              => self::paypalIssues(),
            'longos_platform_pay' => self::platformPaymentIssues(),
            default               => [],
        };
    }

    /** @return list<string> */
    private static function platformPaymentIssues(): array
    {
        $controlSite = (bool) data_get(current_site()?->meta, 'control_site', false);

        return app(PlatformPaymentSettings::class)->runtimeIssues(! $controlSite);
    }

    public static function isReady(string $code): bool
    {
        return self::issues($code) === [];
    }

    /** @return list<string> */
    private static function stripeIssues(): array
    {
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

    /** @return list<string> */
    private static function paypalIssues(): array
    {
        $settings    = PaypalSettings::all();
        $environment = ! empty($settings['sandbox_mode']) ? 'sandbox' : 'live';
        $apiMode     = PaypalSettings::normalizeApiMode(self::firstValue(
            $settings,
            ['api_mode', 'api_standard', 'paypal_api_mode'],
            'rest'
        ));
        $issues      = [];

        if ($apiMode === 'nvp') {
            foreach (['username', 'password', 'signature'] as $field) {
                $aliases = [
                    "nvp_{$environment}_{$field}",
                    "{$environment}_nvp_{$field}",
                    "{$environment}_api_{$field}",
                    "api_{$environment}_{$field}",
                    "{$environment}_{$field}",
                    "nvp_{$field}",
                    "api_{$field}",
                    $field,
                ];
                if (self::firstValue($settings, $aliases) === '') {
                    $issues[] = 'PayPal 当前环境缺少 NVP ' . $field . '。';
                }
            }
        } else {
            foreach (["{$environment}_client_id", "{$environment}_secret"] as $field) {
                if (self::firstValue($settings, [$field]) === '') {
                    $issues[] = 'PayPal 当前环境缺少配置：' . $field . '。';
                }
            }
        }

        $merchantId = strtoupper(self::firstValue($settings, ["{$environment}_merchant_id", 'merchant_id']));
        if ($merchantId !== '' && preg_match('/^[2-9A-HJ-NP-Z]{13}$/', $merchantId) !== 1) {
            $issues[] = 'PayPal Merchant ID 格式无效。';
        }

        return $issues;
    }

    /** @param list<string> $keys */
    private static function firstValue(array $settings, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($settings[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $default;
    }
}
