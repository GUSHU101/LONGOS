<?php

namespace App\Services\PlatformPayment;

use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use App\Saas\Support\DomainName;
use App\Saas\TenantManager;
use Plugin\Paypal\Services\PaypalSettings;
use RuntimeException;

class PlatformPaymentSettings
{
    /** @var list<string> */
    private const STRIPE_PAYMENT_MARKS = [
        'visa', 'mastercard', 'amex', 'discover', 'jcb', 'diners', 'unionpay', 'cartes_bancaires',
    ];

    public function __construct(private readonly TenantManager $tenants) {}

    public function controlSite(): Site
    {
        $current = $this->tenants->current();
        if ($current?->status === Site::STATUS_ACTIVE && (bool) data_get($current->meta, 'control_site', false)) {
            $site = $current->loadMissing('domains');
        } else {
            $configured = $this->configuredControlDomains();
            $site       = null;
            if ($configured !== []) {
                $site = Domain::query()->with('site.domains')->whereIn('host', $configured)->routable()->get()
                    ->map->site
                    ->first(static fn (?Site $candidate): bool => $candidate?->status === Site::STATUS_ACTIVE
                        && (bool) data_get($candidate->meta, 'control_site', false));
            }
            $site ??= Site::query()->with('domains')->where('status', Site::STATUS_ACTIVE)->get()
                ->first(static fn (Site $candidate): bool => (bool) data_get($candidate->meta, 'control_site', false));
        }

        if (! $site || ! $this->controlUrl($site)) {
            throw new RuntimeException('LONGOS 主站或其主域名尚未就绪。');
        }

        return $site;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $control = $this->controlSite();

        return $this->inSite($control, function () use ($control): array {
            $paypal          = PaypalSettings::all();
            $mode            = ! empty($paypal['sandbox_mode']) ? 'sandbox' : 'live';
            $platformSecrets = PaypalSettings::decryptForRuntime([
                'stripe_webhook_secret' => plugin_setting('longos_platform_pay.stripe_webhook_secret', ''),
            ]);

            return [
                'control_site_id' => (int) $control->id,
                'control_url'     => $this->controlUrl($control),
                'enabled'         => filter_var(plugin_setting('longos_platform_pay.status', false), FILTER_VALIDATE_BOOLEAN),
                'share_paypal'    => filter_var(plugin_setting('longos_platform_pay.share_paypal', true), FILTER_VALIDATE_BOOLEAN),
                'share_stripe'    => filter_var(plugin_setting('longos_platform_pay.share_stripe', true), FILTER_VALIDATE_BOOLEAN),
                'paypal'          => [
                    'api_mode'    => PaypalSettings::normalizeApiMode((string) ($paypal['api_mode'] ?? 'rest')),
                    'mode'        => $mode,
                    'client_id'   => trim((string) ($paypal["{$mode}_client_id"] ?? '')),
                    'secret'      => trim((string) ($paypal["{$mode}_secret"] ?? '')),
                    'merchant_id' => strtoupper(trim((string) ($paypal["{$mode}_merchant_id"] ?? $paypal['merchant_id'] ?? ''))),
                ],
                'stripe'          => [
                    'test_mode'       => filter_var(plugin_setting('stripe.test_mode', true), FILTER_VALIDATE_BOOLEAN),
                    'publishable_key' => trim((string) plugin_setting('stripe.publishable_key', '')),
                    'secret_key'      => trim((string) plugin_setting('stripe.secret_key', '')),
                    'webhook_secret'  => trim((string) $platformSecrets['stripe_webhook_secret']),
                ],
            ];
        });
    }

    /** @return list<string> */
    public function configuredProviders(?array $settings = null): array
    {
        $settings  ??= $this->snapshot();
        $providers     = [];
        $paypal        = $settings['paypal'];
        $merchantValid = $paypal['merchant_id'] === '' || preg_match('/^[2-9A-HJ-NP-Z]{13}$/', $paypal['merchant_id']) === 1;
        if ($paypal['api_mode'] === 'rest' && $paypal['client_id'] !== '' && $paypal['secret'] !== '' && $merchantValid) {
            $providers[] = 'paypal';
        }

        $stripe = $settings['stripe'];
        $prefix = $stripe['test_mode'] ? 'test' : 'live';
        if (preg_match('/^pk_' . $prefix . '_[A-Za-z0-9_]{16,}$/', $stripe['publishable_key'])    === 1
            && preg_match('/^sk_' . $prefix . '_[A-Za-z0-9_]{16,}$/', $stripe['secret_key'])      === 1
            && preg_match('/^whsec_[A-Za-z0-9_]{16,}$/', $stripe['webhook_secret'])               === 1) {
            $providers[] = 'stripe';
        }

        return $providers;
    }

    /** @return list<string> */
    public function availableProviders(bool $requirePlatformEnabled = true): array
    {
        return $this->availableProvidersForSnapshot($this->snapshot(), $requirePlatformEnabled);
    }

    /** @param array<string, mixed> $settings @return list<string> */
    public function availableProvidersForSnapshot(array $settings, bool $requirePlatformEnabled = true): array
    {
        if ($requirePlatformEnabled && ! $settings['enabled']) {
            return [];
        }

        $configured = $this->configuredProviders($settings);

        return array_values(array_filter(
            $configured,
            static fn (string $provider): bool => (bool) $settings['share_' . $provider],
        ));
    }

