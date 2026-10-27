<?php

namespace Tests\Unit;

use App\Saas\Models\Site;
use App\Saas\TenantManager;
use App\Services\PlatformPayment\PlatformPaymentCurrency;
use App\Services\PlatformPayment\PlatformPaymentHealthService;
use App\Services\PlatformPayment\PlatformPaymentPayload;
use App\Services\PlatformPayment\PlatformPaymentSettings;
use App\Services\PlatformPayment\PlatformPaypalGateway;
use Beike\Admin\Http\Controllers\PluginController;
use Beike\Plugin\Plugin;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Plugin\LongosPlatformPay\Services\PlatformPaymentCredentials;
use Srmklive\PayPal\Services\PayPal;

class PlatformPaymentIndependenceTest extends TestCase
{
    public function test_provider_health_check_verifies_every_shared_account_without_exposing_credentials(): void
    {
        $previous  = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $container->instance('translator', new Translator(new ArrayLoader, 'en'));

        $settings = $this->createMock(PlatformPaymentSettings::class);
        $settings->method('snapshot')->willReturn([
            'share_paypal' => true,
            'share_stripe' => true,
        ]);
        $settings->method('configuredProviders')->willReturn(['paypal', 'stripe']);

        $probed = [];
        $paypal = static function (array $snapshot) use (&$probed): void {
            $probed[] = ['paypal', $snapshot];
        };
        $stripe = static function (array $snapshot) use (&$probed): void {
            $probed[] = ['stripe', $snapshot];
        };

        try {
            $result = (new PlatformPaymentHealthService($settings, $paypal, $stripe))->check();

            self::assertTrue($result['healthy']);
            self::assertSame('ready', $result['state']);
            self::assertSame(['paypal', 'stripe'], array_keys($result['providers']));
            self::assertSame(['ready', 'ready'], array_column($result['providers'], 'state'));
            self::assertSame(['paypal', 'stripe'], array_column($probed, 0));
            self::assertSame($probed[0][1], $probed[1][1]);
            self::assertStringNotContainsString('secret', json_encode($result, JSON_THROW_ON_ERROR));
        } finally {
            Container::setInstance($previous);
        }
    }

    public function test_provider_health_check_uses_one_transient_form_snapshot(): void
    {
        $previous  = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $container->instance('translator', new Translator(new ArrayLoader, 'en'));

        $overrides = [
            'share_paypal'     => '1',
            'share_stripe'     => '0',
            'paypal_client_id' => 'new-client-id',
            'paypal_secret'    => 'new-secret',
        ];
        $snapshot = [
            'share_paypal' => true,
            'share_stripe' => false,
            'paypal'       => ['client_id' => 'new-client-id', 'secret' => 'new-secret'],
        ];
        $settings = $this->createMock(PlatformPaymentSettings::class);
        $settings->expects(self::never())->method('snapshot');
        $settings->expects(self::once())->method('snapshotWithOverrides')->with($overrides)->willReturn($snapshot);
        $settings->expects(self::once())->method('configuredProviders')->with($snapshot)->willReturn(['paypal']);

        $probed = null;
        $paypal = static function (array $candidate) use (&$probed): void {
            $probed = $candidate;
        };
        $stripe = static function (): void {
            self::fail('A disabled provider must not be probed.');
        };

        try {
            $result = (new PlatformPaymentHealthService($settings, $paypal, $stripe))->check($overrides);

            self::assertTrue($result['healthy']);
            self::assertSame($snapshot, $probed);
            self::assertSame(['paypal'], array_keys($result['providers']));
        } finally {
            Container::setInstance($previous);
        }
    }

    public function test_transient_snapshot_preserves_blank_password_controls(): void
    {
        $settings = new PlatformPaymentSettings($this->createMock(TenantManager::class));
        $base     = [
            'enabled'      => true,
            'share_paypal' => true,
            'share_stripe' => true,
            'paypal'       => [
                'api_mode' => 'rest', 'mode' => 'sandbox', 'client_id' => 'saved-client',
                'secret'   => 'saved-paypal-secret', 'merchant_id' => 'SAVEDMERCHANT',
            ],
            'stripe' => [
                'test_mode'  => true, 'publishable_key' => 'pk_test_saved',
                'secret_key' => 'sk_test_saved-secret', 'webhook_secret' => 'whsec_saved-secret',
            ],
        ];

        $snapshot = $settings->snapshotWithOverrides([
            'paypal_client_id'       => 'new-client',
            'paypal_secret'          => '',
            'paypal_merchant_id'     => '',
            'stripe_publishable_key' => 'pk_test_new',
            'stripe_secret_key'      => '',
            'stripe_webhook_secret'  => '',
        ], $base);

        self::assertSame('new-client', $snapshot['paypal']['client_id']);
        self::assertSame('saved-paypal-secret', $snapshot['paypal']['secret']);
        self::assertSame('', $snapshot['paypal']['merchant_id']);
        self::assertSame('pk_test_new', $snapshot['stripe']['publishable_key']);
        self::assertSame('sk_test_saved-secret', $snapshot['stripe']['secret_key']);
        self::assertSame('whsec_saved-secret', $snapshot['stripe']['webhook_secret']);
    }

