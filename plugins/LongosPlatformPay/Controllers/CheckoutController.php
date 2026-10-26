<?php

namespace Plugin\LongosPlatformPay\Controllers;

use App\Http\Controllers\PlatformPaymentController;
use App\Saas\Models\PlatformPaymentSession;
use App\Saas\Support\SensitiveValueRedactor;
use App\Services\PlatformPayment\PlatformPaymentAmount;
use App\Services\PlatformPayment\PlatformPaymentCurrency;
use App\Services\PlatformPayment\PlatformPaymentSessionService;
use App\Services\PlatformPayment\PlatformPaymentSettings;
use Beike\Repositories\OrderRepo;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CheckoutController
{
    public function store(
        Request $request,
        PlatformPaymentSessionService $sessions,
        PlatformPaymentSettings $settings,
    ): JsonResponse {
        $this->useCheckoutLocale();
        $orderNumber = '';

        try {
            $data        = $request->validate(['order_number' => ['required', 'string', 'max:128']]);
            $orderNumber = (string) $data['order_number'];
            $customer    = current_customer();
            $order       = OrderRepo::getOrderByNumber($orderNumber, $customer);
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

            // Keep availability, public SDK keys, the persisted session and
            // the control URL on one immutable credential snapshot. This
            // avoids a second control-database read after the order session
            // has already been inserted.
            $snapshot  = $settings->snapshot();
            $providers = $settings->availableProvidersForSnapshot($snapshot);
            if ($providers === []) {
                throw new RuntimeException('当前没有可用的平台支付渠道。');
            }

            $created = $sessions->create($site, $order, $snapshot);
            $session = $created['session'];
            if ($session->provider) {
                $configured = $settings->configuredProviders($snapshot);
                $providers  = in_array($session->provider, $configured, true) ? [$session->provider] : [];
            }
            $token    = $created['token'];
            $decimals = PlatformPaymentCurrency::decimalPlaces((string) $session->currency);
            $response = json_success(trans('LongosPlatformPay::common.session_created'), [
                'token'        => $token,
                'checkout_url' => $created['checkout_url'],
                'return_url'   => rtrim((string) $snapshot['control_url'], '/') . "/_longos/pay/{$token}/return/stripe",
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
            [$errorCode, $httpStatus] = $this->classifySessionFailure($e);
            $reference                = 'LPP-' . Str::upper(Str::random(10));
            Log::warning('Platform payment session creation failed.', [
                'exception'  => $e::class,
                'reason'     => SensitiveValueRedactor::redact($e->getMessage()),
                'error_code' => $errorCode,
                'reference'  => $reference,
                'site_id'    => current_site()?->id,
                'order_hash' => $orderNumber !== '' ? hash('sha256', $orderNumber) : null,
            ]);

            return json_fail(
                trans('LongosPlatformPay::common.session_failed', ['reference' => $reference]),
                ['error_code' => $errorCode, 'reference' => $reference],
                $httpStatus,
            );
        }
    }

    public function paypalCreate(
        string $token,
        PlatformPaymentSessionService $sessions,
        PlatformPaymentController $payments,
    ): JsonResponse {
        $this->useCheckoutLocale();
        $this->assertChildSession($token, $sessions);

        return $payments->paypalCreate($token);
    }

    public function status(
        string $token,
        PlatformPaymentSessionService $sessions,
    ): JsonResponse {
        $this->useCheckoutLocale();
        $session   = $this->assertChildSession($token, $sessions);
        $completed = $session->status === PlatformPaymentSession::STATUS_COMPLETED;

        return json_success(trans('LongosPlatformPay::common.status_verified'), [
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
        $this->useCheckoutLocale();
        $this->assertChildSession($token, $sessions);

        return $payments->paypalCapture($request, $token);
    }

    public function stripeIntent(
        string $token,
        PlatformPaymentSessionService $sessions,
        PlatformPaymentController $payments,
    ): JsonResponse {
        $this->useCheckoutLocale();
        $this->assertChildSession($token, $sessions);

        return $payments->stripeIntent($token);
    }

    public function stripeComplete(
        Request $request,
        string $token,
        PlatformPaymentSessionService $sessions,
        PlatformPaymentController $payments,
    ): JsonResponse {
        $this->useCheckoutLocale();
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

    /** @return array{0: string, 1: int} */
    private function classifySessionFailure(Throwable $exception): array
    {
        if ($exception instanceof ValidationException) {
            return ['invalid_request', 422];
        }
        if ($exception instanceof QueryException) {
            $message = strtolower($exception->getMessage());
            if (str_contains($message, 'timestamps_utc') || str_contains($message, 'unknown column')) {
                return ['control_schema_outdated', 503];
            }

            return ['control_storage_unavailable', 503];
        }

        $message = $exception->getMessage();
        if (str_contains($message, '正在创建') || str_contains($message, '进行中')) {
            return ['session_busy', 409];
        }
        if (str_contains($message, '订单') || str_contains($message, '访客会话')) {
            return ['order_unavailable', 422];
        }
        if (str_contains($message, '站点') || str_contains($message, '子站')) {
            return ['site_unavailable', 503];
        }
        if (str_contains($message, '渠道') || str_contains($message, '主站') || str_contains($message, '平台代收')) {
            return ['configuration_unavailable', 503];
        }

        return ['payment_service_unavailable', 503];
    }

    private function useCheckoutLocale(): void
    {
        $locale = trim((string) config('longos.checkout_locale', 'en'));
        app()->setLocale($locale !== '' ? $locale : 'en');
    }
}
