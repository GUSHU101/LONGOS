<?php

namespace App\Console\Commands;

use App\Services\PlatformPayment\PlatformPaymentAccountService;
use App\Services\PlatformPayment\PlatformPaymentSettings;
use App\Services\PlatformPayment\PlatformPaypalGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Performs a bounded, credentials-only health probe for the managed PayPal
 * account pool. No payment order is created by this command.
 */
class CheckPlatformPaymentAccounts extends Command
{
    protected $signature = 'saas:platform-pay:accounts-health {--limit=100 : Maximum enabled PayPal accounts to probe}';

    protected $description = 'Check managed Platform Pay account availability without creating orders';

    public function handle(
        PlatformPaymentAccountService $accounts,
        PlatformPaymentSettings $settings,
    ): int {
        if (! config('saas.enabled') || ! $accounts->schemaReady()) {
            $this->components->info('Managed Platform Pay accounts are not available; nothing to check.');

            return self::SUCCESS;
        }

        $limit   = max(1, min(500, (int) $this->option('limit')));
        $checked = 0;
        $ready   = 0;
        $failed  = 0;

        foreach ($accounts->accounts()->where('enabled', true)->take($limit) as $account) {
            $checked++;
            try {
                // snapshotForPaypalAccount uses the exact account credential
                // without changing the routing order. The gateway constructor
                // performs OAuth only; healthCheck never creates an order.
                $snapshot = $settings->snapshotForPaypalAccount($account);
                (new PlatformPaypalGateway($settings, $snapshot))->healthCheck();
                $accounts->markHealth($account, 'ready');
                $ready++;
            } catch (\Throwable $exception) {
                // Only this pre-order OAuth probe influences the checkout
                // circuit. It is safe to deprioritize a merchant before any
                // buyer has been shown a payment approval URL.
                try {
                    $accounts->recordFailure($account);
                } catch (\Throwable $persistenceException) {
                    Log::notice('Managed Platform Pay health result could not be persisted.', [
                        'account_id' => $account->id,
                        'exception'  => $persistenceException::class,
                    ]);
                }
                $failed++;
                Log::warning('Managed Platform Pay account health probe failed.', [
                    'account_id' => $account->id,
                    'exception'  => $exception::class,
                ]);
            }
        }

        $this->components->info("Checked {$checked} managed PayPal account(s): {$ready} ready, {$failed} unavailable.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
