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

        $candidates = PlatformPaymentSession::query()
            ->with('site.domains')
            ->whereIn('status', [PlatformPaymentSession::STATUS_PROCESSING, PlatformPaymentSession::STATUS_EXPIRED])
            ->whereIn('provider', ['paypal', 'stripe'])
            ->whereNotNull('provider_order_id')
            ->where('created_at', '>=', now()->subDays(4))
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
        $expiredProcessing = $unresolvedSessionIds === [] ? 0 : PlatformPaymentSession::query()
            ->whereKey($unresolvedSessionIds)
            ->where('status', PlatformPaymentSession::STATUS_PROCESSING)
            ->where('expires_at', '<=', now())
            ->update([
                'status'     => PlatformPaymentSession::STATUS_EXPIRED,
                'active_key' => null,
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

        $expired = $expiredPending + $expiredProcessing;
        $this->components->info("Recovered {$recovered}, failed {$failed}, expired {$expired}, and pruned {$pruned} hosted payment session(s).");

        return self::SUCCESS;
    }
}
