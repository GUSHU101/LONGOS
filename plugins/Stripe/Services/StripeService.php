<?php

namespace Plugin\Stripe\Services;

use Beike\Models\Country;
use Beike\Models\Order;
use Beike\Shop\Services\PaymentService;
use Stripe\ApiRequestor;
use Stripe\Customer;
use Stripe\HttpClient\CurlClient;
use Stripe\Stripe;
use Stripe\StripeClient;

class StripeService extends PaymentService
{
    private StripeClient $stripeClient;

    private bool $testMode;

    public function __construct($order, bool $allowSettledOrder = false)
    {
        if ($allowSettledOrder && $order instanceof Order) {
            $this->order             = $order;
            $this->orderId           = (int) $order->id;
            $this->paymentMethodCode = (string) $order->payment_method_code;
        } else {
            parent::__construct($order);
        }

        $apiKey         = trim((string) plugin_setting('stripe.secret_key', ''));
        $this->testMode = filter_var(plugin_setting('stripe.test_mode', true), FILTER_VALIDATE_BOOLEAN);
        $expectedPrefix = $this->testMode ? 'sk_test_' : 'sk_live_';
        if (! str_starts_with($apiKey, $expectedPrefix) || strlen($apiKey) < 24) {
            throw new \RuntimeException('Stripe secret key does not match the configured test mode.');
        }

        Stripe::setMaxNetworkRetries(2);
        Stripe::setAppInfo('LONGOS', '1.2.0');
        $httpClient = (new CurlClient)->setConnectTimeout(10)->setTimeout(30);
        ApiRequestor::setHttpClient($httpClient);
        $this->stripeClient = new StripeClient($apiKey);
    }

    /** Create exactly one PaymentIntent per local order. */
    public function createPaymentIntent(string $attempt = 'initial'): array
    {
        $amount = $this->paymentAmount();
        if ($amount <= 0) {
            throw new \RuntimeException('Stripe payment amount must be greater than zero.');
        }

        $parameters = [
            'amount'               => $amount,
            'currency'             => strtolower($this->paymentCurrency()),
            'payment_method_types' => ['card'],
            'description'          => 'LONGOS order ' . (string) $this->order->number,
            'metadata'             => $this->metadata(),
        ];
        if (filter_var((string) $this->order->email, FILTER_VALIDATE_EMAIL)) {
            $parameters['receipt_email'] = (string) $this->order->email;
        }
        if ($shipping = $this->getShippingAddress()) {
            $parameters['shipping'] = $shipping;
        }

        $intent = $this->stripeClient->paymentIntents->create($parameters, [
            'idempotency_key' => $this->idempotencyKey('intent-' . hash('sha256', $attempt)),
        ]);
        $data = $intent->toArray();
        $this->validatePaymentObject($data, false);

        $clientSecret = trim((string) ($data['client_secret'] ?? ''));
        if ($clientSecret === '' || ! str_starts_with($clientSecret, (string) $data['id'] . '_secret_')) {
            throw new \RuntimeException('Stripe did not return a valid PaymentIntent client secret.');
        }

        return $this->summarizePaymentIntent($data) + ['client_secret' => $clientSecret];
    }

    /** Recover the same intent across reloads, even beyond Stripe's key TTL. */
    public function paymentIntentForCheckout(string $paymentIntentId): array
    {
        $data = $this->retrievePaymentIntent($paymentIntentId);
        $this->validatePaymentObject($data, (string) ($data['status'] ?? '') === 'succeeded');

        $clientSecret = trim((string) ($data['client_secret'] ?? ''));
        if ($clientSecret === '' || ! str_starts_with($clientSecret, (string) $data['id'] . '_secret_')) {
            throw new \RuntimeException('Stripe did not return a valid PaymentIntent client secret.');
        }

        return $this->summarizePaymentIntent($data) + ['client_secret' => $clientSecret];
    }

