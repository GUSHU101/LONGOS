<?php

namespace App\Services\PlatformPayment;

use App\Saas\Models\PlatformPaymentSession;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Plugin\Paypal\Services\PaypalService;
use RuntimeException;
use Srmklive\PayPal\Services\PayPal;

class PlatformPaypalGateway
{
    private PayPal $client;

    /** @var array<string, mixed> */
    private array $settings;

    public function __construct(private readonly PlatformPaymentSettings $platformSettings)
    {
        $snapshot       = $platformSettings->snapshot();
        $this->settings = $snapshot['paypal'];
        if (! in_array('paypal', $platformSettings->configuredProviders($snapshot), true)) {
            throw new RuntimeException('主站 PayPal REST 收款尚未配置完整。');
        }

        $mode   = $this->settings['mode'];
        $config = [
            'mode'    => $mode,
            'sandbox' => [
                'client_id'     => $mode === 'sandbox' ? $this->settings['client_id'] : '',
                'client_secret' => $mode === 'sandbox' ? $this->settings['secret'] : '',
                'app_id'        => '',
            ],
            'live' => [
                'client_id'     => $mode === 'live' ? $this->settings['client_id'] : '',
                'client_secret' => $mode === 'live' ? $this->settings['secret'] : '',
                'app_id'        => '',
            ],
            'payment_action' => 'Sale',
            'currency'       => 'USD',
            'notify_url'     => '',
            'locale'         => 'en_US',
            'validate_ssl'   => true,
        ];
        $this->client = new PayPal($config);
        $this->client->setClient(new Client(['connect_timeout' => 10, 'timeout' => 30, 'verify' => true]));
        $this->authenticate();
    }

    /** @return array<string, mixed> */
    public function create(PlatformPaymentSession $session, string $token): array
    {
        $unit = [
            'reference_id' => $session->uuid,
            'custom_id'    => $session->uuid,
            'invoice_id'   => $session->uuid,
            'description'  => 'LONGOS secure checkout ' . $session->uuid,
            'amount'       => [
                'currency_code' => $session->currency,
                'value'         => $this->formatAmount($session),
            ],
        ];
        if ($this->settings['merchant_id'] !== '') {
            $unit['payee'] = ['merchant_id' => $this->settings['merchant_id']];
        }

        $this->client->setRequestHeaders([
            'PayPal-Request-Id' => 'longos-platform-create-' . $session->uuid,
            'Prefer'            => 'return=representation',
        ]);
        $result = $this->client->createOrder([
            'intent'         => 'CAPTURE',
            'payment_source' => ['paypal' => ['experience_context' => [
                'user_action' => 'PAY_NOW',
                'return_url'  => $this->platformSettings->mainUrl("_longos/pay/{$token}/return/paypal"),
                'cancel_url'  => $this->platformSettings->mainUrl("_longos/pay/{$token}/cancel"),
            ]]],
            'purchase_units' => [$unit],
        ]);
        if (! is_array($result)) {
            throw new RuntimeException('PayPal 未返回有效订单。');
        }

        $id = trim((string) ($result['id'] ?? ''));
        if (preg_match('/^[A-Z0-9]{10,32}$/', $id) !== 1
            || ! in_array(strtoupper((string) ($result['status'] ?? '')), ['CREATED', 'SAVED', 'APPROVED', 'PAYER_ACTION_REQUIRED'], true)) {
            throw new RuntimeException('PayPal 创建支付订单失败。');
        }

        return ['id' => $id, 'status' => strtoupper((string) $result['status'])];
    }

