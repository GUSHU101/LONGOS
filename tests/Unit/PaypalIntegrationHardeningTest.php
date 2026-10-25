<?php

namespace Tests\Unit;

use Beike\Plugin\Plugin;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Container\Container;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;
use Plugin\Paypal\Services\PaypalNvpClient;
use Plugin\Paypal\Services\PaypalService;
use Plugin\Paypal\Services\PaypalSettings;

class PaypalIntegrationHardeningTest extends TestCase
{
    public function test_only_active_rest_environment_credentials_are_required(): void
    {
        $sandbox = $this->validateSettings([
            'api_mode'     => 'rest',
            'sandbox_mode' => '1',
            'status'       => '1',
        ]);
        self::assertTrue($sandbox->errors()->has('sandbox_client_id'));
        self::assertTrue($sandbox->errors()->has('sandbox_secret'));
        self::assertFalse($sandbox->errors()->has('live_client_id'));
        self::assertFalse($sandbox->errors()->has('live_secret'));

        $live = $this->validateSettings([
            'api_mode'     => 'rest',
            'sandbox_mode' => '0',
            'status'       => '1',
        ]);
        self::assertTrue($live->errors()->has('live_client_id'));
        self::assertTrue($live->errors()->has('live_secret'));
        self::assertFalse($live->errors()->has('sandbox_client_id'));
        self::assertFalse($live->errors()->has('sandbox_secret'));
    }

    public function test_inactive_paypal_can_be_saved_as_a_draft(): void
    {
        $validator = $this->validateSettings([
            'api_mode'     => 'rest',
            'sandbox_mode' => '1',
            'status'       => '0',
        ]);

        self::assertFalse($validator->fails());
    }

    public function test_merchant_id_uses_paypal_format(): void
    {
        $valid = $this->validateSettings([
            'api_mode'            => 'rest',
            'sandbox_mode'        => '1',
            'status'              => '0',
            'sandbox_merchant_id' => '2ABCDEFGHJKLM',
        ]);
        self::assertFalse($valid->errors()->has('sandbox_merchant_id'));

        $invalid = $this->validateSettings([
            'api_mode'            => 'rest',
            'sandbox_mode'        => '1',
            'status'              => '0',
            'sandbox_merchant_id' => 'merchant@example.com',
        ]);
        self::assertTrue($invalid->errors()->has('sandbox_merchant_id'));
    }

    public function test_payment_flow_keeps_payee_and_local_order_guards(): void
    {
        $root       = dirname(__DIR__, 2);
        $service    = (string) file_get_contents($root . '/plugins/Paypal/Services/PaypalService.php');
        $controller = (string) file_get_contents($root . '/plugins/Paypal/Controllers/PaypalController.php');
        $checkout   = (string) file_get_contents($root . '/plugins/Paypal/Views/checkout/payment.blade.php');

        self::assertStringContainsString("\$unit['payee'] = ['merchant_id' => \$merchantId]", $service);
        self::assertStringContainsString('validatePayee(', $service);
        self::assertStringContainsString("'PayPal-Request-Id'", $service);
        self::assertStringContainsString('assertProviderOrderBinding(', $controller);
        self::assertStringContainsString('tenant_operational_route(', $controller);
        self::assertStringContainsString('PaypalService::safeProviderResult(', $controller);
        self::assertStringContainsString("'transaction_id' => (string) \$safeResult['id']", $controller);
        self::assertStringContainsString("'merchant-id' => \$paypalMerchantId", $checkout);
        self::assertStringContainsString('PaypalCurrency::paymentCurrency', $service);
        self::assertStringContainsString('PaypalCurrency::paymentCurrency', $checkout);
        self::assertStringContainsString("'connect_timeout' => 10", $service);
        self::assertStringContainsString('authenticateRestClient(', $service);
        self::assertStringContainsString('Crypt::encryptString(', $service);
        self::assertStringContainsString('verifyNvpNotification(', $service);
        self::assertStringContainsString('validateNvpNotification(', $service);
        self::assertStringContainsString("Cache::lock('paypal:payment:'", $controller);
        self::assertStringNotContainsString("public function nvpNotify(Request \$request)\n    {\n        return response('OK');", $controller);
    }