    /**
     * Convert the platform's real provider availability into storefront
     * payment marks. These marks are shared by checkout and footer rendering
     * so a child site never advertises a method the hosted page cannot use.
     *
     * @param iterable<mixed> $providers
     * @return list<string>
     */
    public static function paymentMarksForProviders(iterable $providers): array
    {
        $marks = [];
        foreach ($providers as $provider) {
            $provider = strtolower(trim(is_scalar($provider) ? (string) $provider : ''));
            if ($provider === 'paypal') {
                $marks[] = 'paypal';
            } elseif ($provider === 'stripe') {
                $marks = array_merge($marks, self::STRIPE_PAYMENT_MARKS);
            }
        }

        return array_values(array_unique($marks));
    }

    /** @return list<string> */
    public function issues(bool $requirePlatformEnabled = true): array
    {
        try {
            $settings = $this->snapshot();
        } catch (\Throwable $e) {
            return [$e->getMessage()];
        }

        return $this->issuesForSnapshot($settings, $requirePlatformEnabled);
    }

    /**
     * Runtime readiness is intentionally channel-tolerant. A temporarily
     * invalid Stripe setup must not hide a healthy shared PayPal channel (or
     * vice versa), while the stricter issues() check remains available when
     * an administrator saves the configuration.
     *
     * @return list<string>
     */
    public function runtimeIssues(bool $requirePlatformEnabled = true): array
    {
        try {
            $settings = $this->snapshot();
        } catch (\Throwable $e) {
            return [$e->getMessage()];
        }

        return $this->runtimeIssuesForSnapshot($settings, $requirePlatformEnabled);
    }

    /** @param array<string, mixed> $settings @return list<string> */
    public function runtimeIssuesForSnapshot(array $settings, bool $requirePlatformEnabled = true): array
    {
        if ($requirePlatformEnabled && ! $settings['enabled']) {
            return ['请先在 LONGOS 主站启用平台代收。'];
        }
        if ($this->availableProvidersForSnapshot($settings, false) === []) {
            return ['主站没有可供子站使用的 PayPal 或 Stripe 收款渠道。'];
        }

        return [];
    }

    /** @param array<string, mixed> $fields @return list<string> */
    public function issuesWithOverrides(array $fields, bool $requirePlatformEnabled = true): array
    {
        try {
            $settings = $this->snapshot();
        } catch (\Throwable $e) {
            return [$e->getMessage()];
        }

        foreach (['status' => 'enabled', 'share_paypal' => 'share_paypal', 'share_stripe' => 'share_stripe'] as $field => $key) {
            if (array_key_exists($field, $fields)) {
                $settings[$key] = filter_var($fields[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (trim((string) ($fields['stripe_webhook_secret'] ?? '')) !== '') {
            $settings['stripe']['webhook_secret'] = trim((string) $fields['stripe_webhook_secret']);
        }

        return $this->issuesForSnapshot($settings, $requirePlatformEnabled);
    }

    /** @param array<string, mixed> $settings @return list<string> */
    private function issuesForSnapshot(array $settings, bool $requirePlatformEnabled): array
    {
        if ($requirePlatformEnabled && ! $settings['enabled']) {
            return ['请先在 LONGOS 主站启用平台代收。'];
        }
        if (! $settings['share_paypal'] && ! $settings['share_stripe']) {
            return ['主站至少需要共享 PayPal 或 Stripe 中的一种收款方式。'];
        }

        $configured = $this->configuredProviders($settings);
        $issues     = [];
        if ($settings['share_paypal'] && ! in_array('paypal', $configured, true)) {
            $issues[] = '主站 PayPal 必须使用 REST API，并保存当前环境的 Client ID 与 Secret。';
        }
        if ($settings['share_stripe'] && ! in_array('stripe', $configured, true)) {
            $issues[] = '主站 Stripe 密钥或平台 Webhook 签名密钥尚未配置完整。';
        }

        return $issues;
    }

    public function mainUrl(string $path = ''): string
    {
        return $this->snapshot()['control_url'] . '/' . ltrim($path, '/');
    }

    private function inSite(Site $site, callable $callback): mixed
    {
        if ((int) $this->tenants->current()?->id === (int) $site->id) {
            return $callback();
        }

        return $this->tenants->run($site, $callback);
    }

    private function controlUrl(Site $site): string
    {
        $configured = $this->configuredControlDomains();

        $domain = $site->domains->first(static fn ($candidate): bool => $candidate->isRoutable()
            && in_array($candidate->host, $configured, true));
        $url    = $domain ? "{$domain->scheme}://{$domain->host}" : (string) $site->primary_url;
        if ($url !== '' && ! app()->environment(['local', 'testing']) && ! str_starts_with(strtolower($url), 'https://')) {
            throw new RuntimeException('LONGOS 主站支付域名必须使用 HTTPS。');
        }

        return rtrim($url, '/');
    }

    /** @return list<string> */
    private function configuredControlDomains(): array
    {
        $configured = [];
        foreach ((array) config('saas.control_domains', []) as $host) {
            try {
                $configured[] = DomainName::normalize((string) $host);
            } catch (\Throwable) {
            }
        }

        return array_values(array_unique($configured));
    }
}
