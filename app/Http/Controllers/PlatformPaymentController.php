<?php

namespace App\Http\Controllers;

use App\Saas\Models\PlatformPaymentAccount;
use App\Saas\Models\PlatformPaymentSession;
use App\Saas\Support\SensitiveValueRedactor;
use App\Services\PlatformPayment\PlatformPaymentAccountService;
use App\Services\PlatformPayment\PlatformPaymentAccountUnavailable;
use App\Services\PlatformPayment\PlatformPaymentSessionService;
use App\Services\PlatformPayment\PlatformPaymentSettings;
use App\Services\PlatformPayment\PlatformPaypalGateway;
use App\Services\PlatformPayment\PlatformStripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PlatformPaymentController extends Controller
{
    public function __construct(
        private readonly PlatformPaymentSessionService $sessions,
        private readonly PlatformPaymentSettings $settings,
    ) {}

    public function show(string $token): RedirectResponse
    {
        $this->useFallbackLocale();

        try {
            $session = $this->sessions->resolve($token);
        } catch (Throwable $e) {
            Log::notice('Platform payment page rejected.', [
                'exception' => $e::class,
                'reason'    => SensitiveValueRedactor::redact($e->getMessage()),
            ]);
            abort(410, __('platform_pay.invalid_or_expired'));
        }

        $this->assertHostedCallbackHost($session, request());

        $this->useSessionLocale($session, request());
        if ($session->status === PlatformPaymentSession::STATUS_COMPLETED) {
            return redirect()->away($this->sessions->tenantSuccessUrl($session));
        }

        // This endpoint used to render a branded LONGOS interstitial with
        // provider controls. Existing saved links must not expose that page:
        // return the buyer to their own storefront recovery page instead.
        // New checkout sessions never navigate here; PayPal opens directly
        // from the tenant checkout and only returns to this hostname.
        return redirect()
            ->away($this->sessions->tenantRecoveryUrl($session, 'unavailable'))
            ->header('Cache-Control', 'no-store, private, max-age=0')
            ->header('X-Frame-Options', 'DENY');
    }

    public function paypalCreate(string $token): JsonResponse
    {
        $this->useFallbackLocale();
        $session         = null;
        $providerCreated = false;

        try {
            $session = $this->sessions->resolve($token);
            $this->assertHostedCallbackHostWhenNeeded($session, request());
            $this->useSessionLocale($session, request());
            $session         = $this->sessions->claimProvider($session, 'paypal');
            $result          = $this->paypalGateway($session)->create($session, $token);
            $providerCreated = true;
            $this->sessions->bindProvider($session, 'paypal', $result['id']);

            return json_success(__('platform_pay.status.paypal_created'), [
                'id'           => $result['id'],
                'approval_url' => $result['approval_url'],
            ]);
        } catch (Throwable $e) {
            if ($session instanceof PlatformPaymentSession && ! $providerCreated) {
                $this->releaseClaim($session, 'paypal');
                if ($e instanceof PlatformPaymentAccountUnavailable) {
                    // Only an OAuth/credential availability failure is safe to
                    // count against the account circuit.  A later unknown
                    // create-order failure may have reached PayPal, so taking
                    // that merchant out of rotation could hide an order that
                    // still needs reconciliation.
                    $this->recordPaypalTechnicalFailure($session);
                    $replacement = $this->sessions->rotatePaypalAccountAfterPreOrderFailure($session);
                    if ($replacement) {
                        // The tenant checkout retries this same endpoint once.
                        // Deliberately return no callback-host URL: that host is
                        // provider-return infrastructure, not a shopper-facing
                        // payment page.
                        return json_success(__('platform_pay.status.unavailable'), [
                            'account_rotated' => true,
                        ]);
                    }
                }
            }

            return $this->error($e, __('platform_pay.status.paypal_failed'));
        }
    }

    public function status(string $token): JsonResponse
    {
        $this->useFallbackLocale();

        try {
            $session   = $this->sessions->resolveProviderResult($token);
            $this->assertHostedCallbackHostWhenNeeded($session, request());
            $this->useSessionLocale($session, request());
            $completed = $session->status === PlatformPaymentSession::STATUS_COMPLETED;

            return json_success(__('platform_pay.status.verified'), [
                'completed'    => $completed,
                'state'        => (string) $session->status,
                'redirect_url' => $completed ? $this->sessions->tenantSuccessUrl($session) : null,
            ]);
        } catch (Throwable $e) {
            return $this->error($e, __('platform_pay.status.unavailable'));
        }
    }

    public function paypalCapture(Request $request, string $token): JsonResponse
    {
        $this->useFallbackLocale();

        try {
            $data    = $request->validate(['paypal_order_id' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9]{10,32}$/']]);
            $session = $this->sessions->resolveProviderResult($token);
            $this->assertHostedCallbackHostWhenNeeded($session, $request);
            $this->useSessionLocale($session, $request);
            if ($this->isCompletedBy($session, 'paypal', $data['paypal_order_id'])) {
                return json_success(__('platform_pay.status.already_completed'), ['redirect_url' => $this->sessions->tenantSuccessUrl($session)]);
            }
            $this->sessions->assertProviderBinding($session, 'paypal', $data['paypal_order_id']);
            $result  = $this->paypalGateway($session)->capture($session, $data['paypal_order_id']);
            $session = $this->sessions->complete($session, 'paypal', $data['paypal_order_id'], $result['transaction_id'], $result['payload']);

            return json_success(__('platform_pay.status.completed'), ['redirect_url' => $this->sessions->tenantSuccessUrl($session)]);
        } catch (Throwable $e) {
            return $this->error($e, __('platform_pay.status.paypal_failed'));
        }
    }

    public function stripeIntent(string $token): JsonResponse
    {
        $this->useFallbackLocale();
        $session         = null;
        $providerCreated = false;

        try {
            $session = $this->sessions->resolve($token);
            $this->assertHostedCallbackHostWhenNeeded($session, request());
            $this->useSessionLocale($session, request());
            $session         = $this->sessions->claimProvider($session, 'stripe');
            $result          = app(PlatformStripeGateway::class)->create($session);
            $providerCreated = true;
            $this->sessions->bindProvider($session, 'stripe', $result['id']);

            return json_success(__('platform_pay.status.stripe_created'), $result);
        } catch (Throwable $e) {
            if ($session instanceof PlatformPaymentSession && ! $providerCreated) {
                $this->releaseClaim($session, 'stripe');
            }

            return $this->error($e, __('platform_pay.status.stripe_failed'));
        }
    }

    public function stripeComplete(Request $request, string $token): JsonResponse
    {
        $this->useFallbackLocale();

        try {
            $data    = $request->validate(['payment_intent_id' => ['required', 'string', 'max:160', 'regex:/^pi_[A-Za-z0-9_]{8,128}$/']]);
            $session = $this->sessions->resolveProviderResult($token);
            $this->assertHostedCallbackHostWhenNeeded($session, $request);
            $this->useSessionLocale($session, $request);
            if ($this->isCompletedBy($session, 'stripe', $data['payment_intent_id'])) {
                return json_success(__('platform_pay.status.already_completed'), ['redirect_url' => $this->sessions->tenantSuccessUrl($session)]);
            }
            $this->sessions->assertProviderBinding($session, 'stripe', $data['payment_intent_id']);
            $result  = app(PlatformStripeGateway::class)->confirm($session, $data['payment_intent_id']);
            $session = $this->sessions->complete($session, 'stripe', $data['payment_intent_id'], $result['transaction_id'], $result['payload']);

            return json_success(__('platform_pay.status.completed'), ['redirect_url' => $this->sessions->tenantSuccessUrl($session)]);
        } catch (Throwable $e) {
            return $this->error($e, __('platform_pay.status.stripe_failed'));
        }
    }

    public function providerReturn(Request $request, string $token, string $provider): RedirectResponse
    {
        $this->useFallbackLocale();
        $session = null;

        try {
            $session = $this->sessions->resolveProviderResult($token);
            $this->assertHostedCallbackHost($session, $request);
            $this->useSessionLocale($session, $request);
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
                ? $this->paypalGateway($session)->capture($session, $providerOrderId)
                : app(PlatformStripeGateway::class)->confirm($session, $providerOrderId);
            $session = $this->sessions->complete($session, $provider, $providerOrderId, $result['transaction_id'], $result['payload']);

            return redirect()->away($this->sessions->tenantSuccessUrl($session));
        } catch (Throwable $e) {
            Log::warning('Platform payment provider return failed.', [
                'provider'  => $provider,
                'exception' => $e::class,
                'reason'    => SensitiveValueRedactor::redact($e->getMessage()),
            ]);
            if (! $session instanceof PlatformPaymentSession) {
                abort(410, __('platform_pay.invalid_or_expired'));
            }

            return redirect()->away($this->sessions->tenantRecoveryUrl($session, 'error'));
        }
    }

    public function cancel(string $token): RedirectResponse
    {
        $this->useFallbackLocale();

        try {
            $session = $this->sessions->resolve($token);
        } catch (Throwable $e) {
            Log::notice('Platform payment cancellation rejected.', [
                'exception' => $e::class,
                'reason'    => SensitiveValueRedactor::redact($e->getMessage()),
            ]);
            abort(410, __('platform_pay.invalid_or_expired'));
        }
        $this->assertHostedCallbackHost($session, request());
        $this->useSessionLocale($session, request());
        if ($session->status === PlatformPaymentSession::STATUS_COMPLETED) {
            return redirect()->away($this->sessions->tenantSuccessUrl($session));
        }
        $this->sessions->cancel($session);

        return redirect()->away($this->sessions->tenantRecoveryUrl($session, 'cancelled'));
    }

    private function error(Throwable $e, string $message): JsonResponse
    {
        $reference = 'LPP-' . Str::upper(Str::random(10));
        Log::warning('Platform payment provider request failed.', [
            'exception' => $e::class,
            'reason'    => SensitiveValueRedactor::redact($e->getMessage()),
            'reference' => $reference,
        ]);

        return json_fail(
            __('platform_pay.status.failed_with_reference', [
                'message'   => $message,
                'reference' => $reference,
            ]),
            ['error_code' => 'provider_request_failed', 'reference' => $reference],
            422,
        );
    }

    private function releaseClaim(PlatformPaymentSession $session, string $provider): void
    {
        try {
            $this->sessions->releaseUnboundProviderClaim($session, $provider);
        } catch (Throwable $exception) {
            Log::warning('Platform payment provider claim could not be released.', [
                'provider'  => $provider,
                'exception' => $exception::class,
                'reason'    => SensitiveValueRedactor::redact($exception->getMessage()),
            ]);
        }
    }

    private function recordPaypalTechnicalFailure(PlatformPaymentSession $session): void
    {
        if (! $session->payment_account_id) {
            return;
        }

        try {
            $account = PlatformPaymentAccount::query()->find($session->payment_account_id);
            if ($account) {
                // OAuth failed before PayPal could create an order. Open the
                // circuit immediately so this same buyer cannot be sent back
                // to an account with unavailable credentials; the scheduled
                // health probe can close it as soon as authentication works.
                app(PlatformPaymentAccountService::class)->recordUnavailable($account);
            }
        } catch (Throwable $exception) {
            // Payment error telemetry must never replace the original
            // customer-facing error or prevent its safe session rollback.
            Log::notice('Platform Pay account failure could not be recorded.', [
                'session_id' => $session->id,
                'exception'  => $exception::class,
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

    private function paypalGateway(PlatformPaymentSession $session): PlatformPaypalGateway
    {
        return new PlatformPaypalGateway($this->settings, null, $session);
    }

    private function assertHostedCallbackHostWhenNeeded(PlatformPaymentSession $session, Request $request): void
    {
        // The same action methods are also called by the child-store AJAX
        // bridge. Only routes served from /_longos/pay must be constrained to
        // the session's callback hostname.
        if ($request->is('_longos/pay/*')) {
            $this->assertHostedCallbackHost($session, $request);
        }
    }

    private function assertHostedCallbackHost(PlatformPaymentSession $session, Request $request): void
    {
        abort_unless($this->settings->sessionUsesCallbackHost($session, $request->getHost()), 404);
    }

    private function useFallbackLocale(): void
    {
        $locale = trim((string) config('longos.checkout_locale', 'en'));
        App::setLocale($locale !== '' ? $locale : 'en');
    }

    /**
     * Provider callbacks run on an account-specific callback hostname. They
     * do not render a payment page, but still need the tenant's published
     * locale to generate a consistent redirect/error response. The optional
     * bridge never sends request/order data to a translation provider.
     */
    private function useSessionLocale(PlatformPaymentSession $session, Request $request): void
    {
        $fallback = trim((string) ($session->locale ?: config('longos.checkout_locale', 'en')));
        $fallback = preg_match('/^[A-Za-z_]{2,16}$/', $fallback) === 1 ? $fallback : 'en';
        $bridge   = 'Plugin\\LongosGlobalTranslate\\Services\\PlatformPaymentLocaleBridge';
        if (! class_exists($bridge) || ! $session->site) {
            App::setLocale($fallback);

            return;
        }

        try {
            $locale = app($bridge)->apply($session->site, $request, $fallback);
            App::setLocale(preg_match('/^[A-Za-z_]{2,16}$/', (string) $locale) === 1 ? $locale : $fallback);
        } catch (Throwable) {
            App::setLocale($fallback);
        }
    }
}