    public function test_platform_settings_surface_saved_runtime_readiness_and_validation_failures(): void
    {
        $root       = dirname(__DIR__, 2);
        $controller = (string) file_get_contents($root . '/beike/Admin/Http/Controllers/PluginController.php');
        $form       = (string) file_get_contents($root . '/resources/beike/admin/views/pages/plugins/form.blade.php');
        $columns    = (string) file_get_contents($root . '/plugins/LongosPlatformPay/columns.php');
        $english    = require $root . '/plugins/LongosPlatformPay/Lang/en/common.php';
        $chinese    = require $root . '/plugins/LongosPlatformPay/Lang/zh_cn/common.php';

        self::assertStringContainsString('platformPaymentRuntimeStatus($plugin)', $controller);
        self::assertStringContainsString('availableProvidersForSnapshot($snapshot)', $controller);
        self::assertStringContainsString("'platformPaymentRuntimeStatus'", $controller);
        self::assertStringContainsString('platformPaymentRuntimeStatus', $form);
        self::assertStringContainsString('$errors->any()', $form);
        self::assertStringContainsString('data-plugin-validation-summary', $form);
        self::assertStringContainsString("payload?.status !== 'success'", $form);
        self::assertStringContainsString('data-plugin-action-form', $form);
        self::assertStringContainsString('new FormData(form)', $form);
        self::assertStringContainsString("key !== '_method'", $form);
        self::assertStringContainsString("'action_route'     => 'plugins.platform_payment_health'", $columns);
        self::assertStringContainsString("'action_form'      => true", $columns);
        self::assertStringNotContainsString("admin_route('plugins.platform_payment_health')", $columns);
        self::assertStringContainsString("['info', 'success', 'warning', 'danger']", $form);
        self::assertStringContainsString("__('admin/plugin.enable_status')", $form);
        self::assertStringNotContainsString("? '启用状态' : '基础设置'", $form);
        self::assertArrayHasKey('runtime_ready', $english['admin']);
        self::assertArrayHasKey('runtime_child_disabled', $english['admin']);
        self::assertArrayHasKey('runtime_unavailable', $chinese['admin']);
        self::assertArrayHasKey('health_check_action', $english['admin']);
        self::assertArrayHasKey('health_stripe_failed', $chinese['admin']);
    }

    public function test_platform_runtime_status_distinguishes_ready_and_locally_disabled_child_sites(): void
    {
        $previous  = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new Repository([
            'multitenancy' => ['current_tenant_container_key' => 'currentTenant'],
        ]));
        $container->instance('currentTenant', (new Site)->forceFill(['meta' => ['control_site' => false]]));
        $container->instance('translator', new Translator(new ArrayLoader, 'en'));
        $settings = $this->createMock(PlatformPaymentSettings::class);
        $settings->method('snapshot')->willReturn(['enabled' => true]);
        $settings->method('availableProvidersForSnapshot')->willReturn(['paypal']);
        $container->instance(PlatformPaymentSettings::class, $settings);

