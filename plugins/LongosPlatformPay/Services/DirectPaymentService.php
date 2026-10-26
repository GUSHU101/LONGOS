<?php

namespace Plugin\LongosPlatformPay\Services;

use App\Saas\Models\PlatformPaymentSession;
use App\Saas\Support\SensitiveValueRedactor;
use App\Services\PlatformPayment\PlatformPaymentSessionService;
use App\Services\PlatformPayment\PlatformPaymentSettings;
use App\Services\PlatformPayment\PlatformPaypalGateway;
use Beike\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class DirectPaymentService
{
    public function __construct(
        private readonly PlatformPaymentSessionService $sessions,
        private readonly PlatformPaymentSettings $settings,
    ) {}

    /**
     * PayPal provides a provider-owned approval URL, so it can start directly
     * from the order creation request. Stripe still needs its browser card
     * form and therefore deliberately returns null here.
     */
    public function start(Order $order): ?RedirectResponse
    {
        if ((string) $order->payment_method_code !== PlatformPaymentSessionService::PAYMENT_CODE) {
            return null;
        }

        $session         = null;
        $providerCreated = false;

        try {
            if ($this->settings->availableProviders() !== ['paypal']) {
                return null;
            }

            $site = current_site();
            if (! $site || (bool) data_get($site->meta, 'control_site', false)) {
                return null;
            }

            $created         = $this->sessions->create($site, $order);
            $session         = $this->sessions->claimProvider($created['session'], 'paypal');
            $result          = app(PlatformPaypalGateway::class)->create($session, $created['token']);
            $providerCreated = true;
            $this->sessions->bindProvider($session, 'paypal', $result['id']);

            return redirect()->away($result['approval_url']);
        } catch (Throwable $e) {
            if ($session instanceof PlatformPaymentSession && ! $providerCreated) {
                try {
                    $this->sessions->releaseUnboundProviderClaim($session, 'paypal');
                } catch (Throwable) {
                }
            }

            Log::warning('Direct platform PayPal checkout could not start; rendering recovery checkout.', [
                'order_id'  => (int) $order->id,
                'exception' => $e::class,
                'message'   => SensitiveValueRedactor::redact($e->getMessage()),
            ]);

            return null;
        }
    }
}
