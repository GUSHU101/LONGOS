<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Illuminate\Support\Facades\DB;
use Plugin\LongosGlobalTranslate\Providers\ProviderRegistry;
use Plugin\LongosGlobalTranslate\Providers\TranslationProviderRequest;
use RuntimeException;
use Throwable;

/**
 * One explicit, operator-triggered provider probe. It is deliberately not a
 * background health check: real credentials may incur a provider charge.
 */
final class ProviderVerificationService
{
    public function __construct(
        private readonly ProviderAccountService $accounts,
        private readonly ProviderRegistry $providers,
        private readonly UsageLedger $ledger,
    ) {}

    /** @return array<string, mixed> */
    public function verify(int $accountId, ?int $actorId): array
    {
        $account = DB::table('lgt_provider_accounts')->where('id', $accountId)->first();
        if ($account === null || ! $account->enabled || (int) $account->paid_cap !== 0) {
            throw new RuntimeException('PROVIDER_ACCOUNT_NOT_VERIFIABLE');
        }
        $provider = $this->providers->get((string) $account->provider);
        // Decrypt and validate credentials before a ledger entry moves to
        // `sending`. A failed local decrypt/shape check cannot have contacted
        // an upstream API and must never be presented as a billable unknown.
        $credentials = $this->accounts->credentials($account);
        $provider->validateCredentialShape($credentials);
        $source               = 'LONGOS verification';
        $reservation          = $this->ledger->reserve($accountId, 19, null, null, null, true);
        $requestMarkedSending = false;

        try {
            $this->ledger->markSending($reservation['id']);
            $requestMarkedSending = true;
            $response             = $provider->send(new TranslationProviderRequest(
                $reservation['request_uuid'], $accountId, 'en', 'fr', [$source], hash('sha256', $source), 8,
            ), $credentials);
            if (! $response->ok || count($response->translations) !== 1) {
                $failure = $provider->classifyFailure($response);
                if ($failure['definitely_unbilled']) {
                    $this->ledger->definiteFailure($reservation['id'], $failure['code']);
                } else {
                    $this->ledger->uncertain($reservation['id'], $failure['code']);
                }

                throw new RuntimeException('PROVIDER_VERIFICATION_FAILED');
            }
            $this->ledger->commit($reservation['id'], (string) ($response->requestId ?? ''));

            return $this->accounts->markVerified($accountId, $actorId);
        } catch (RuntimeException $exception) {
            if (! $requestMarkedSending) {
                try {
                    $this->ledger->definiteFailure($reservation['id'], 'PRE_SEND_FAILURE');
                } catch (Throwable) {
                    // The original state remains journaled for review.
                }
            }

            throw $exception;
        } catch (Throwable) {
            try {
                if ($requestMarkedSending) {
                    $this->ledger->uncertain($reservation['id'], 'VERIFICATION_EXCEPTION');
                } else {
                    $this->ledger->definiteFailure($reservation['id'], 'PRE_SEND_FAILURE');
                }
            } catch (Throwable) {
                // The attempt has already been journaled and remains visible
                // to an operator even if a final state update also fails.
            }

            throw new RuntimeException('PROVIDER_VERIFICATION_UNCERTAIN');
        }
    }
}
