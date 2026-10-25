<?php

/**
 * PaypalService.php
 *
 * @copyright  2023 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2023-08-22 11:49:51
 * @modified   2023-08-22 11:49:51
 */

namespace Plugin\Paypal\Services;

use Beike\Shop\Services\PaymentService;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Srmklive\PayPal\Services\PayPal;

class PaypalService extends PaymentService
{
    private const API_MODE_REST = 'rest';

    private const API_MODE_NVP = 'nvp';

    public ?PayPal $paypalClient = null;

    private ?PaypalNvpClient $nvpClient = null;

    /**
     * @param             $order
     * @throws \Exception
     * @throws \Throwable
     */
    public function __construct($order, bool $allowSettledOrder = false)
    {
        if ($allowSettledOrder && $order instanceof \Beike\Models\Order) {
            $this->order             = $order;
            $this->orderId           = (int) $order->id;
            $this->paymentMethodCode = (string) $order->payment_method_code;
        } else {
            parent::__construct($order);
        }

        // REST authentication is deliberately lazy. Recovery paths can verify
        // an already persisted capture without making another OAuth request.
    }

    /**
     * @return void
     * @throws \Throwable
     */
    private function initPaypal(): void
    {
        $paypalSetting = $this->getPaypalSetting();
        $mode          = $this->getPaypalEnvironment($paypalSetting);
        $this->assertConfiguration($paypalSetting, $mode, self::API_MODE_REST);
        $config        = [
            'mode'    => $mode,
            'sandbox' => [
                'client_id'     => $paypalSetting['sandbox_client_id'] ?? '',
                'client_secret' => $paypalSetting['sandbox_secret']    ?? '',
                'app_id'        => '',
            ],
            'live' => [
                'client_id'     => $paypalSetting['live_client_id'] ?? '',
                'client_secret' => $paypalSetting['live_secret']    ?? '',
                'app_id'        => '',
            ],
            'payment_action' => 'Sale',
            'currency'       => $this->getPaymentCurrency(),
            'notify_url'     => '',
            'locale'         => 'en_US',
            // Payment credentials and order data must never travel over an
            // unverified TLS connection.
            'validate_ssl'   => true,
        ];
        config(['paypal' => null]);
        $this->paypalClient = new PayPal($config);
        $this->paypalClient->setClient(new Client([
            'connect_timeout' => 10,
            'timeout'         => 30,
            'verify'          => true,
        ]));
        $this->authenticateRestClient(
            $mode,
            (string) ($config[$mode]['client_id'] ?? ''),
            (string) ($config[$mode]['client_secret'] ?? '')
        );
    }

