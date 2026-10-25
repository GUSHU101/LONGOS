<?php

namespace App\Console\Commands;

use App\Saas\Models\PlatformPaymentSession;
use App\Services\PlatformPayment\PlatformPaymentSessionService;
use App\Services\PlatformPayment\PlatformPaypalGateway;
use App\Services\PlatformPayment\PlatformStripeGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ReconcilePlatformPayments extends Command
{
    protected $signature = 'saas:platform-pay:reconcile {--limit=100 : Maximum open sessions to inspect} {--prune-days=365 : Retention for terminal sessions}';

    protected $description = 'Reconcile interrupted hosted payments and prune old terminal payment sessions';

    public function handle(PlatformPaymentSessionService $sessions): int
    {
        if (! config('saas.enabled') || ! Schema::connection('saas')->hasTable('saas_platform_payment_sessions')) {
            $this->components->info('Hosted payment storage is not available; nothing to reconcile.');

            return self::SUCCESS;
        }

        $limit                = max(1, min(500, (int) $this->option('limit')));
        $pruneDays            = max(30, min(2555, (int) $this->option('prune-days')));
        $recovered            = 0;
        $failed               = 0;
        $gateways             = [];
        $unresolvedSessionIds = [];
        $cancelledSessionIds  = [];

        // A worker can stop after claiming a provider but before persisting
        // the remote order id. Such a claim cannot have exposed a PayPal
        // approval URL or Stripe client secret to the customer, so it is safe
        // to release after the longest provider timeout. The deterministic
        // provider idempotency keys make a same-channel retry recover the
        // original remote object if it was created just before the crash.
        $releasedClaims = PlatformPaymentSession::query()
            ->where('status', PlatformPaymentSession::STATUS_PROCESSING)
            ->whereIn('provider', ['paypal', 'stripe'])
            ->whereNull('provider_order_id')
            ->where('expires_at', '>', now())
            ->where('updated_at', '<=', now()->subMinutes(5))
            ->update([
                'provider'   => null,
                'status'     => PlatformPaymentSession::STATUS_PENDING,
                'updated_at' => now(),
            ]);
        $expiredClaims = PlatformPaymentSession::query()
            ->where('status', PlatformPaymentSession::STATUS_PROCESSING)
            ->whereNull('provider_order_id')
            ->where('expires_at', '<=', now())
            ->update([
                'provider'   => null,
                'status'     => PlatformPaymentSession::STATUS_EXPIRED,
                'active_key' => null,
                'updated_at' => now(),
            ]);

        $candidates = PlatformPaymentSession::query()
            ->with('site.domains')
            ->financiallyUnsettled()
            ->whereIn('provider', ['paypal', 'stripe'])
            ->where('updated_at', '<=', now()->subMinute())
            ->oldest('id')
            ->limit($limit)
            ->get();

        foreach ($candidates as $session) {
            try {
                $provider = (string) $session->provider;
                $gateway  = $gateways[$provider] ??= $provider === 'paypal'
                    ? app(PlatformPaypalGateway::class)
                    : app(PlatformStripeGateway::class);
                $result = $gateway->recoverCompleted($session, (string) $session->provider_order_id);
                if ($result === null) {
                    if ($provider === 'stripe'
                        && $session->expires_at?->isPast()
                        && $gateway->cancelUnresolved($session, (string) $session->provider_order_id)) {
                        $cancelledSessionIds[] = $session->id;

                        continue;
                    }

                    $unresolvedSessionIds[] = $session->id;

                    continue;
                }

                $sessions->complete(
                    $session,
                    $provider,
                    (string) $session->provider_order_id,
                    $result['transaction_id'],
                    $result['payload'],
                );
                $recovered++;
            } catch (Throwable $exception) {
                $failed++;
                Log::warning('Hosted payment reconciliation failed.', [
                    'session_id' => $session->id,
                    'provider'   => $session->provider,
                    'exception'  => $exception::class,
                ]);
            }
        }

        $expiredPending = PlatformPaymentSession::query()
            ->where('status', PlatformPaymentSession::STATUS_PENDING)
            ->where('expires_at', '<=', now())
            ->update([
                'status'     => PlatformPaymentSession::STATUS_EXPIRED,
                'active_key' => null,
                'updated_at' => now(),
            ]);

        $releasedStale = PlatformPaymentSession::query()
            ->whereIn('status', [PlatformPaymentSession::STATUS_PROCESSING, PlatformPaymentSession::STATUS_EXPIRED])
            ->where('provider', 'paypal')
            ->whereNotNull('provider_order_id')
            ->whereNotNull('active_key')
            ->where('expires_at', '<=', now())
            ->where('created_at', '<', now()->subDays(PlatformPaymentSession::RECOVERY_WINDOW_DAYS))
            ->update([
                'status'     => PlatformPaymentSession::STATUS_EXPIRED,
                'active_key' => null,
                'updated_at' => now(),
            ]);
        $cancelled = $cancelledSessionIds === [] ? 0 : PlatformPaymentSession::query()
            ->whereKey($cancelledSessionIds)
            ->whereIn('status', [PlatformPaymentSession::STATUS_PROCESSING, PlatformPaymentSession::STATUS_EXPIRED])
            ->where('provider', 'stripe')
            ->update([
                'status'     => PlatformPaymentSession::STATUS_CANCELLED,
                'active_key' => null,
                'updated_at' => now(),
            ]);
        $expiredProcessing = $unresolvedSessionIds === [] ? 0 : PlatformPaymentSession::query()
            ->whereKey($unresolvedSessionIds)
            ->where('status', PlatformPaymentSession::STATUS_PROCESSING)
            ->where('expires_at', '<=', now())
            ->update([
                'status'     => PlatformPaymentSession::STATUS_EXPIRED,
                'updated_at' => now(),
            ]);

        $pruned = PlatformPaymentSession::query()
            ->whereIn('status', [
                PlatformPaymentSession::STATUS_COMPLETED,
                PlatformPaymentSession::STATUS_CANCELLED,
                PlatformPaymentSession::STATUS_FAILED,
                PlatformPaymentSession::STATUS_EXPIRED,
            ])
            ->where('created_at', '<', now()->subDays($pruneDays))
            ->delete();

        $expired  = $expiredPending + $expiredProcessing + $expiredClaims;
        $released = $releasedStale  + $releasedClaims;
        $this->components->info("Recovered {$recovered}, cancelled {$cancelled}, failed {$failed}, expired {$expired}, released {$released} stale lock(s), and pruned {$pruned} hosted payment session(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