    /** Retrieve and verify Stripe's authoritative state after Stripe.js/3DS. */
    public function confirmPaymentIntent(string $paymentIntentId): array
    {
        $data = $this->retrievePaymentIntent($paymentIntentId);
        $this->validatePaymentObject($data, true);

        return $this->summarizePaymentIntent($data);
    }

    private function retrievePaymentIntent(string $paymentIntentId): array
    {
        $paymentIntentId = trim($paymentIntentId);
        if (preg_match('/^pi_[A-Za-z0-9_]{8,128}$/', $paymentIntentId) !== 1) {
            throw new \InvalidArgumentException('Invalid Stripe PaymentIntent id.');
        }

        return $this->stripeClient->paymentIntents->retrieve($paymentIntentId, [])->toArray();
    }

    /** Validate a signature-authenticated webhook object without another API call. */
    public function validateWebhookPayment(array $data, string $type): array
    {
        if ($type === 'payment_intent.succeeded') {
            $this->validatePaymentObject($data, true);

            return $this->summarizePaymentIntent($data);
        }

        if ($type === 'charge.succeeded') {
            $this->validateLegacyCharge($data);

            return [
                'id'       => (string) ($data['id'] ?? ''),
                'object'   => 'charge',
                'status'   => 'succeeded',
                'paid'     => true,
                'captured' => true,
                'amount'   => (int) ($data['amount'] ?? 0),
                'currency' => strtoupper((string) ($data['currency'] ?? '')),
                'livemode' => (bool) ($data['livemode'] ?? false),
            ];
        }

        throw new \InvalidArgumentException('Unsupported Stripe event type.');
    }

    /** Compatibility path for older cached checkout templates. */
    public function capture(array $creditCardData): array
    {
        $tokenId = trim((string) ($creditCardData['token'] ?? ''));
        if (preg_match('/^tok_[A-Za-z0-9_]{8,128}$/', $tokenId) !== 1) {
            throw new \InvalidArgumentException('Invalid Stripe token.');
        }

        $stripeCustomer = $this->createCustomer($tokenId);
        $parameters     = [
            'amount'      => $this->paymentAmount(),
            'currency'    => strtolower($this->paymentCurrency()),
            'metadata'    => $this->metadata(),
            'customer'    => $stripeCustomer->id,
            'description' => 'LONGOS order ' . (string) $this->order->number,
        ];
        if ($shipping = $this->getShippingAddress()) {
            $parameters['shipping'] = $shipping;
        }

        $charge = $this->stripeClient->charges->create($parameters, [
            'idempotency_key' => $this->idempotencyKey('legacy-charge'),
        ]);
        $data = $charge->toArray();
        $this->validateLegacyCharge($data);

        return [
            'id'       => (string) ($data['id'] ?? ''),
            'object'   => 'charge',
            'status'   => 'succeeded',
            'paid'     => true,
            'captured' => true,
            'amount'   => (int) $data['amount'],
            'currency' => strtoupper((string) $data['currency']),
            'livemode' => (bool) ($data['livemode'] ?? false),
        ];
    }

    private function createCustomer(string $source): Customer
    {
        $paymentCountry = Country::query()->find($this->order->payment_country_id);
        $customerData   = [
            'email'       => $this->order->email,
            'description' => setting('base.meta_title'),
            'name'        => $this->order->customer_name,
            'phone'       => $this->order->shipping_telephone,
            'address'     => [
                'city'        => $this->order->payment_city,
                'country'     => $paymentCountry->code ?? '',
                'line1'       => $this->order->payment_address_1,
                'line2'       => $this->order->payment_address_2,
                'postal_code' => $this->order->payment_zipcode,
                'state'       => $this->order->payment_zone,
            ],
            'metadata' => $this->metadata(),
            'source'   => $source,
        ];
        if ($shipping = $this->getShippingAddress()) {
            $customerData['shipping'] = $shipping;
        }

        return $this->stripeClient->customers->create($customerData, [
            'idempotency_key' => $this->idempotencyKey('legacy-customer'),
        ]);
    }