    public function test_paypal_secrets_are_encrypted_and_legacy_plaintext_remains_readable(): void
    {
        $previous  = Facade::getFacadeApplication();
        $container = new Container;
        $container->instance('encrypter', new Encrypter(random_bytes(32), 'AES-256-CBC'));
        Facade::clearResolvedInstance('encrypter');
        Facade::setFacadeApplication($container);

        try {
            $stored = PaypalSettings::normalizeForStorage([
                'sandbox_client_id'     => 'public-client-id',
                'sandbox_secret'        => 'rest-secret',
                'sandbox_api_password'  => 'nvp-password',
                'sandbox_api_signature' => 'nvp-signature',
                'password'              => 'legacy-generic-password',
            ]);

            self::assertSame('public-client-id', $stored['sandbox_client_id']);
            foreach (['sandbox_secret', 'sandbox_api_password', 'sandbox_api_signature', 'password'] as $field) {
                self::assertStringStartsWith(PaypalSettings::ENCRYPTED_PREFIX, $stored[$field]);
                self::assertNotSame([
                    'sandbox_secret'        => 'rest-secret',
                    'sandbox_api_password'  => 'nvp-password',
                    'sandbox_api_signature' => 'nvp-signature',
                    'password'              => 'legacy-generic-password',
                ][$field], $stored[$field]);
            }

            self::assertSame('rest-secret', PaypalSettings::decryptForRuntime($stored)['sandbox_secret']);
            self::assertSame('legacy-plaintext', PaypalSettings::decryptForRuntime(['live_secret' => 'legacy-plaintext'])['live_secret']);
            self::assertTrue(PaypalSettings::hasStoredSecret($stored, 'sandbox_secret'));
        } finally {
            Facade::clearResolvedInstance('encrypter');
            Facade::setFacadeApplication($previous);
        }
    }

    public function test_legacy_settings_are_canonicalized_without_crossing_environments(): void
    {
        $legacy = [
            'api_standard'  => 'soap',
            'sandbox_mode'  => '1',
            'client_id'     => 'legacy-client',
            'secret'        => 'legacy-rest-secret',
            'api_username'  => 'legacy-user',
            'api_password'  => 'legacy-password',
            'api_signature' => 'legacy-signature',
        ];

        $settings = PaypalSettings::canonicalizeLegacySettings($legacy);

        self::assertSame('nvp', $settings['api_mode']);
        self::assertSame('legacy-client', $settings['sandbox_client_id']);
        self::assertSame('legacy-rest-secret', $settings['sandbox_secret']);
        self::assertSame('legacy-user', $settings['sandbox_api_username']);
        self::assertSame('legacy-password', $settings['sandbox_api_password']);
        self::assertSame('legacy-signature', $settings['sandbox_api_signature']);
        self::assertArrayNotHasKey('live_client_id', $settings);
        self::assertArrayNotHasKey('live_secret', $settings);
        self::assertArrayNotHasKey('live_api_username', $settings);
    }

    public function test_blank_secret_submission_preserves_legacy_aliases_before_validation(): void
    {
        $fields = PaypalSettings::preserveBlankSecrets([
            'sandbox_secret'        => '',
            'sandbox_api_password'  => '',
            'sandbox_api_signature' => '',
            'live_secret'           => '',
        ], [
            'sandbox_mode'  => '1',
            'secret'        => 'legacy-rest-secret',
            'api_password'  => 'legacy-password',
            'api_signature' => 'legacy-signature',
        ]);

        self::assertSame('legacy-rest-secret', $fields['sandbox_secret']);
        self::assertSame('legacy-password', $fields['sandbox_api_password']);
        self::assertSame('legacy-signature', $fields['sandbox_api_signature']);
        self::assertSame('', $fields['live_secret']);
    }

