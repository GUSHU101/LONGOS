<?php

namespace App\Http\Controllers;

use App\Saas\Models\PlatformPaymentSession;
use App\Services\PlatformPayment\PlatformPaymentSessionService;
use App\Services\PlatformPayment\PlatformStripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Throwable;

class PlatformPaymentWebhookController extends Controller
{
    public function stripe(Request $request, PlatformPaymentSessionService $sessions): JsonResponse
    {
        try {
            $gateway = app(PlatformStripeGateway::class);
            $event   = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', ''),
                $gateway->webhookSecret(),
            )->toArray();
            if (($event['type'] ?? '') !== 'payment_intent.succeeded') {
                return json_success('Stripe event ignored.');
            }

            $intent  = (array) data_get($event, 'data.object', []);
            $uuid    = trim((string) data_get($intent, 'metadata.platform_session', ''));
            $session = PlatformPaymentSession::query()->with('site.domains')->where('uuid', $uuid)->firstOrFail();
            $gateway->validate($session, $intent, true);
            $providerOrderId = (string) ($intent['id'] ?? '');
            if ($session->status === PlatformPaymentSession::STATUS_COMPLETED
                && hash_equals((string) $session->provider_transaction_id, $providerOrderId)) {
                return json_success('Stripe event already processed.');
            }
            $sessions->bindProvider($session, 'stripe', $providerOrderId);
            $sessions->complete($session, 'stripe', $providerOrderId, $providerOrderId, $gateway->safePayload($intent));

            return json_success('Stripe event processed.');
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            Log::notice('Platform Stripe webhook signature rejected.', ['exception' => $e::class]);

            return json_fail('Invalid Stripe signature.', [], 400);
        } catch (Throwable $e) {
            Log::error('Platform Stripe webhook failed.', ['exception' => $e::class]);

            return json_fail('Webhook could not be processed.', [], 500);
        }
    }
}
