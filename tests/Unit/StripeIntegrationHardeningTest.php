<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class StripeIntegrationHardeningTest extends TestCase
{
    public function test_charge_and_webhook_validate_the_actual_gateway_currency(): void
    {
        $root       = dirname(__DIR__, 2);
        $service    = (string) file_get_contents($root . '/plugins/Stripe/Services/StripeService.php');
        $controller = (string) file_get_contents($root . '/plugins/Stripe/Controllers/StripeController.php');
        $checkout   = (string) file_get_contents($root . '/plugins/Stripe/Views/checkout/payment.blade.php');

        self::assertStringContainsString('StripeCurrency::paymentCurrency', $service);
        self::assertStringContainsString('StripeCurrency::paymentAmount', $service);
        self::assertStringContainsString('validateWebhookPayment(', $controller);
        self::assertStringContainsString('StripeCurrency::paymentCurrency', $checkout);
        self::assertStringNotContainsString('strtolower((string) $this->order->currency_code)', $service);
        self::assertStringContainsString('createPaymentIntent(', $service);
        self::assertStringContainsString('confirmCardPayment(', $checkout);
        self::assertStringContainsString('Stripe::setMaxNetworkRetries(2)', $service);
        self::assertStringContainsString('setConnectTimeout(10)->setTimeout(30)', $service);
        self::assertStringContainsString('validateWebhookPayment(', $controller);
        self::assertStringContainsString('new StripeService($order, true)', $controller);
        self::assertStringNotContainsString('->setShipment()', $controller);
    }

    public function test_enabled_settings_require_matching_key_environment_and_webhook_secret(): void
    {
        $valid = $this->validateSettings([
            'status'          => '1',
            'test_mode'       => '1',
            'publishable_key' => 'pk_test_1234567890abcdefghijkl',
            'secret_key'      => 'sk_test_1234567890abcdefghijkl',
            'webhook_secret'  => 'whsec_1234567890abcdefghijkl',
        ]);
        self::assertTrue($valid->errors()->isEmpty());

        $mixed = $this->validateSettings([
            'status'          => '1',
            'test_mode'       => '0',
            'publishable_key' => 'pk_test_1234567890abcdefghijkl',
            'secret_key'      => 'sk_live_1234567890abcdefghijkl',
            'webhook_secret'  => 'whsec_1234567890abcdefghijkl',
        ]);
        self::assertTrue($mixed->errors()->has('publishable_key'));

        $missingWebhook = $this->validateSettings([
            'status'          => '1',
            'test_mode'       => '1',
            'publishable_key' => 'pk_test_1234567890abcdefghijkl',
            'secret_key'      => 'sk_test_1234567890abcdefghijkl',
        ]);
        self::assertTrue($missingWebhook->errors()->has('webhook_secret'));

        self::assertTrue($this->validateSettings(['status' => '0', 'test_mode' => '1'])->errors()->isEmpty());
    }

    private function validateSettings(array $payload): \Illuminate\Contracts\Validation\Validator
    {
        $previous  = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $container->instance('request', Request::create('/stripe/settings', 'POST', $payload));

        try {
            $columns    = require dirname(__DIR__, 2) . '/plugins/Stripe/columns.php';
            $rules      = array_column($columns, 'rules', 'name');
            $translator = new Translator(new ArrayLoader, 'en');

            $validator = (new Factory($translator, $container))->make($payload, $rules);
            $validator->fails();

            return $validator;
        } finally {
            Container::setInstance($previous);
        }
    }
}
