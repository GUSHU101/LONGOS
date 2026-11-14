<?php

namespace App\Services\PlatformPayment;

use App\Saas\Models\PlatformPaymentSession;
use App\Saas\Support\DomainName;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Srmklive\PayPal\Services\PayPal;
use Throwable;

class PlatformPaypalGateway
{
    private PayPal $client;

    /** @var array<string, mixed> */
    private array $settings;

    private bool $authenticatedFromCache = false;

    /** @param array<string, mixed>|null $snapshot */
    public function __construct(
        private readonly PlatformPaymentSettings $platformSettings,
        ?array $snapshot = null,
        ?PlatformPaymentSession $session = null,
    ) {
        $snapshot       ??= $platformSettings->snapshot();
        $this->settings = $session
            ? $platformSettings->paypalForSession($session)
            : $snapshot['paypal'];
        if (! $platformSettings->validPaypalConfiguration($this->settings)) {
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
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw new RuntimeException('PayPal 回传令牌无效。');
        }

        $returnPath = "_longos/pay/{$token}/return/paypal";
        $cancelPath = "_longos/pay/{$token}/cancel";

        try {
            $returnUrl = $this->platformSettings->paymentUrlForSession($session, $returnPath);
            $cancelUrl = $this->platformSettings->paymentUrlForSession($session, $cancelPath);
            $this->assertManagedCallbackUrl($session, $returnUrl, '/' . $returnPath);
            $this->assertManagedCallbackUrl($session, $cancelUrl, '/' . $cancelPath);
        } catch (Throwable $exception) {
            // No PayPal request has been sent yet. A managed session may be
            // moved to another healthy account without risking a duplicate
            // provider order or charge.
            if ($session->payment_account_id) {
                throw new PlatformPaymentAccountUnavailable('PayPal 专用回传域名暂不可用。', 0, $exception);
            }

            throw $exception;
        }

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
        // Each managed account uses its own direct REST Client ID and Secret.
        // In that integration PayPal derives the receiving merchant from the
        // OAuth caller. Supplying a payee is reserved for partner/marketplace
        // flows and can make an otherwise valid direct-account order fail.

        $this->client->setRequestHeaders([
            'PayPal-Request-Id' => $this->requestId('create', $session),
            'Prefer'            => 'return=representation',
        ]);
        $result = $this->client->createOrder([
            'intent'         => 'CAPTURE',
            'payment_source' => ['paypal' => ['experience_context' => [
                'user_action' => 'PAY_NOW',
                // The provider returns only to this session's verified,
                // account-owned callback hostname.
                'return_url'  => $returnUrl,
                'cancel_url'  => $cancelUrl,
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

        $approvalUrl = $this->approvalUrl($result);
        if ($approvalUrl === null) {
            throw new RuntimeException('PayPal 未返回有效付款地址。');
        }

        return [
            'id'           => $id,
            'status'       => strtoupper((string) $result['status']),
            'approval_url' => $approvalUrl,
        ];
    }

    private function assertManagedCallbackUrl(
        PlatformPaymentSession $session,
        string $url,
        string $expectedPath,
    ): void {
        if (! $session->payment_account_id) {
            return;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            throw new RuntimeException('PayPal 专用回传地址无效。');
        }

        try {
            $expectedHost = DomainName::normalize((string) $session->callback_host);
            $actualHost   = DomainName::normalize((string) ($parts['host'] ?? ''));
        } catch (\Throwable) {
            throw new RuntimeException('PayPal 专用回传地址无效。');
        }

        if (! hash_equals('https', strtolower((string) ($parts['scheme'] ?? '')))
            || ! hash_equals($expectedHost, $actualHost)
            || ! hash_equals($expectedPath, (string) ($parts['path'] ?? ''))
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new RuntimeException('PayPal 回传地址未使用该收款账户绑定的专用域名。');
        }
    }

    /** @return array{transaction_id: string, payload: array<string, mixed>} */
    public function capture(PlatformPaymentSession $session, string $paypalOrderId): array
    {
        if (preg_match('/^[A-Z0-9]{10,32}$/', $paypalOrderId) !== 1) {
            throw new RuntimeException('PayPal 订单编号无效。');
        }

        try {
            $this->client->setRequestHeaders([
                'PayPal-Request-Id' => $this->requestId('capture', $session),
                'Prefer'            => 'return=representation',
            ]);
            $result = $this->client->capturePaymentOrder($paypalOrderId);
            if (! is_array($result)) {
                throw new RuntimeException('PayPal 付款尚未完成。');
            }

            return $this->completedResult($session, $paypalOrderId, $result);
        } catch (Throwable $captureFailure) {
            // The capture request may have reached PayPal even when its HTTP
            // response was lost. Query the order before reporting failure so
            // an already-completed charge is posted to the tenant immediately
            // instead of waiting for scheduled reconciliation.
            try {
                $existing = $this->client->showOrderDetails($paypalOrderId);
                if (is_array($existing) && strtoupper((string) ($existing['status'] ?? '')) === 'COMPLETED') {
                    return $this->completedResult($session, $paypalOrderId, $existing);
                }
            } catch (Throwable) {
            }

            throw $captureFailure;
        }
    }

    /** @return array{transaction_id: string, payload: array<string, mixed>}|null */
    public function recoverCompleted(PlatformPaymentSession $session, string $paypalOrderId): ?array
    {
        if (preg_match('/^[A-Z0-9]{10,32}$/', $paypalOrderId) !== 1) {
            throw new RuntimeException('PayPal 订单编号无效。');
        }

        $result = $this->client->showOrderDetails($paypalOrderId);
        if (! is_array($result)) {
            throw new RuntimeException('PayPal 未返回有效订单状态。');
        }
        if (! hash_equals($paypalOrderId, (string) ($result['id'] ?? ''))) {
            throw new RuntimeException('PayPal 返回的订单与支付会话不匹配。');
        }
        $this->assertOrderBinding($session, $paypalOrderId, $result);

        $status = strtoupper((string) ($result['status'] ?? ''));
        if ($status === 'APPROVED') {
            // Approval is the customer's authorization to capture. Recover a
            // browser interruption between PayPal approval and our onApprove
            // callback with the same deterministic capture idempotency key.
            return $this->capture($session, $paypalOrderId);
        }
        if ($status !== 'COMPLETED') {
            return null;
        }

        return $this->completedResult($session, $paypalOrderId, $result);
    }

    /** @param array<string, mixed> $result @return array{transaction_id: string, payload: array<string, mixed>} */
    private function completedResult(PlatformPaymentSession $session, string $paypalOrderId, array $result): array
    {
        if (strtoupper((string) ($result['status'] ?? '')) !== 'COMPLETED') {
            throw new RuntimeException('PayPal 付款尚未完成。');
        }
        $this->assertOrderBinding($session, $paypalOrderId, $result);

        $unit    = $result['purchase_units'][0] ?? null;
        $capture = is_array($unit) ? data_get($unit, 'payments.captures.0') : null;
        if (! is_array($unit) || ! is_array($capture)
                              || strtoupper((string) ($capture['status'] ?? '')) !== 'COMPLETED'
                              || ! hash_equals($this->formatAmount($session), PlatformPaymentAmount::format((string) data_get($capture, 'amount.value', ''), $this->decimals($session->currency)))
                              || ! hash_equals($session->currency, strtoupper((string) data_get($capture, 'amount.currency_code', '')))) {
            throw new RuntimeException('PayPal 支付金额、币种或会话绑定校验失败。');
        }
        $this->auditExpectedMerchantWhenReturned(
            (string) (data_get($capture, 'payee.merchant_id') ?: data_get($unit, 'payee.merchant_id', '')),
        );

        $safe          = PlatformPaymentPayload::paypal($result);
        $transactionId = trim((string) data_get($safe, 'purchase_units.0.payments.captures.0.id', ''));
        if (preg_match('/^[A-Za-z0-9-]{8,64}$/', $transactionId) !== 1) {
            throw new RuntimeException('PayPal 未返回有效交易编号。');
        }

        return ['transaction_id' => $transactionId, 'payload' => $safe];
    }

    /** @param array<string, mixed> $result */
    private function assertOrderBinding(PlatformPaymentSession $session, string $paypalOrderId, array $result): void
    {
        $unit = $result['purchase_units'][0] ?? null;
        if (! hash_equals($paypalOrderId, (string) ($result['id'] ?? ''))
            || ! is_array($unit)
            || ! hash_equals($session->uuid, (string) ($unit['custom_id'] ?? ''))
            || ! hash_equals($session->uuid, (string) ($unit['invoice_id'] ?? ''))
            || ! hash_equals($this->formatAmount($session), PlatformPaymentAmount::format((string) data_get($unit, 'amount.value', ''), $this->decimals($session->currency)))
            || ! hash_equals($session->currency, strtoupper((string) data_get($unit, 'amount.currency_code', '')))) {
            throw new RuntimeException('PayPal 订单金额、币种或会话绑定校验失败。');
        }
        $this->auditExpectedMerchantWhenReturned((string) data_get($unit, 'payee.merchant_id', ''));
    }

    public function clientId(): string
    {
        return (string) $this->settings['client_id'];
    }

    public function merchantId(): string
    {
        return (string) $this->settings['merchant_id'];
    }

    /**
     * A direct Client ID/Secret already fixes the payee to the account that
     * owns those credentials. PayPal does not include payee data in every
     * successful representation, so an optional Merchant ID is audit-only.
     * A mismatch is intentionally non-blocking: rejecting a completed
     * capture would leave a real buyer charge unreconciled. The mandatory
     * session, order, amount, currency and transaction-id checks above still
     * protect the payment state.
     */
    private function auditExpectedMerchantWhenReturned(string $merchant): void
    {
        $expected = strtoupper(trim((string) $this->settings['merchant_id']));
        $merchant = strtoupper(trim($merchant));

        if ($expected === '' || $merchant === '' || hash_equals($expected, $merchant)) {
            return;
        }

        Log::warning('Platform Pay received an unexpected PayPal merchant identifier.', [
            'account_id' => $this->settings['account_id'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $result */
    private function approvalUrl(array $result): ?string
    {
        foreach ((array) ($result['links'] ?? []) as $link) {
            if (! is_array($link)
                || ! in_array(strtolower((string) ($link['rel'] ?? '')), ['approve', 'payer-action'], true)) {
                continue;
            }

            $url    = trim((string) ($link['href'] ?? ''));
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $host   = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($scheme === 'https' && in_array($host, ['www.paypal.com', 'www.sandbox.paypal.com'], true)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Construction authenticates with PayPal (or restores a still-valid,
     * encrypted OAuth token). Requiring that token here gives the admin
     * health check a stable contract without creating a customer order.
     */
    public function healthCheck(): void
    {
        // A cache miss already performed a live OAuth exchange in the
        // constructor. Only revalidate cached credentials over the network.
        if ($this->authenticatedFromCache) {
            $token = $this->client->getAccessToken();
            if (! is_array($token) || empty($token['access_token']) || empty($token['token_type'])) {
                throw new RuntimeException('PayPal 身份验证失败。');
            }
        }
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
                    $this->authenticatedFromCache = true;

                    return;
                }
            }
        } catch (\Throwable) {
        }

        try {
            $token = $this->client->getAccessToken();
        } catch (Throwable $exception) {
            // OAuth happens before Create Order, so no buyer payment or
            // provider order exists yet. The controller can safely use this
            // explicit type for a session-preserving account failover.
            throw new PlatformPaymentAccountUnavailable('PayPal 账户身份验证暂不可用。', 0, $exception);
        }
        if (! is_array($token) || empty($token['access_token']) || empty($token['token_type'])) {
            throw new PlatformPaymentAccountUnavailable('PayPal 账户身份验证失败。');
        }
        $expiresIn = max(1, (int) ($token['expires_in'] ?? 300));
        $cacheable = array_intersect_key($token, array_flip(['access_token', 'token_type', 'app_id']));

        if ($expiresIn > 120) {
            try {
                Cache::put($cacheKey, Crypt::encryptString(json_encode($cacheable, JSON_THROW_ON_ERROR)), now()->addSeconds($expiresIn - 120));
            } catch (\Throwable) {
            }
        }
    }

    private function formatAmount(PlatformPaymentSession $session): string
    {
        return PlatformPaymentAmount::format((string) $session->amount, $this->decimals($session->currency));
    }

    private function requestId(string $operation, PlatformPaymentSession $session): string
    {
        // Keep the provider idempotency value compact while retaining a
        // deterministic, operation-specific key for safe network retries.
        return 'lp-' . substr(hash('sha256', $operation . '|' . $session->uuid), 0, 32);
    }

    private function decimals(string $currency): int
    {
        return PlatformPaymentCurrency::decimalPlaces($currency);
    }
}
