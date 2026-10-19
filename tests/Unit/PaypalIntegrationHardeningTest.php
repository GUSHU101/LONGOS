<?php

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;
use Plugin\Paypal\Services\PaypalNvpClient;

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
        self::assertStringContainsString("'transaction_id' => (string) \$paypalOrder['id']", $controller);
        self::assertStringContainsString("'merchant-id' => \$paypalMerchantId", $checkout);
        self::assertStringContainsString('PaypalCurrency::paymentCurrency', $service);
        self::assertStringContainsString('PaypalCurrency::paymentCurrency', $checkout);
        self::assertStringContainsString("'connect_timeout' => 10", $service);
        self::assertStringContainsString('verifyNvpNotification(', $service);
        self::assertStringContainsString('validateNvpNotification(', $service);
        self::assertStringContainsString("Cache::lock('paypal:payment:'", $controller);
        self::assertStringNotContainsString("public function nvpNotify(Request \$request)\n    {\n        return response('OK');", $controller);
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