    public function test_provider_results_are_reduced_to_reconciliation_fields(): void
    {
        $rest = PaypalService::safeProviderResult([
            'id'             => '5O190127TN364715T',
            'status'         => 'COMPLETED',
            'payer'          => ['email_address' => 'buyer@example.com', 'name' => ['given_name' => 'Buyer']],
            'purchase_units' => [[
                'custom_id'  => 'ORDER-100',
                'invoice_id' => 'ORDER-100',
                'shipping'   => ['address' => ['address_line_1' => 'Private street']],
                'payee'      => ['merchant_id' => '2ABCDEFGHJKLM', 'email_address' => 'merchant@example.com'],
                'payments'   => ['captures' => [[
                    'id'                => '3C679366HH908993F',
                    'status'            => 'COMPLETED',
                    'amount'            => ['currency_code' => 'USD', 'value' => '19.99'],
                    'seller_protection' => ['status' => 'ELIGIBLE'],
                ]]],
            ]],
        ], false);
        $encodedRest = json_encode($rest, JSON_THROW_ON_ERROR);

        self::assertSame('5O190127TN364715T', $rest['id']);
        self::assertSame('3C679366HH908993F', $rest['purchase_units'][0]['payments']['captures'][0]['id']);
        self::assertStringNotContainsString('buyer@example.com', $encodedRest);
        self::assertStringNotContainsString('merchant@example.com', $encodedRest);
        self::assertStringNotContainsString('Private street', $encodedRest);
        self::assertStringNotContainsString('seller_protection', $encodedRest);

        $nvp = PaypalService::safeProviderResult([
            'id'       => '8AB12345CD678901E',
            'token'    => 'EC-ABCDEFGHIJKLM',
            'status'   => 'COMPLETED',
            'payer_id' => 'PAYERPRIVATE',
            'details'  => [
                'EMAIL'                         => 'buyer@example.com',
                'PAYMENTREQUEST_0_AMT'          => '19.99',
                'PAYMENTREQUEST_0_CURRENCYCODE' => 'USD',
                'PAYMENTREQUEST_0_INVNUM'       => 'ORDER-100',
            ],
            'response' => ['PAYMENTINFO_0_PAYMENTSTATUS' => 'Completed', 'FIRSTNAME' => 'Buyer'],
        ], true);
        $encodedNvp = json_encode($nvp, JSON_THROW_ON_ERROR);

        self::assertSame('8AB12345CD678901E', $nvp['id']);
        self::assertSame('ORDER-100', $nvp['order_number']);
        self::assertStringNotContainsString('buyer@example.com', $encodedNvp);
        self::assertStringNotContainsString('PAYERPRIVATE', $encodedNvp);
        self::assertStringNotContainsString('FIRSTNAME', $encodedNvp);
    }

    public function test_settings_panel_uses_progressive_disclosure_and_never_echoes_secrets(): void
    {
        $root     = dirname(__DIR__, 2);
        $view     = (string) file_get_contents($root . '/plugins/Paypal/Views/admin/config_form.blade.php');
        $columns  = (string) file_get_contents($root . '/plugins/Paypal/columns.php');
        $checkout = (string) file_get_contents($root . '/plugins/Paypal/Views/checkout/payment.blade.php');

        self::assertStringContainsString('<details class="paypal-advanced"', $view);
        self::assertStringContainsString('data-paypal-environment-panel=', $view);
        self::assertStringContainsString('data-paypal-mode-panel=', $view);
        self::assertStringContainsString('aria-live="polite"', $view);
        self::assertStringContainsString('value=""', $view);
        self::assertStringNotContainsString("old(\$environment . '_secret'", $view);
        self::assertStringNotContainsString("'label' => '关闭'", $columns);
        self::assertStringContainsString('Paypal::checkout.connecting', $checkout);
        self::assertStringNotContainsString("status.textContent = 'Connecting to PayPal", $checkout);
        self::assertFileExists($root . '/plugins/Paypal/Lang/en/checkout.php');
        self::assertFileExists($root . '/plugins/Paypal/Lang/zh_cn/checkout.php');
        self::assertFileExists($root . '/plugins/Paypal/Views/admin/config.blade.php');
        self::assertStringContainsString("@include('Paypal::admin.config_form')", (string) file_get_contents(
            $root . '/plugins/Paypal/Views/admin/config.blade.php'
        ));

        $plugin = (new Plugin($root . '/plugins/Paypal', ['code' => 'paypal']))->setDirname('Paypal');
        self::assertSame('Paypal::admin.config', $plugin->getColumnView());
    }

    public function test_ipn_race_preserves_the_bound_checkout_token(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/Paypal/Controllers/PaypalController.php');

        self::assertStringContainsString('getBoundNvpToken(', $controller);
        self::assertStringContainsString("\$result['token'] = \$boundToken", $controller);
        self::assertStringNotContainsString("'PayerID', 'payerId', 'payer_id'", $controller);
    }

    public function test_ipn_verification_replays_the_exact_original_body(): void
    {
        $history = [];
        $stack   = HandlerStack::create(new MockHandler([new Response(200, [], "VERIFIED\n")]));
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack]);
        $nvp    = new PaypalNvpClient(['mode' => 'sandbox'], $client);
        $body   = 'payment_status=Completed&custom=ORDER%2B100&mc_gross=19.99';

        self::assertTrue($nvp->verifyIpn($body));
        self::assertCount(1, $history);
        self::assertSame('cmd=_notify-validate&' . $body, (string) $history[0]['request']->getBody());
        self::assertSame('ipnpb.sandbox.paypal.com', $history[0]['request']->getUri()->getHost());
    }

    private function validateSettings(array $payload): \Illuminate\Contracts\Validation\Validator
    {
        $previous  = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $container->instance('request', Request::create('/paypal/settings', 'POST', $payload));

        try {
            $columns    = require dirname(__DIR__, 2) . '/plugins/Paypal/columns.php';
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
