<?php

namespace Tests\Unit;

use App\Saas\Models\Site;
use App\Services\PlatformPayment\PlatformPaymentCurrency;
use App\Services\PlatformPayment\PlatformPaymentPayload;
use App\Services\PlatformPayment\PlatformPaymentSettings;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;
use Plugin\LongosPlatformPay\Services\PlatformPaymentCredentials;

class PlatformPaymentIndependenceTest extends TestCase
{
    public function test_platform_plugin_does_not_read_legacy_paypal_or_stripe_plugin_implementations(): void
    {
        $root = dirname(__DIR__, 2);
        $code = implode("\n", [
            file_get_contents($root . '/app/Services/PlatformPayment/PlatformPaymentSettings.php'),
            file_get_contents($root . '/app/Services/PlatformPayment/PlatformPaymentSessionService.php'),
            file_get_contents($root . '/app/Services/PlatformPayment/PlatformPaypalGateway.php'),
            file_get_contents($root . '/app/Services/PlatformPayment/PlatformStripeGateway.php'),
            file_get_contents($root . '/plugins/LongosPlatformPay/columns.php'),
        ]);

        self::assertStringNotContainsString('Plugin\\Paypal', $code);
        self::assertStringNotContainsString('Plugin\\Stripe', $code);
        self::assertStringNotContainsString("plugin_setting('paypal", $code);
        self::assertStringNotContainsString("plugin_setting('stripe", $code);
        self::assertStringContainsString('PlatformPaymentCredentials::all()', $code);
        self::assertStringContainsString("'name'        => 'paypal_client_id'", $code);
        self::assertStringContainsString("'name'        => 'stripe_secret_key'", $code);
        self::assertStringContainsString(':value="\'\'"', (string) file_get_contents(
            $root . '/resources/beike/admin/views/pages/plugins/form.blade.php'
        ));
    }

    public function test_platform_secrets_use_the_plugins_own_encrypted_storage_contract(): void
    {
        $previous  = Facade::getFacadeApplication();
        $container = new Container;
        $container->instance('encrypter', new Encrypter(random_bytes(32), 'AES-256-CBC'));
        Facade::clearResolvedInstance('encrypter');
        Facade::setFacadeApplication($container);

        try {
            $stored = PlatformPaymentCredentials::normalizeForStorage([
                'paypal_client_id'       => 'public-client-id',
                'paypal_secret'          => 'paypal-private-secret',
                'stripe_publishable_key' => 'pk_test_public',
                'stripe_secret_key'      => 'sk_test_private_secret',
                'stripe_webhook_secret'  => 'whsec_private_secret',
            ]);

            self::assertSame('public-client-id', $stored['paypal_client_id']);
            self::assertSame('pk_test_public', $stored['stripe_publishable_key']);
            self::assertStringStartsWith(PlatformPaymentCredentials::ENCRYPTED_PREFIX, $stored['paypal_secret']);
            self::assertStringStartsWith(PlatformPaymentCredentials::ENCRYPTED_PREFIX, $stored['stripe_secret_key']);
            self::assertStringStartsWith(PlatformPaymentCredentials::ENCRYPTED_PREFIX, $stored['stripe_webhook_secret']);

            $runtime = PlatformPaymentCredentials::decryptForRuntime($stored);
            self::assertSame('paypal-private-secret', $runtime['paypal_secret']);
            self::assertSame('sk_test_private_secret', $runtime['stripe_secret_key']);
            self::assertSame('whsec_private_secret', $runtime['stripe_webhook_secret']);
            self::assertSame('', PlatformPaymentCredentials::runtimeValue('paypal_secret', 'enc:v1:not-valid-ciphertext'));
        } finally {
            Facade::clearResolvedInstance('encrypter');
            Facade::setFacadeApplication($previous);
        }
    }

    public function test_platform_settings_panel_validates_its_own_provider_credentials(): void
    {
        $valid = $this->validateColumns([
            'status'             => '1',
            'share_paypal'       => '1',
            'share_stripe'       => '0',
            'paypal_mode'        => 'sandbox',
            'paypal_client_id'   => 'paypal_client_1234567890',
            'paypal_secret'      => 'paypal-secret-value',
            'paypal_merchant_id' => '',
            'stripe_test_mode'   => '1',
        ]);
        self::assertTrue($valid->isEmpty(), $valid->first());

        $missingSecret = $this->validateColumns([
            'status'           => '1',
            'share_paypal'     => '1',
            'share_stripe'     => '0',
            'paypal_mode'      => 'sandbox',
            'paypal_client_id' => 'paypal_client_1234567890',
            'paypal_secret'    => '',
            'stripe_test_mode' => '1',
        ]);
        self::assertTrue($missingSecret->has('paypal_secret'));

        $stripe = $this->validateColumns([
            'status'                 => '1',
            'share_paypal'           => '0',
            'share_stripe'           => '1',
            'paypal_mode'            => 'sandbox',
            'stripe_test_mode'       => '1',
            'stripe_publishable_key' => 'pk_test_1234567890abcdef',
            'stripe_secret_key'      => 'sk_test_1234567890abcdef',
            'stripe_webhook_secret'  => 'whsec_1234567890abcdef',
        ]);
        self::assertTrue($stripe->isEmpty(), $stripe->first());
    }

    public function test_platform_currency_and_paypal_payload_are_owned_and_minimal(): void
    {
        $order = (object) ['currency_code' => 'EUR', 'currency_value' => 0.91, 'total' => 19.99];
        self::assertSame('EUR', PlatformPaymentCurrency::paymentCurrency($order));
        self::assertSame(18.19, PlatformPaymentCurrency::paymentAmount($order));

        $safe = PlatformPaymentPayload::paypal([
            'id'             => 'PAYPALORDER123',
            'status'         => 'COMPLETED',
            'payer'          => ['email_address' => 'private@example.test'],
            'purchase_units' => [[
                'custom_id' => 'session-uuid',
                'payments'  => ['captures' => [[
                    'id'     => 'CAPTURE123',
                    'status' => 'COMPLETED',
                    'amount' => ['currency_code' => 'USD', 'value' => '19.99'],
                ]]],
            ]],
        ]);

        self::assertSame('PAYPALORDER123', $safe['id']);
        self::assertSame('CAPTURE123', data_get($safe, 'purchase_units.0.payments.captures.0.id'));
        self::assertArrayNotHasKey('payer', $safe);
        self::assertStringNotContainsString('private@example.test', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $payload */
    private function validateColumns(array $payload): \Illuminate\Support\MessageBag
    {
        $previous  = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new Repository([
            'multitenancy' => ['current_tenant_container_key' => 'currentTenant'],
        ]));
        $container->instance('currentTenant', (new Site)->forceFill(['meta' => ['control_site' => true]]));
        $container->instance('request', Request::create('/platform-pay/settings', 'POST', $payload));
        $translator = new Translator(new ArrayLoader, 'en');
        $container->instance('translator', $translator);
        $settings = $this->createMock(PlatformPaymentSettings::class);
        $settings->method('mainUrl')->willReturn('https://main.example/_longos/pay/webhooks/stripe');
        $container->instance(PlatformPaymentSettings::class, $settings);

        try {
            $columns = require dirname(__DIR__, 2) . '/plugins/LongosPlatformPay/columns.php';
            $rules   = array_column($columns, 'rules', 'name');
            $result  = (new Factory($translator, $container))->make($payload, $rules);
            $result->fails();

            return $result->errors();
        } finally {
            Container::setInstance($previous);
        }
    }
}
