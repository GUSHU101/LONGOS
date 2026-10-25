<?php

namespace Plugin\LongosPlatformPay\Controllers;

use App\Http\Controllers\PlatformPaymentController;
use App\Saas\Models\PlatformPaymentSession;
use App\Services\PlatformPayment\PlatformPaymentAmount;
use App\Services\PlatformPayment\PlatformPaymentCurrency;
use App\Services\PlatformPayment\PlatformPaymentSessionService;
use App\Services\PlatformPayment\PlatformPaymentSettings;
use Beike\Repositories\OrderRepo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckoutController
{
    public function store(
        Request $request,
        PlatformPaymentSessionService $sessions,
        PlatformPaymentSettings $settings,
    ): JsonResponse {
        try {
            $data     = $request->validate(['order_number' => ['required', 'string', 'max:128']]);
            $customer = current_customer();
            $order    = OrderRepo::getOrderByNumber($data['order_number'], $customer);
            if (! $order) {
                throw new \RuntimeException('订单不存在。');
            }
            if (! $customer) {
                $guestOrders = array_map('strval', session('guest_order_numbers', []));
                if (! in_array((string) $order->number, $guestOrders, true)) {
                    throw new \RuntimeException('当前访客会话无权访问该订单。');
                }
            }
            $site = current_site();
            if (! $site) {
                throw new \RuntimeException('当前站点尚未解析。');
            }

            $created   = $sessions->create($site, $order);
            $session   = $created['session'];
            $snapshot  = $settings->snapshot();
            $providers = $settings->availableProvidersForSnapshot($snapshot);
            if ($session->provider) {
                $configured = $settings->configuredProviders($snapshot);
                $providers  = in_array($session->provider, $configured, true) ? [$session->provider] : [];
            }
            if ($providers === []) {
                throw new \RuntimeException('当前没有可用的平台支付渠道。');
            }

            $token    = $created['token'];
            $decimals = PlatformPaymentCurrency::decimalPlaces((string) $session->currency);
            $response = json_success('安全支付会话已创建。', [
                'token'        => $token,
                'checkout_url' => $created['checkout_url'],
                'return_url'   => $settings->mainUrl("_longos/pay/{$token}/return/stripe"),
                'providers'    => $providers,
                'amount'       => [
                    'currency' => (string) $session->currency,
                    'value'    => PlatformPaymentAmount::format((string) $session->amount, $decimals),
                ],
                'paypal' => in_array('paypal', $providers, true) ? [
                    'client_id'   => (string) data_get($snapshot, 'paypal.client_id', ''),
                    'merchant_id' => (string) data_get($snapshot, 'paypal.merchant_id', ''),
                ] : null,
                'stripe' => in_array('stripe', $providers, true) ? [
                    'publishable_key' => (string) data_get($snapshot, 'stripe.publishable_key', ''),
                ] : null,
                'endpoints' => [
                    // Browser orchestration must stay on the exact host that
                    // rendered checkout. Absolute URLs can become cross-origin
                    // when a tenant is visited through a retained alias.
                    'status'          => route('shop.longos_platform_pay.status', ['token' => $token], false),
                    'paypal_create'   => route('shop.longos_platform_pay.paypal.create', ['token' => $token], false),
                    'paypal_capture'  => route('shop.longos_platform_pay.paypal.capture', ['token' => $token], false),
                    'stripe_intent'   => route('shop.longos_platform_pay.stripe.intent', ['token' => $token], false),
                    'stripe_complete' => route('shop.longos_platform_pay.stripe.complete', ['token' => $token], false),
                ],
            ]);
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        } catch (Throwable $e) {
            Log::warning('Platform payment session creation failed.', ['exception' => $e::class]);

            return json_fail('暂时无法连接主站支付，请稍后重试。', [], 422);
        }
    }

    public function paypalCreate(
        string $token,
        PlatformPaymentSessionService $sessions,
        PlatformPaymentController $payments,
    ): JsonResponse {
        $this->assertChildSession($token, $sessions);

        return $payments->paypalCreate($token);
    }

    public function status(
        string $token,
        PlatformPaymentSessionService $sessions,
    ): JsonResponse {
        $session   = $this->assertChildSession($token, $sessions);
        $completed = $session->status === PlatformPaymentSession::STATUS_COMPLETED;

        return json_success('支付状态已核验。', [
            'completed'    => $completed,
            'state'        => (string) $session->status,
            'redirect_url' => $completed ? $sessions->tenantSuccessUrl($session) : null,
        ]);
    }

    public function paypalCapture(
        Request $request,
        string $token,
        PlatformPaymentSessionService $sessions,
        PlatformPaymentController $payments,
    ): JsonResponse {
        $this->assertChildSession($token, $sessions);

        return $payments->paypalCapture($request, $token);
    }

    public function stripeIntent(
        string $token,
        PlatformPaymentSessionService $sessions,
        PlatformPaymentController $payments,
    ): JsonResponse {
        $this->assertChildSession($token, $sessions);

        return $payments->stripeIntent($token);
    }

    public function stripeComplete(
        Request $request,
        string $token,
        PlatformPaymentSessionService $sessions,
        PlatformPaymentController $payments,
    ): JsonResponse {
        $this->assertChildSession($token, $sessions);

        return $payments->stripeComplete($request, $token);
    }

    private function assertChildSession(string $token, PlatformPaymentSessionService $sessions): PlatformPaymentSession
    {
        $site = current_site();
        abort_unless($site && ! (bool) data_get($site->meta, 'control_site', false), 404);

        $session = $sessions->resolveProviderResult($token);
        abort_unless((int) $session->site_id === (int) $site->id, 404);

        return $session;
    }
}
