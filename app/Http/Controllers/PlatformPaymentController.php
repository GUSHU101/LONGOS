<?php

namespace App\Http\Controllers;

use App\Saas\Models\PlatformPaymentSession;
use App\Services\PlatformPayment\PlatformPaymentSessionService;
use App\Services\PlatformPayment\PlatformPaymentSettings;
use App\Services\PlatformPayment\PlatformPaypalGateway;
use App\Services\PlatformPayment\PlatformStripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class PlatformPaymentController extends Controller
{
    public function __construct(
        private readonly PlatformPaymentSessionService $sessions,
        private readonly PlatformPaymentSettings $settings,
    ) {}

    public function show(string $token): View|RedirectResponse
    {
        try {
            $session = $this->sessions->resolve($token);
            App::setLocale($session->locale ?: 'en');
            if ($session->status === PlatformPaymentSession::STATUS_COMPLETED) {
                return redirect()->away($this->sessions->tenantSuccessUrl($session));
            }
            $snapshot  = $this->settings->snapshot();
            $providers = $this->settings->availableProviders();
            abort_if($providers === [], 503, '平台收款暂不可用。');

            return view('platform-pay.checkout', [
                'session'              => $session,
                'token'                => $token,
                'providers'            => $providers,
                'paypalClientId'       => in_array('paypal', $providers, true) ? $snapshot['paypal']['client_id'] : '',
                'paypalMerchantId'     => in_array('paypal', $providers, true) ? $snapshot['paypal']['merchant_id'] : '',
                'stripePublishableKey' => in_array('stripe', $providers, true) ? $snapshot['stripe']['publishable_key'] : '',
                'cancelUrl'            => $this->settings->mainUrl("_longos/pay/{$token}/cancel"),
            ]);
        } catch (Throwable $e) {
            Log::notice('Platform payment page rejected.', ['exception' => $e::class]);
            abort(410, '支付链接不存在、已结束或已过期。');
        }
    }

    public function paypalCreate(string $token): JsonResponse
    {
        try {
            $session = $this->sessions->resolve($token);
            App::setLocale($session->locale ?: 'en');
            $session = $this->sessions->claimProvider($session, 'paypal');
            $result  = app(PlatformPaypalGateway::class)->create($session, $token);
            $this->sessions->bindProvider($session, 'paypal', $result['id']);

            return json_success('PayPal order created.', ['id' => $result['id']]);
        } catch (Throwable $e) {
            return $this->error($e, __('platform_pay.status.paypal_failed'));
        }
    }

    public function paypalCapture(Request $request, string $token): JsonResponse
    {
        try {
            $data    = $request->validate(['paypal_order_id' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9]{10,32}$/']]);
            $session = $this->sessions->resolve($token);
            App::setLocale($session->locale ?: 'en');
            $this->sessions->assertProviderBinding($session, 'paypal', $data['paypal_order_id']);
            $result  = app(PlatformPaypalGateway::class)->capture($session, $data['paypal_order_id']);
            $session = $this->sessions->complete($session, 'paypal', $data['paypal_order_id'], $result['transaction_id'], $result['payload']);

            return json_success('Payment completed.', ['redirect_url' => $this->sessions->tenantSuccessUrl($session)]);
        } catch (Throwable $e) {
            return $this->error($e, __('platform_pay.status.paypal_failed'));
        }
    }

    public function stripeIntent(string $token): JsonResponse
    {
        try {
            $session = $this->sessions->resolve($token);
            App::setLocale($session->locale ?: 'en');
            $session = $this->sessions->claimProvider($session, 'stripe');
            $result  = app(PlatformStripeGateway::class)->create($session);
            $this->sessions->bindProvider($session, 'stripe', $result['id']);

            return json_success('Stripe PaymentIntent created.', $result);
        } catch (Throwable $e) {
            return $this->error($e, __('platform_pay.status.stripe_failed'));
        }
    }

    public function stripeComplete(Request $request, string $token): JsonResponse
    {
        try {
            $data    = $request->validate(['payment_intent_id' => ['required', 'string', 'max:160', 'regex:/^pi_[A-Za-z0-9_]{8,128}$/']]);
            $session = $this->sessions->resolve($token);
            App::setLocale($session->locale ?: 'en');
            $this->sessions->assertProviderBinding($session, 'stripe', $data['payment_intent_id']);
            $result  = app(PlatformStripeGateway::class)->confirm($session, $data['payment_intent_id']);
            $session = $this->sessions->complete($session, 'stripe', $data['payment_intent_id'], $result['transaction_id'], $result['payload']);

            return json_success('Payment completed.', ['redirect_url' => $this->sessions->tenantSuccessUrl($session)]);
        } catch (Throwable $e) {
            return $this->error($e, __('platform_pay.status.stripe_failed'));
        }
    }

    public function providerReturn(Request $request, string $token, string $provider): RedirectResponse
    {
        try {
            $session = $this->sessions->resolve($token);
            if ($provider === 'paypal') {
                $providerOrderId = trim((string) $request->query('token', ''));
                $this->sessions->assertProviderBinding($session, 'paypal', $providerOrderId);
                $result          = app(PlatformPaypalGateway::class)->capture($session, $providerOrderId);
            } elseif ($provider === 'stripe') {
                $providerOrderId = trim((string) $request->query('payment_intent', ''));
                $this->sessions->assertProviderBinding($session, 'stripe', $providerOrderId);
                $result          = app(PlatformStripeGateway::class)->confirm($session, $providerOrderId);
            } else {
                abort(404);
            }
            $session = $this->sessions->complete($session, $provider, $providerOrderId, $result['transaction_id'], $result['payload']);

            return redirect()->away($this->sessions->tenantSuccessUrl($session));
        } catch (Throwable $e) {
            Log::warning('Platform payment provider return failed.', ['provider' => $provider, 'exception' => $e::class]);
            $session = $this->sessions->resolve($token);

            return redirect()->away($this->sessions->tenantCheckoutUrl($session) . '?payment_error=1');
        }
    }

    public function cancel(string $token): RedirectResponse
    {
        $session = $this->sessions->resolve($token);
        $this->sessions->cancel($session);

        return redirect()->away($this->sessions->tenantCheckoutUrl($session) . '?payment_cancelled=1');
    }

    private function error(Throwable $e, string $message): JsonResponse
    {
        Log::warning($message, ['exception' => $e::class]);

        return json_fail($message, [], 422);
    }
}