    /** @return array{transaction_id: string, payload: array<string, mixed>} */
    public function capture(PlatformPaymentSession $session, string $paypalOrderId): array
    {
        if (preg_match('/^[A-Z0-9]{10,32}$/', $paypalOrderId) !== 1) {
            throw new RuntimeException('PayPal 订单编号无效。');
        }
        $this->client->setRequestHeaders([
            'PayPal-Request-Id' => 'longos-platform-capture-' . $session->uuid,
            'Prefer'            => 'return=representation',
        ]);
        $result = $this->client->capturePaymentOrder($paypalOrderId);
        if (! is_array($result) || strtoupper((string) ($result['status'] ?? '')) !== 'COMPLETED') {
            throw new RuntimeException('PayPal 付款尚未完成。');
        }
        if (! hash_equals($paypalOrderId, (string) ($result['id'] ?? ''))) {
            throw new RuntimeException('PayPal 返回的订单与支付会话不匹配。');
        }

        $unit    = $result['purchase_units'][0] ?? null;
        $capture = is_array($unit) ? data_get($unit, 'payments.captures.0') : null;
        if (! is_array($unit) || ! is_array($capture)
                              || ! hash_equals($session->uuid, (string) ($unit['custom_id'] ?? ''))
                              || ! hash_equals($session->uuid, (string) ($unit['invoice_id'] ?? ''))
                              || strtoupper((string) ($capture['status'] ?? '')) !== 'COMPLETED'
                              || ! hash_equals($this->formatAmount($session), number_format((float) data_get($capture, 'amount.value', -1), $this->decimals($session->currency), '.', ''))
                              || ! hash_equals($session->currency, strtoupper((string) data_get($capture, 'amount.currency_code', '')))) {
            throw new RuntimeException('PayPal 支付金额、币种或会话绑定校验失败。');
        }
        if ($this->settings['merchant_id'] !== '') {
            $merchant = strtoupper((string) (data_get($capture, 'payee.merchant_id') ?: data_get($unit, 'payee.merchant_id', '')));
            if ($merchant === '' || ! hash_equals($this->settings['merchant_id'], $merchant)) {
                throw new RuntimeException('PayPal 收款账户校验失败。');
            }
        }

        $safe          = PaypalService::safeProviderResult($result, false);
        $transactionId = trim((string) data_get($safe, 'purchase_units.0.payments.captures.0.id', ''));
        if (preg_match('/^[A-Za-z0-9-]{8,64}$/', $transactionId) !== 1) {
            throw new RuntimeException('PayPal 未返回有效交易编号。');
        }

        return ['transaction_id' => $transactionId, 'payload' => $safe];
    }

    public function clientId(): string
    {
        return (string) $this->settings['client_id'];
    }

    public function merchantId(): string
    {
        return (string) $this->settings['merchant_id'];
    }

    private function authenticate(): void
    {
        $mode      = (string) $this->settings['mode'];
        $clientId  = (string) $this->settings['client_id'];
        $secret    = (string) $this->settings['secret'];
        $cacheKey  = 'paypal:oauth:' . hash('sha256', $mode . '|' . $clientId . '|' . $secret);

        try {
            $encrypted = Cache::get($cacheKey);
            if (is_string($encrypted) && $encrypted !== '') {
                $cached = json_decode(Crypt::decryptString($encrypted), true);
                if (is_array($cached) && ! empty($cached['access_token']) && ! empty($cached['token_type'])) {
                    $this->client->setAccessToken($cached);

                    return;
                }
            }
        } catch (\Throwable) {
        }

        $token = $this->client->getAccessToken();
        if (! is_array($token) || empty($token['access_token']) || empty($token['token_type'])) {
            throw new RuntimeException('PayPal 身份验证失败。');
        }
        $expiresIn = max(60, (int) ($token['expires_in'] ?? 300));
        $cacheable = array_intersect_key($token, array_flip(['access_token', 'token_type', 'app_id']));

        try {
            Cache::put($cacheKey, Crypt::encryptString(json_encode($cacheable, JSON_THROW_ON_ERROR)), now()->addSeconds(max(60, $expiresIn - 120)));
        } catch (\Throwable) {
        }
    }

    private function formatAmount(PlatformPaymentSession $session): string
    {
        return number_format((float) $session->amount, $this->decimals($session->currency), '.', '');
    }

    private function decimals(string $currency): int
    {
        return in_array($currency, ['HUF', 'JPY', 'TWD'], true) ? 0 : 2;
    }
}