    /**
     * @return mixed
     * @throws \Throwable
     */
    public function createOrder(): mixed
    {
        if ($this->isNvpApi()) {
            return $this->createNvpOrder();
        }

        if ($this->paypalClient === null) {
            $this->initPaypal();
        }

        $total = $this->formatPaymentAmount();
        $unit  = [
            'reference_id' => $this->getOrderNumber(),
            'amount'       => [
                'currency_code' => $this->getPaymentCurrency(),
                'value'         => $total,
            ],
            'description' => $this->getOrderDescription(),
            'custom_id'   => $this->getOrderNumber(),
            'invoice_id'  => $this->getOrderNumber(),
        ];
        $merchantId = $this->getMerchantId();
        if ($merchantId !== '') {
            $unit['payee'] = ['merchant_id' => $merchantId];
        }

        $this->paypalClient->setRequestHeaders([
            'PayPal-Request-Id' => $this->requestId('create'),
            'Prefer'            => 'return=representation',
        ]);
        $result = $this->paypalClient->createOrder([
            'intent'         => 'CAPTURE',
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'user_action' => 'PAY_NOW',
                    ],
                ],
            ],
            'purchase_units' => [$unit],
        ]);

        if (! is_array($result)) {
            throw new \RuntimeException('PayPal returned an invalid order response.');
        }
        $this->validateCreatedOrder($result);

        return $result;
    }

    public function captureRestPayment(string $paypalOrderId): array
    {
        $paypalOrderId = trim($paypalOrderId);
        if (preg_match('/^[A-Z0-9]{10,32}$/', $paypalOrderId) !== 1) {
            throw new \InvalidArgumentException('PayPal order id is required.');
        }
        if ($this->paypalClient === null) {
            $this->initPaypal();
        }

        $this->paypalClient->setRequestHeaders([
            'PayPal-Request-Id' => $this->requestId('capture', $paypalOrderId),
            'Prefer'            => 'return=representation',
        ]);
        $result = $this->paypalClient->capturePaymentOrder($paypalOrderId);
        if (! is_array($result)) {
            throw new \RuntimeException('PayPal returned an invalid capture response.');
        }
        $this->validateRestCapture($result, $paypalOrderId);

        return $result;
    }

    public function createNvpOrder(array $urls = []): array
    {
        $orderNumber = $this->getOrderNumber();
        $total       = $this->formatPaymentAmount();
        $currency    = $this->getPaymentCurrency();
        $returnUrl   = $urls['return_url'] ?? $urls['returnUrl'] ?? $this->defaultNvpReturnUrl($orderNumber);
        $cancelUrl   = $urls['cancel_url'] ?? $urls['cancelUrl'] ?? $this->defaultNvpCancelUrl($orderNumber);
        $notifyUrl   = $urls['notify_url'] ?? $urls['notifyUrl'] ?? $this->defaultNvpNotifyUrl($orderNumber);

        $parameters = [
            'PAYMENTREQUEST_0_PAYMENTACTION' => 'Sale',
            'PAYMENTREQUEST_0_AMT'           => $total,
            'PAYMENTREQUEST_0_CURRENCYCODE'  => $currency,
            'PAYMENTREQUEST_0_DESC'          => $this->getOrderDescription(),
            'RETURNURL'                      => $returnUrl,
            'CANCELURL'                      => $cancelUrl,
            'NOSHIPPING'                     => '1',
            'REQCONFIRMSHIPPING'             => '0',
            'SOLUTIONTYPE'                   => 'Sole',
            'LANDINGPAGE'                    => 'Billing',
        ];

        if ($orderNumber !== '') {
            $parameters['PAYMENTREQUEST_0_INVNUM'] = $orderNumber;
            $parameters['PAYMENTREQUEST_0_CUSTOM'] = $orderNumber;
        }

        if ($notifyUrl !== '') {
            $parameters['PAYMENTREQUEST_0_NOTIFYURL'] = $notifyUrl;
        }

        $result      = $this->getNvpClient()->setExpressCheckout($parameters);
        $token       = $result['TOKEN'];
        $approvalUrl = $result['CHECKOUT_URL'];

        return [
            'id'           => $token,
            'token'        => $token,
            'status'       => strtoupper((string) ($result['ACK'] ?? '')),
            'approval_url' => $approvalUrl,
            'links'        => [
                [
                    'href'   => $approvalUrl,
                    'rel'    => 'approve',
                    'method' => 'GET',
                ],
            ],
            'raw' => $result,
        ];
    }

    public function captureNvpPayment(string $token, string $payerId): array
    {
        $token   = trim($token);
        $payerId = trim($payerId);

        if (preg_match('/^[A-Za-z0-9-]{10,128}$/', $token)    !== 1
            || preg_match('/^[A-Za-z0-9-]{5,64}$/', $payerId) !== 1) {
            throw new \InvalidArgumentException('PayPal token and payer id are required.');
        }

        $details = $this->getNvpClient()->getExpressCheckoutDetails($token);
        $this->validateNvpDetails($details);

        if (! empty($details['PAYERID']) && (string) $details['PAYERID'] !== $payerId) {
            throw new \RuntimeException('PayPal payer id does not match checkout details.');
        }

        $parameters = [
            'TOKEN'                          => $token,
            'PAYERID'                        => $payerId,
            'PAYMENTREQUEST_0_PAYMENTACTION' => 'Sale',
            'PAYMENTREQUEST_0_AMT'           => $this->formatPaymentAmount(),
            'PAYMENTREQUEST_0_CURRENCYCODE'  => $this->getPaymentCurrency(),
            'PAYMENTREQUEST_0_DESC'          => $this->getOrderDescription(),
            'PAYMENTREQUEST_0_NOTIFYURL'     => $this->defaultNvpNotifyUrl($this->getOrderNumber()),
        ];

        $orderNumber = $this->getOrderNumber();
        if ($orderNumber !== '') {
            $parameters['PAYMENTREQUEST_0_INVNUM'] = $orderNumber;
        }

        $response = $this->getNvpClient()->doExpressCheckoutPayment($parameters);

        return [
            'id'       => $response['PAYMENTINFO_0_TRANSACTIONID'] ?? null,
            'status'   => $this->normalizeNvpPaymentStatus($response),
            'token'    => $token,
            'payer_id' => $payerId,
            'details'  => $details,
            'response' => $response,
        ];
    }

    public function verifyNvpNotification(string $rawBody): bool
    {
        if (! $this->isNvpApi()) {
            return false;
        }

        return $this->getNvpClient()->verifyIpn($rawBody);
    }

    /**
     * Validate the authenticated IPN against the immutable local order.
     * The returned subset is safe to persist and intentionally excludes
     * payer addresses, email addresses, and PayPal verification material.
     */
    public function validateNvpNotification(array $notification): array
    {
        $status = strtoupper(trim((string) ($notification['payment_status'] ?? '')));
        if ($status !== 'COMPLETED') {
            throw new \RuntimeException('PayPal IPN payment is not completed.');
        }

        $transactionId = trim((string) ($notification['txn_id'] ?? ''));
        if (preg_match('/^[A-Za-z0-9-]{8,64}$/', $transactionId) !== 1) {
            throw new \RuntimeException('PayPal IPN transaction id is invalid.');
        }

        $orderNumber = $this->getOrderNumber();
        $references  = array_values(array_filter([
            trim((string) ($notification['invoice'] ?? '')),
            trim((string) ($notification['custom'] ?? '')),
        ], static fn (string $value): bool => $value !== ''));
        if ($references === [] || ! in_array($orderNumber, $references, true)) {
            throw new \RuntimeException('PayPal IPN order reference does not match the local order.');
        }

        $amount = $notification['mc_gross'] ?? $notification['payment_gross'] ?? null;
        if ($amount === null || $this->formatAmount($amount) !== $this->formatPaymentAmount()) {
            throw new \RuntimeException('PayPal IPN amount does not match the order amount.');
        }

        $currency = strtoupper(trim((string) ($notification['mc_currency'] ?? '')));
        if ($currency !== $this->getPaymentCurrency()) {
            throw new \RuntimeException('PayPal IPN currency does not match the order currency.');
        }

        $merchantId = $this->getMerchantId();
        $receiverId = strtoupper(trim((string) ($notification['receiver_id'] ?? '')));
        if ($merchantId !== '' && ($receiverId === '' || ! hash_equals($merchantId, $receiverId))) {
            throw new \RuntimeException('PayPal IPN receiver does not match the configured merchant.');
        }

        $transactionType = strtolower(trim((string) ($notification['txn_type'] ?? '')));
        if ($transactionType !== '' && $transactionType !== 'express_checkout') {
            throw new \RuntimeException('PayPal IPN transaction type is not supported.');
        }

        return [
            'id'               => $transactionId,
            'status'           => 'COMPLETED',
            'amount'           => $this->formatPaymentAmount(),
            'currency'         => $this->getPaymentCurrency(),
            'order_number'     => $orderNumber,
            'transaction_type' => $transactionType ?: 'express_checkout',
            'verified_ipn'     => true,
        ];
    }

    public function isPaymentSuccessful(array $result): bool
    {
        $status   = strtoupper((string) ($result['status'] ?? $result['PAYMENTINFO_0_PAYMENTSTATUS'] ?? ''));
        $response = $result['response'] ?? [];

        if ($status === '' && is_array($response)) {
            $status = strtoupper((string) ($response['PAYMENTINFO_0_PAYMENTSTATUS'] ?? $response['PAYMENTSTATUS'] ?? ''));
        }

        return $status === 'COMPLETED';
    }

    /**
     * REST capture responses must refer to this exact order and amount before
     * the local order state may change to paid.
     */
    public function validateRestCapture(array $result, string $expectedPaypalOrderId = ''): void
    {
        if (! $this->isPaymentSuccessful($result)) {
            throw new \RuntimeException('PayPal payment was not completed.');
        }

        $unit = $result['purchase_units'][0] ?? [];
        if (! is_array($unit)) {
            throw new \RuntimeException('PayPal capture response is missing purchase unit data.');
        }

        $amount = $unit['payments']['captures'][0]['amount']['value']
            ?? $unit['amount']['value']
            ?? null;
        $currency = $unit['payments']['captures'][0]['amount']['currency_code']
            ?? $unit['amount']['currency_code']
            ?? null;
        if ($amount === null || $this->formatAmount($amount) !== $this->formatPaymentAmount()) {
            throw new \RuntimeException('PayPal payment amount does not match the order amount.');
        }
        if ($currency === null || strtoupper((string) $currency) !== $this->getPaymentCurrency()) {
            throw new \RuntimeException('PayPal payment currency does not match the order currency.');
        }

        if ($expectedPaypalOrderId !== '' && (string) ($result['id'] ?? '') !== $expectedPaypalOrderId) {
            throw new \RuntimeException('PayPal capture response does not match the approved PayPal order.');
        }

        $orderNumber    = $this->getOrderNumber();
        $referenceFound = false;
        foreach (['custom_id', 'invoice_id'] as $key) {
            if (! empty($unit[$key])) {
                $referenceFound = true;
            }
            if (! empty($unit[$key]) && ! hash_equals($orderNumber, (string) $unit[$key])) {
                throw new \RuntimeException('PayPal order reference does not match the local order.');
            }
        }
        if (! $referenceFound) {
            throw new \RuntimeException('PayPal capture response is missing the local order reference.');
        }

        $this->validatePayee($unit);
    }

    public function validateCreatedOrder(array $result): void
    {
        $paypalOrderId = trim((string) ($result['id'] ?? ''));
        if ($paypalOrderId === '' || ! preg_match('/^[A-Z0-9]{10,32}$/', $paypalOrderId)) {
            throw new \RuntimeException($this->providerError($result, 'PayPal did not return a valid order id.'));
        }

        $status = strtoupper(trim((string) ($result['status'] ?? '')));
        if ($status === '' || ! in_array($status, ['CREATED', 'SAVED', 'APPROVED', 'PAYER_ACTION_REQUIRED'], true)) {
            throw new \RuntimeException($this->providerError($result, 'PayPal did not create the payment order.'));
        }

        if ($this->getMerchantId() !== '') {
            $unit = $result['purchase_units'][0] ?? [];
            if (! is_array($unit)) {
                throw new \RuntimeException('PayPal order response is missing payee data.');
            }
            $this->validatePayee($unit);
        }
    }

    public function getCaptureTransactionId(array $result): string
    {
        if ($this->isNvpApi()) {
            return trim((string) ($result['id'] ?? ''));
        }

        return trim((string) ($result['purchase_units'][0]['payments']['captures'][0]['id'] ?? ''));
    }

    /**
     * Keep only fields needed for reconciliation, retry recovery, and payment
     * validation. PayPal capture responses may include payer names, emails,
     * addresses, and funding details that LONGOS does not need to retain or
     * send back to the storefront browser.
     */
    public static function safeProviderResult(array $result, bool $nvp): array
    {
        if ($nvp) {
            return self::safeNvpResult($result);
        }

        $units = [];
        foreach ((array) ($result['purchase_units'] ?? []) as $unit) {
            if (! is_array($unit)) {
                continue;
            }

            $captures = [];
            foreach ((array) data_get($unit, 'payments.captures', []) as $capture) {
                if (! is_array($capture)) {
                    continue;
                }

                $captures[] = self::compactValues([
                    'id'            => self::scalarString($capture['id'] ?? null),
                    'status'        => self::scalarString($capture['status'] ?? null),
                    'amount'        => self::safeMoney($capture['amount'] ?? null),
                    'final_capture' => is_bool($capture['final_capture'] ?? null) ? $capture['final_capture'] : null,
                    'payee'         => self::safePayee($capture['payee'] ?? null),
                ]);
            }

            $units[] = self::compactValues([
                'reference_id' => self::scalarString($unit['reference_id'] ?? null),
                'custom_id'    => self::scalarString($unit['custom_id'] ?? null),
                'invoice_id'   => self::scalarString($unit['invoice_id'] ?? null),
                'amount'       => self::safeMoney($unit['amount'] ?? null),
                'payee'        => self::safePayee($unit['payee'] ?? null),
                'payments'     => $captures === [] ? null : ['captures' => $captures],
            ]);
        }

        return self::compactValues([
            'id'             => self::scalarString($result['id'] ?? null),
            'status'         => self::scalarString($result['status'] ?? null),
            'purchase_units' => $units,
        ]);
    }

    public function validateStoredCapture(array $result, string $expectedProviderId): void
    {
        if (! $this->isNvpApi()) {
            $this->validateRestCapture($result, $expectedProviderId);

            return;
        }

        $safe = self::safeProviderResult($result, true);
        if (! $this->isPaymentSuccessful($safe)) {
            throw new \RuntimeException('PayPal payment was not completed.');
        }
        if ($expectedProviderId !== '' && ! hash_equals((string) ($safe['token'] ?? ''), $expectedProviderId)) {
            throw new \RuntimeException('PayPal capture response does not match the approved checkout token.');
        }
        if (preg_match('/^[A-Za-z0-9-]{8,64}$/', (string) ($safe['id'] ?? '')) !== 1) {
            throw new \RuntimeException('PayPal capture response is missing a valid transaction id.');
        }
        if (($safe['amount'] ?? null) === null || $this->formatAmount($safe['amount']) !== $this->formatPaymentAmount()) {
            throw new \RuntimeException('PayPal payment amount does not match the order amount.');
        }
        if (strtoupper((string) ($safe['currency'] ?? '')) !== $this->getPaymentCurrency()) {
            throw new \RuntimeException('PayPal payment currency does not match the order currency.');
        }
        if (! hash_equals($this->getOrderNumber(), (string) ($safe['order_number'] ?? ''))) {
            throw new \RuntimeException('PayPal order reference does not match the local order.');
        }
    }

    public function isNvpApi(): bool
    {
        return $this->getApiMode() === self::API_MODE_NVP;
    }

    public function getApiMode(): string
    {
        $paypalSetting = $this->getPaypalSetting();

        return PaypalSettings::normalizeApiMode((string) $this->getSettingValue(
            $paypalSetting,
            ['api_mode', 'api_standard', 'paypal_api_mode'],
            self::API_MODE_REST
        ));
    }

    private function getNvpClient(): PaypalNvpClient
    {
        if ($this->nvpClient instanceof PaypalNvpClient) {
            return $this->nvpClient;
        }

        $paypalSetting = $this->getPaypalSetting();
        $mode          = $this->getPaypalEnvironment($paypalSetting);
        $this->assertConfiguration($paypalSetting, $mode, self::API_MODE_NVP);
        $credentials   = $this->getNvpCredentials($paypalSetting, $mode);
        $timeout       = (int) $this->getSettingValue($paypalSetting, ['nvp_timeout', 'api_timeout'], 30);

        return $this->nvpClient = new PaypalNvpClient([
            'mode'      => $mode,
            'username'  => $credentials['username'],
            'password'  => $credentials['password'],
            'signature' => $credentials['signature'],
            'timeout'   => $timeout,
        ]);
    }

    /**
     * Reuse PayPal OAuth tokens until shortly before expiry. The cached value
     * is encrypted with APP_KEY and the cache key contains only one-way hashes
     * of the credentials, so neither the token nor credentials are exposed by
     * inspecting Redis/database cache entries.
     */
    private function authenticateRestClient(string $mode, string $clientId, string $secret): void
    {
        if ($this->paypalClient === null) {
            throw new \RuntimeException('PayPal REST client is unavailable.');
        }

        $cacheKey = 'paypal:oauth:' . hash('sha256', $mode . '|' . $clientId . '|' . $secret);

        try {
            $encrypted = Cache::get($cacheKey);
            if (is_string($encrypted) && $encrypted !== '') {
                $cached = json_decode(Crypt::decryptString($encrypted), true);
                if (is_array($cached)
                    && trim((string) ($cached['access_token'] ?? '')) !== ''
                    && trim((string) ($cached['token_type'] ?? ''))   !== '') {
                    $this->paypalClient->setAccessToken($cached);

                    return;
                }
            }
        } catch (\Throwable) {
            // A cache outage or stale APP_KEY must not prevent a fresh PayPal
            // authentication request.
        }

        $token = $this->paypalClient->getAccessToken();
        if (! is_array($token)
            || trim((string) ($token['access_token'] ?? '')) === ''
            || trim((string) ($token['token_type'] ?? ''))   === '') {
            throw new \RuntimeException('PayPal did not return a valid access token.');
        }

        $expiresIn = max(60, (int) ($token['expires_in'] ?? 300));
        $ttl       = max(60, $expiresIn - min(120, (int) floor($expiresIn / 5)));
        $cacheable = array_intersect_key($token, array_flip(['access_token', 'token_type', 'app_id']));

        try {
            Cache::put(
                $cacheKey,
                Crypt::encryptString(json_encode($cacheable, JSON_THROW_ON_ERROR)),
                now()->addSeconds($ttl)
            );
        } catch (\Throwable) {
            // The current request already has a valid token. Caching is only a
            // latency optimization and must never make checkout fail.
        }
    }

    private static function safeNvpResult(array $result): array
    {
        $details  = is_array($result['details'] ?? null) ? $result['details'] : [];
        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $raw      = is_array($result['raw'] ?? null) ? $result['raw'] : [];
        $approval = self::safeApprovalUrl($result['approval_url'] ?? null);

        return self::compactValues([
            'id'           => self::scalarString($result['id'] ?? $result['token'] ?? null),
            'token'        => self::scalarString($result['token'] ?? $details['TOKEN'] ?? $response['TOKEN'] ?? null),
            'status'       => self::scalarString($result['status'] ?? $response['PAYMENTINFO_0_PAYMENTSTATUS'] ?? $raw['ACK'] ?? null),
            'approval_url' => $approval,
            'links'        => $approval === null ? null : [[
                'href'   => $approval,
                'rel'    => 'approve',
                'method' => 'GET',
            ]],
            'amount' => self::scalarString(
                $result['amount']
                ?? $details['PAYMENTREQUEST_0_AMT']
                ?? $details['AMT']
                ?? $response['PAYMENTINFO_0_AMT']
                ?? $response['AMT']
                ?? null
            ),
            'currency' => self::scalarString(
                $result['currency']
                ?? $details['PAYMENTREQUEST_0_CURRENCYCODE']
                ?? $details['CURRENCYCODE']
                ?? $response['PAYMENTINFO_0_CURRENCYCODE']
                ?? $response['CURRENCYCODE']
                ?? null
            ),
            'order_number' => self::scalarString(
                $result['order_number']
                ?? $details['PAYMENTREQUEST_0_INVNUM']
                ?? $details['INVNUM']
                ?? $response['PAYMENTINFO_0_INVNUM']
                ?? $response['INVNUM']
                ?? null
            ),
        ]);
    }

    private static function safeMoney(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $money = self::compactValues([
            'currency_code' => self::scalarString($value['currency_code'] ?? null),
            'value'         => self::scalarString($value['value'] ?? null),
        ]);

        return $money === [] ? null : $money;
    }

    private static function safePayee(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $merchantId = self::scalarString($value['merchant_id'] ?? null);

        return $merchantId === null ? null : ['merchant_id' => $merchantId];
    }

    private static function safeApprovalUrl(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $url    = trim((string) $value);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host   = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $scheme === 'https' && in_array($host, ['www.paypal.com', 'www.sandbox.paypal.com'], true)
            ? $url
            : null;
    }

    private static function scalarString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private static function compactValues(array $values): array
    {
        return array_filter($values, static fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }

    private function getNvpCredentials(array $paypalSetting, string $mode): array
    {
        $prefix = $mode === 'sandbox' ? 'sandbox' : 'live';

        return [
            'username' => (string) $this->getSettingValue($paypalSetting, [
                'nvp_' . $prefix . '_username',
                $prefix . '_nvp_username',
                $prefix . '_api_username',
                'api_' . $prefix . '_username',
                $prefix . '_username',
                'nvp_username',
                'api_username',
                'username',
            ]),
            'password' => (string) $this->getSettingValue($paypalSetting, [
                'nvp_' . $prefix . '_password',
                $prefix . '_nvp_password',
                $prefix . '_api_password',
                'api_' . $prefix . '_password',
                $prefix . '_password',
                'nvp_password',
                'api_password',
                'password',
            ]),
            'signature' => (string) $this->getSettingValue($paypalSetting, [
                'nvp_' . $prefix . '_signature',
                $prefix . '_nvp_signature',
                $prefix . '_api_signature',
                'api_' . $prefix . '_signature',
                $prefix . '_signature',
                'nvp_signature',
                'api_signature',
                'signature',
            ]),
        ];
    }

    private function normalizeNvpPaymentStatus(array $response): string
    {
        $status = strtoupper((string) ($response['PAYMENTINFO_0_PAYMENTSTATUS'] ?? $response['PAYMENTSTATUS'] ?? ''));

        if ($status !== '') {
            return $status;
        }

        return strtoupper((string) ($response['ACK'] ?? 'FAILED'));
    }

    private function validateNvpDetails(array $details): void
    {
        $amount = $details['PAYMENTREQUEST_0_AMT'] ?? $details['AMT'] ?? null;

        if ($amount === null || $this->formatAmount($amount) !== $this->formatPaymentAmount()) {
            throw new \RuntimeException('PayPal payment amount does not match the order amount.');
        }

        $currency = $details['PAYMENTREQUEST_0_CURRENCYCODE'] ?? $details['CURRENCYCODE'] ?? null;

        if ($currency === null || strtoupper((string) $currency) !== $this->getPaymentCurrency()) {
            throw new \RuntimeException('PayPal payment currency does not match the order currency.');
        }

        $invoiceNumber = $details['PAYMENTREQUEST_0_INVNUM'] ?? $details['INVNUM'] ?? null;
        $orderNumber   = $this->getOrderNumber();

        if ($invoiceNumber !== null && $orderNumber !== '' && (string) $invoiceNumber !== $orderNumber) {
            throw new \RuntimeException('PayPal invoice number does not match the order number.');
        }
    }

    private function getPaypalSetting(): array
    {
        return PaypalSettings::all();
    }

    private function getPaypalEnvironment(array $paypalSetting = []): string
    {
        if ($paypalSetting === []) {
            $paypalSetting = $this->getPaypalSetting();
        }

        return ! empty($paypalSetting['sandbox_mode']) ? 'sandbox' : 'live';
    }

    public function getMerchantId(): string
    {
        $settings   = $this->getPaypalSetting();
        $prefix     = $this->getPaypalEnvironment($settings) === 'sandbox' ? 'sandbox' : 'live';
        $merchantId = strtoupper(trim((string) $this->getSettingValue($settings, [
            $prefix . '_merchant_id',
            'merchant_id',
        ])));

        if ($merchantId !== '' && ! preg_match('/^[2-9A-HJ-NP-Z]{13}$/', $merchantId)) {
            throw new \RuntimeException('The configured PayPal Merchant ID is invalid. It must contain 13 uppercase letters or digits.');
        }

        return $merchantId;
    }

    private function assertConfiguration(array $settings, string $environment, string $apiMode): void
    {
        $prefix = $environment === 'sandbox' ? 'sandbox' : 'live';
        if ($apiMode === self::API_MODE_NVP) {
            $credentials = $this->getNvpCredentials($settings, $environment);
            $missing     = array_keys(array_filter($credentials, static fn ($value): bool => trim((string) $value) === ''));
            if ($missing !== []) {
                throw new \RuntimeException(sprintf(
                    'PayPal %s NVP credentials are incomplete: %s.',
                    $environment,
                    implode(', ', $missing)
                ));
            }

            return;
        }

        $clientId = trim((string) ($settings[$prefix . '_client_id'] ?? ''));
        $secret   = trim((string) ($settings[$prefix . '_secret'] ?? ''));
        if ($clientId === '' || $secret === '') {
            throw new \RuntimeException(sprintf(
                'PayPal %s REST credentials are incomplete. Configure both Client ID and Secret.',
                $environment
            ));
        }

        // Validate the optional payee setting before making a remote request.
        $this->getMerchantId();
    }

    private function validatePayee(array $unit): void
    {
        $expected = $this->getMerchantId();
        if ($expected === '') {
            return;
        }

        $actual = strtoupper(trim((string) (
            $unit['payments']['captures'][0]['payee']['merchant_id']
            ?? $unit['payee']['merchant_id']
            ?? ''
        )));
        if ($actual === '' || ! hash_equals($expected, $actual)) {
            throw new \RuntimeException('PayPal payment payee does not match the configured Merchant ID.');
        }
    }

    private function requestId(string $operation, string $providerId = ''): string
    {
        $seed = implode('|', [
            $this->getPaypalEnvironment(),
            (string) ($this->order->id ?? ''),
            $this->getOrderNumber(),
            $operation,
            $providerId,
        ]);

        return 'longos-' . $operation . '-' . substr(hash('sha256', $seed), 0, 20);
    }

    private function providerError(array $result, string $fallback): string
    {
        $message = trim((string) (
            $result['error']['details'][0]['description']
            ?? $result['error']['message']
            ?? $result['message']
            ?? ''
        ));

        return $message !== '' ? $message : $fallback;
    }

    private function getSettingValue(array $settings, array $keys, $default = '')
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $settings) && $settings[$key] !== null && $settings[$key] !== '') {
                return $settings[$key];
            }
        }

        return $default;
    }

    private function defaultNvpReturnUrl(string $orderNumber): string
    {
        return $this->buildCallbackUrl('paypal.nvp.return', '/paypal/nvp/return', $orderNumber);
    }

    private function defaultNvpCancelUrl(string $orderNumber): string
    {
        return $this->buildCallbackUrl('paypal.nvp.cancel', '/paypal/nvp/cancel', $orderNumber);
    }

    private function defaultNvpNotifyUrl(string $orderNumber): string
    {
        return $this->buildCallbackUrl('paypal.nvp.notify', '/paypal/nvp/notify', $orderNumber);
    }

    private function buildCallbackUrl(string $routeName, string $path, string $orderNumber): string
    {
        $parameters = ['order_number' => $orderNumber];

        if (function_exists('shop_route')) {
            return shop_route($routeName, $parameters);
        }

        if (app('router')->has('shop.' . $routeName)) {
            return route('shop.' . $routeName, $parameters);
        }

        return url($path) . '?' . http_build_query($parameters);
    }

    private function getPaymentCurrency(): string
    {
        return PaypalCurrency::paymentCurrency($this->order);
    }

    private function formatPaymentAmount(): string
    {
        return $this->formatAmount(PaypalCurrency::paymentAmount($this->order));
    }

    private function formatAmount($amount): string
    {
        $decimalPlaces = PaypalCurrency::decimalPlaces($this->getPaymentCurrency());

        return number_format(round((float) $amount, $decimalPlaces), $decimalPlaces, '.', '');
    }

    private function getOrderDescription(): string
    {
        if (method_exists($this->order, 'getOrderDesc')) {
            $description = (string) $this->order->getOrderDesc(127);
        } else {
            $description = 'Order ' . $this->getOrderNumber();
        }

        $description = trim($description);

        if ($description === '') {
            $description = 'Order ' . $this->getOrderNumber();
        }

        return $this->limitString($description, 127);
    }

    private function getOrderNumber(): string
    {
        foreach (['number', 'order_number', 'order_no'] as $key) {
            $value = $this->order->{$key} ?? null;

            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        if (($this->order->id ?? null) !== null) {
            return (string) $this->order->id;
        }

        return '';
    }

    private function limitString(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }

        return substr($value, 0, $length);
    }
}