    private function validatePaymentObject(array $data, bool $requireSucceeded): void
    {
        $id = (string) ($data['id'] ?? '');
        if (preg_match('/^pi_[A-Za-z0-9_]{8,128}$/', $id) !== 1) {
            throw new \RuntimeException('Stripe returned an invalid PaymentIntent.');
        }
        $this->validateEnvironment($data);
        $this->validateOrderReference((array) ($data['metadata'] ?? []));

        if ((int) ($data['amount'] ?? -1)                     !== $this->paymentAmount()
            || strtoupper((string) ($data['currency'] ?? '')) !== $this->paymentCurrency()) {
            throw new \RuntimeException('Stripe payment amount or currency does not match the order.');
        }

        if ($requireSucceeded
            && ((string) ($data['status'] ?? '')          !== 'succeeded'
                || (int) ($data['amount_received'] ?? -1) !== $this->paymentAmount())) {
            throw new \RuntimeException('Stripe payment is not completed.');
        }
    }

    private function validateLegacyCharge(array $data): void
    {
        $this->validateEnvironment($data);
        $this->validateOrderReference((array) ($data['metadata'] ?? []));
        if (! (bool) ($data['paid'] ?? false)
            || ! (bool) ($data['captured'] ?? false)
            || (int) ($data['amount'] ?? -1)                  !== $this->paymentAmount()
            || strtoupper((string) ($data['currency'] ?? '')) !== $this->paymentCurrency()) {
            throw new \RuntimeException('Stripe charge was not completed for the expected amount.');
        }
    }

    private function validateEnvironment(array $data): void
    {
        if ((bool) ($data['livemode'] ?? false) === $this->testMode) {
            throw new \RuntimeException('Stripe response environment does not match the configured test mode.');
        }
    }

    private function validateOrderReference(array $metadata): void
    {
        $orderId = trim((string) ($metadata['order_id'] ?? ''));
        if (! hash_equals((string) $this->order->number, (string) ($metadata['order_number'] ?? ''))
            || ($orderId !== '' && ! hash_equals((string) $this->order->id, $orderId))) {
            throw new \RuntimeException('Stripe payment does not belong to this order.');
        }
    }

    private function summarizePaymentIntent(array $data): array
    {
        return [
            'id'              => (string) $data['id'],
            'object'          => 'payment_intent',
            'status'          => (string) ($data['status'] ?? ''),
            'amount'          => (int) ($data['amount'] ?? 0),
            'amount_received' => (int) ($data['amount_received'] ?? 0),
            'currency'        => strtoupper((string) ($data['currency'] ?? '')),
            'livemode'        => (bool) ($data['livemode'] ?? false),
            'order_number'    => (string) $this->order->number,
        ];
    }

    private function metadata(): array
    {
        return [
            'order_number' => (string) $this->order->number,
            'order_id'     => (string) $this->order->id,
        ];
    }

    private function idempotencyKey(string $operation): string
    {
        return 'longos-' . $operation . '-' . $this->order->id . '-'
            . strtolower($this->paymentCurrency()) . '-' . $this->paymentAmount();
    }

    private function getShippingAddress(): ?array
    {
        $shippingCountry = Country::query()->find($this->order->shipping_country_id);
        $name            = trim((string) $this->order->shipping_customer_name);
        $line1           = trim((string) $this->order->shipping_address_1);
        if ($name === '' || $line1 === '') {
            return null;
        }

        return [
            'name'    => $name,
            'phone'   => $this->order->shipping_telephone,
            'address' => [
                'city'        => $this->order->shipping_city,
                'country'     => $shippingCountry->code ?? '',
                'line1'       => $this->order->shipping_address_1,
                'line2'       => $this->order->shipping_address_2,
                'postal_code' => $this->order->shipping_zipcode,
                'state'       => $this->order->shipping_zone,
            ],
        ];
    }

    public function paymentAmount(): int
    {
        return StripeCurrency::paymentAmount($this->order);
    }

    public function paymentCurrency(): string
    {
        return StripeCurrency::paymentCurrency($this->order);
    }
}