        try {
            $controller = (new \ReflectionClass(PluginController::class))->newInstanceWithoutConstructor();
            $method     = new \ReflectionMethod($controller, 'platformPaymentRuntimeStatus');
            $plugin     = (new Plugin('', []))->setEnabled(true);

            self::assertSame('success', $method->invoke($controller, $plugin)['tone']);

            $plugin->setEnabled(false);
            self::assertSame('danger', $method->invoke($controller, $plugin)['tone']);
        } finally {
            Container::setInstance($previous);
        }
    }

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
        self::assertStringContainsString('PlatformPaymentCredentials::all($connection)', $code);
        $credentials = (string) file_get_contents(
            $root . '/plugins/LongosPlatformPay/Services/PlatformPaymentCredentials.php'
        );
        self::assertStringContainsString('Setting::on($connection)', $credentials);
        self::assertStringNotContainsString('plugin_setting(', $credentials);
        self::assertStringContainsString("'name'            => 'paypal_client_id'", $code);
        self::assertStringContainsString("'name'            => 'stripe_secret_key'", $code);
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

    public function test_child_site_cannot_persist_forged_platform_credentials(): void
    {
        $controller = (new \ReflectionClass(PluginController::class))->newInstanceWithoutConstructor();
        $method     = new \ReflectionMethod($controller, 'platformPaymentFields');
        $payload    = [
            'status'            => '1',
            'paypal_secret'     => 'must-not-be-stored',
            'stripe_secret_key' => 'must-not-be-stored',
            'unexpected'        => 'must-not-be-stored',
        ];

        self::assertSame(['status' => '1'], $method->invoke($controller, $payload, false));
        self::assertSame([
            'status'            => '1',
            'paypal_secret'     => 'must-not-be-stored',
            'stripe_secret_key' => 'must-not-be-stored',
        ], $method->invoke($controller, $payload, true));
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

    public function test_paypal_retry_ids_are_compact_stable_and_operation_specific(): void
    {
        $gateway = (new \ReflectionClass(PlatformPaypalGateway::class))->newInstanceWithoutConstructor();
        $method  = new \ReflectionMethod($gateway, 'requestId');
        $session = (new \App\Saas\Models\PlatformPaymentSession)->forceFill([
            'uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
        ]);

        $create  = $method->invoke($gateway, 'create', $session);
        $capture = $method->invoke($gateway, 'capture', $session);

        self::assertMatchesRegularExpression('/^lp-[a-f0-9]{32}$/', $create);
        self::assertSame($create, $method->invoke($gateway, 'create', $session));
        self::assertNotSame($create, $capture);
    }

    #[IgnoreDeprecations]
    public function test_paypal_reconciliation_captures_an_approved_interrupted_order(): void
    {
        $orderId = 'ORDER1234567890';
        $session = (new \App\Saas\Models\PlatformPaymentSession)->forceFill([
            'uuid'     => '12121212-1212-4212-8212-121212121212',
            'amount'   => '19.99000000',
            'currency' => 'USD',
        ]);
        $unit = [
            'reference_id' => $session->uuid,
            'custom_id'    => $session->uuid,
            'invoice_id'   => $session->uuid,
            'amount'       => ['currency_code' => 'USD', 'value' => '19.99'],
        ];
        $client = $this->createMock(PayPal::class);
        $client->expects(self::once())->method('showOrderDetails')->with($orderId)->willReturn([
            'id' => $orderId, 'status' => 'APPROVED', 'purchase_units' => [$unit],
        ]);
        $client->expects(self::once())->method('capturePaymentOrder')->with($orderId)->willReturn([
            'id' => $orderId, 'status' => 'COMPLETED', 'purchase_units' => [[
                ...$unit,
                'payments' => ['captures' => [[
                    'id'     => 'CAPTURE12345678', 'status' => 'COMPLETED',
                    'amount' => ['currency_code' => 'USD', 'value' => '19.99'],
                ]]],
            ]],
        ]);

        $gateway = (new \ReflectionClass(PlatformPaypalGateway::class))->newInstanceWithoutConstructor();
        foreach (['client' => $client, 'settings' => ['merchant_id' => '']] as $property => $value) {
            (new \ReflectionProperty($gateway, $property))->setValue($gateway, $value);
        }

        $result = $gateway->recoverCompleted($session, $orderId);
        self::assertSame('CAPTURE12345678', $result['transaction_id'] ?? null);
        self::assertSame('COMPLETED', data_get($result, 'payload.status'));
    }

    #[IgnoreDeprecations]
    public function test_paypal_capture_recovers_when_the_success_response_is_lost(): void
    {
        $orderId = 'ORDER9876543210';
        $session = (new \App\Saas\Models\PlatformPaymentSession)->forceFill([
            'uuid'     => '13131313-1313-4313-8313-131313131313',
            'amount'   => '29.99000000',
            'currency' => 'USD',
        ]);
        $unit = [
            'reference_id' => $session->uuid,
            'custom_id'    => $session->uuid,
            'invoice_id'   => $session->uuid,
            'amount'       => ['currency_code' => 'USD', 'value' => '29.99'],
            'payments'     => ['captures' => [[
                'id'     => 'CAPTURE98765432', 'status' => 'COMPLETED',
                'amount' => ['currency_code' => 'USD', 'value' => '29.99'],
            ]]],
        ];
        $client = $this->createMock(PayPal::class);
        $client->expects(self::once())->method('capturePaymentOrder')->with($orderId)
            ->willThrowException(new \RuntimeException('provider response lost'));
        $client->expects(self::once())->method('showOrderDetails')->with($orderId)->willReturn([
            'id' => $orderId, 'status' => 'COMPLETED', 'purchase_units' => [$unit],
        ]);

        $gateway = (new \ReflectionClass(PlatformPaypalGateway::class))->newInstanceWithoutConstructor();
        foreach (['client' => $client, 'settings' => ['merchant_id' => '']] as $property => $value) {
            (new \ReflectionProperty($gateway, $property))->setValue($gateway, $value);
        }

        $result = $gateway->capture($session, $orderId);
        self::assertSame('CAPTURE98765432', $result['transaction_id']);
        self::assertSame('COMPLETED', data_get($result, 'payload.status'));
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
