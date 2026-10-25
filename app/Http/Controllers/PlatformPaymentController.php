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
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PlatformPaymentController extends Controller
{
    public function __construct(
        private readonly PlatformPaymentSessionService $sessions,
        private readonly PlatformPaymentSettings $settings,
    ) {}

    public function show(string $token): View|Response
    {
        try {
            $session = $this->sessions->resolve($token);
        } catch (Throwable $e) {
            Log::notice('Platform payment page rejected.', ['exception' => $e::class]);
            abort(410, '支付链接不存在、已结束或已过期。');
        }

        App::setLocale($session->locale ?: 'en');
        if ($session->status === PlatformPaymentSession::STATUS_COMPLETED) {
            return redirect()->away($this->sessions->tenantSuccessUrl($session));
        }

        $snapshot = null;

        try {
            $snapshot  = $this->settings->snapshot();
            $providers = $this->settings->availableProvidersForSnapshot($snapshot);
            if ($session->provider) {
                $configured = $this->settings->configuredProviders($snapshot);
                $providers  = in_array($session->provider, $configured, true) ? [$session->provider] : [];
            }
        } catch (Throwable $e) {
            Log::error('Platform payment providers are unavailable.', ['exception' => $e::class]);
            $providers = [];
        }

        $viewData = [
            'session'              => $session,
            'token'                => $token,
            'providers'            => $providers,
            'paypalClientId'       => in_array('paypal', $providers, true) ? (string) data_get($snapshot, 'paypal.client_id', '') : '',
            'paypalMerchantId'     => in_array('paypal', $providers, true) ? (string) data_get($snapshot, 'paypal.merchant_id', '') : '',
            'stripePublishableKey' => in_array('stripe', $providers, true) ? (string) data_get($snapshot, 'stripe.publishable_key', '') : '',
            'cancelUrl'            => $providers === []
                ? $this->sessions->tenantRecoveryUrl($session, 'unavailable')
                : (string) $snapshot['control_url'] . "/_longos/pay/{$token}/cancel",
        ];

        return $providers === []
            ? response()->view('platform-pay.checkout', $viewData, 503)
            : view('platform-pay.checkout', $viewData);
    }

    public function paypalCreate(string $token): JsonResponse
    {
        $session         = null;
        $providerCreated = false;

        try {
            $session = $this->sessions->resolve($token);
            App::setLocale($session->locale ?: 'en');
            $session         = $this->sessions->claimProvider($session, 'paypal');
            $result          = app(PlatformPaypalGateway::class)->create($session, $token);
            $providerCreated = true;
            $this->sessions->bindProvider($session, 'paypal', $result['id']);

            return json_success('PayPal order created.', ['id' => $result['id']]);
        } catch (Throwable $e) {
            if ($session instanceof PlatformPaymentSession && ! $providerCreated) {
                $this->releaseClaim($session, 'paypal');
            }

            return $this->error($e, __('platform_pay.status.paypal_failed'));
        }
    }

    public function paypalCapture(Request $request, string $token): JsonResponse
    {
        try {
            $data    = $request->validate(['paypal_order_id' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9]{10,32}$/']]);
            $session = $this->sessions->resolveProviderResult($token);
            App::setLocale($session->locale ?: 'en');
            if ($this->isCompletedBy($session, 'paypal', $data['paypal_order_id'])) {
                return json_success('Payment already completed.', ['redirect_url' => $this->sessions->tenantSuccessUrl($session)]);
            }
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
        $session         = null;
        $providerCreated = false;

        try {
            $session = $this->sessions->resolve($token);
            App::setLocale($session->locale ?: 'en');
            $session         = $this->sessions->claimProvider($session, 'stripe');
            $result          = app(PlatformStripeGateway::class)->create($session);
            $providerCreated = true;
            $this->sessions->bindProvider($session, 'stripe', $result['id']);

            return json_success('Stripe PaymentIntent created.', $result);
        } catch (Throwable $e) {
            if ($session instanceof PlatformPaymentSession && ! $providerCreated) {
                $this->releaseClaim($session, 'stripe');
            }

            return $this->error($e, __('platform_pay.status.stripe_failed'));
        }
    }

    public function stripeComplete(Request $request, string $token): JsonResponse
    {
        try {
            $data    = $request->validate(['payment_intent_id' => ['required', 'string', 'max:160', 'regex:/^pi_[A-Za-z0-9_]{8,128}$/']]);
            $session = $this->sessions->resolveProviderResult($token);
            App::setLocale($session->locale ?: 'en');
            if ($this->isCompletedBy($session, 'stripe', $data['payment_intent_id'])) {
                return json_success('Payment already completed.', ['redirect_url' => $this->sessions->tenantSuccessUrl($session)]);
            }
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
        $session = null;

        try {
            $session = $this->sessions->resolveProviderResult($token);
            if ($provider === 'paypal') {
                $providerOrderId = trim((string) $request->query('token', ''));
            } elseif ($provider === 'stripe') {
                $providerOrderId = trim((string) $request->query('payment_intent', ''));
            } else {
                abort(404);
            }
            if ($this->isCompletedBy($session, $provider, $providerOrderId)) {
                return redirect()->away($this->sessions->tenantSuccessUrl($session));
            }

            $this->sessions->assertProviderBinding($session, $provider, $providerOrderId);
            $result = $provider === 'paypal'
                ? app(PlatformPaypalGateway::class)->capture($session, $providerOrderId)
                : app(PlatformStripeGateway::class)->confirm($session, $providerOrderId);
            $session = $this->sessions->complete($session, $provider, $providerOrderId, $result['transaction_id'], $result['payload']);

            return redirect()->away($this->sessions->tenantSuccessUrl($session));
        } catch (Throwable $e) {
            Log::warning('Platform payment provider return failed.', ['provider' => $provider, 'exception' => $e::class]);
            if (! $session instanceof PlatformPaymentSession) {
                abort(410, '支付链接不存在、已结束或已过期。');
            }

            return redirect()->away($this->sessions->tenantRecoveryUrl($session, 'error'));
        }
    }

    public function cancel(string $token): RedirectResponse
    {
        try {
            $session = $this->sessions->resolve($token);
        } catch (Throwable $e) {
            Log::notice('Platform payment cancellation rejected.', ['exception' => $e::class]);
            abort(410, '支付链接不存在、已结束或已过期。');
        }
        if ($session->status === PlatformPaymentSession::STATUS_COMPLETED) {
            return redirect()->away($this->sessions->tenantSuccessUrl($session));
        }
        $this->sessions->cancel($session);

        return redirect()->away($this->sessions->tenantRecoveryUrl($session, 'cancelled'));
    }

    private function error(Throwable $e, string $message): JsonResponse
    {
        Log::warning($message, ['exception' => $e::class]);

        return json_fail($message, [], 422);
    }

    private function releaseClaim(PlatformPaymentSession $session, string $provider): void
    {
        try {
            $this->sessions->releaseUnboundProviderClaim($session, $provider);
        } catch (Throwable $exception) {
            Log::warning('Platform payment provider claim could not be released.', [
                'provider'  => $provider,
                'exception' => $exception::class,
            ]);
        }
    }

    private function isCompletedBy(PlatformPaymentSession $session, string $provider, string $providerOrderId): bool
    {
        return $session->status === PlatformPaymentSession::STATUS_COMPLETED
            && $providerOrderId !== ''
            && hash_equals((string) $session->provider, $provider)
            && hash_equals((string) $session->provider_order_id, $providerOrderId);
    }
}
