<?php

namespace App\Services\PlatformPayment;

use App\Saas\Models\PlatformPaymentSession;
use Beike\Services\CurrencyCatalog;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\Stripe;
use Stripe\StripeClient;

class PlatformStripeGateway
{
    private StripeClient $client;

    /** @var array<string, mixed> */
    private array $settings;

    public function __construct(private readonly PlatformPaymentSettings $platformSettings)
    {
        $snapshot       = $platformSettings->snapshot();
        $this->settings = $snapshot['stripe'];
        if (! in_array('stripe', $platformSettings->configuredProviders($snapshot), true)) {
            throw new RuntimeException('主站 Stripe 收款尚未配置完整。');
        }

        Stripe::setMaxNetworkRetries(2);
        Stripe::setAppInfo('LONGOS Platform Pay', '1.0.0');
        ApiRequestor::setHttpClient((new CurlClient)->setConnectTimeout(10)->setTimeout(30));
        $this->client = new StripeClient((string) $this->settings['secret_key']);
    }

    /** @return array{id: string, client_secret: string, status: string} */
    public function create(PlatformPaymentSession $session): array
    {
        $intent = $this->client->paymentIntents->create([
            'amount'               => $this->minorAmount($session),
            'currency'             => strtolower($session->currency),
            'payment_method_types' => ['card'],
            'description'          => 'LONGOS secure checkout ' . $session->uuid,
            'metadata'             => [
                'platform_session' => $session->uuid,
                'site_id'          => (string) $session->site_id,
            ],
        ], ['idempotency_key' => 'longos-platform-intent-' . $session->uuid]);
        $data = $intent->toArray();
        $this->validate($session, $data, false);

        $secret = trim((string) ($data['client_secret'] ?? ''));
        if ($secret === '' || ! str_starts_with($secret, (string) $data['id'] . '_secret_')) {
            throw new RuntimeException('Stripe 未返回有效的客户端确认密钥。');
        }

        return ['id' => (string) $data['id'], 'client_secret' => $secret, 'status' => (string) $data['status']];
    }

    /** @return array{transaction_id: string, payload: array<string, mixed>} */
    public function confirm(PlatformPaymentSession $session, string $paymentIntentId): array
    {
        if (preg_match('/^pi_[A-Za-z0-9_]{8,128}$/', $paymentIntentId) !== 1) {
            throw new RuntimeException('Stripe PaymentIntent 编号无效。');
        }
        $data = $this->client->paymentIntents->retrieve($paymentIntentId, [])->toArray();
        $this->validate($session, $data, true);

        return ['transaction_id' => $paymentIntentId, 'payload' => $this->safePayload($data)];
    }

    /** @param array<string, mixed> $data */
    public function validate(PlatformPaymentSession $session, array $data, bool $requireSucceeded): void
    {
        if (preg_match('/^pi_[A-Za-z0-9_]{8,128}$/', (string) ($data['id'] ?? '')) !== 1
            || (bool) ($data['livemode'] ?? false) === (bool) $this->settings['test_mode']
            || ! hash_equals($session->uuid, (string) data_get($data, 'metadata.platform_session', ''))
            || ! hash_equals((string) $session->site_id, (string) data_get($data, 'metadata.site_id', ''))
            || (int) ($data['amount'] ?? -1) !== $this->minorAmount($session)
            || ! hash_equals($session->currency, strtoupper((string) ($data['currency'] ?? '')))) {
            throw new RuntimeException('Stripe 支付金额、币种、环境或会话绑定校验失败。');
        }
        if ($requireSucceeded && ((string) ($data['status'] ?? '') !== 'succeeded'
            || (int) ($data['amount_received'] ?? -1)              !== $this->minorAmount($session))) {
            throw new RuntimeException('Stripe 付款尚未完成。');
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function safePayload(array $data): array
    {
        return [
            'id'               => (string) ($data['id'] ?? ''),
            'object'           => 'payment_intent',
            'status'           => (string) ($data['status'] ?? ''),
            'amount'           => (int) ($data['amount'] ?? 0),
            'amount_received'  => (int) ($data['amount_received'] ?? 0),
            'currency'         => strtoupper((string) ($data['currency'] ?? '')),
            'livemode'         => (bool) ($data['livemode'] ?? false),
            'platform_session' => (string) data_get($data, 'metadata.platform_session', ''),
        ];
    }

    public function publishableKey(): string
    {
        return (string) $this->settings['publishable_key'];
    }

    public function webhookSecret(): string
    {
        return (string) $this->settings['webhook_secret'];
    }

    private function minorAmount(PlatformPaymentSession $session): int
    {
        $decimals = CurrencyCatalog::decimalPlaces($session->currency);

        return (int) round((float) $session->amount * (10 ** $decimals));
    }
}
