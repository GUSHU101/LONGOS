<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Plugin\LongosGlobalTranslate\Jobs\ProcessTranslationJob;
use Plugin\LongosGlobalTranslate\Providers\ProviderRegistry;
use Plugin\LongosGlobalTranslate\Providers\TranslationProviderRequest;
use RuntimeException;
use Throwable;

final class TranslationJobService
{
    public function __construct(
        private readonly ContentScanner $scanner,
        private readonly TranslationSettings $settings,
        private readonly ProviderAccountService $accounts,
        private readonly ProviderRegistry $providers,
        private readonly UsageLedger $ledger,
        private readonly TranslationLibrary $library,
    ) {}

    /** @param list<string> $locales @param list<string> $kinds
     * @return array<string, mixed>
     */
    public function plan(array $locales, array $kinds, ?int $actorId): array
    {
        $settings = $this->settings->all();
        $locales  = array_values(array_unique(array_filter(array_map(static fn (mixed $locale): string => TranslationSettings::locale((string) $locale), $locales))));
        $allowed  = array_flip((array) $settings['enabled_locales']);
        foreach ($locales as $locale) {
            if (! isset($allowed[$locale])) {
                throw new RuntimeException('TARGET_LOCALE_NOT_ENABLED');
            }
        }
        $kinds = array_values(array_intersect(['product', 'category', 'page', 'builder', CommerceUiCatalog::KIND], $kinds));
        if ($locales === [] || $kinds === []) {
            throw new RuntimeException('EMPTY_TRANSLATION_SCOPE');
        }
        $report = $this->scanner->scan($kinds, $actorId);
        $job    = DB::transaction(function () use ($locales, $kinds, $settings, $actorId): array {
            $existing = DB::table('lgt_jobs')
                ->whereIn('status', ['planned', 'queued', 'scanning', 'running', 'pausing', 'paused'])
                ->where('scope', json_encode($kinds))
                ->where('target_locales', json_encode($locales))
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return (array) $existing + ['created' => false];
            }
            $id = DB::table('lgt_jobs')->insertGetId([
                'public_id'      => (string) Str::uuid(),
                'status'         => $settings['mode'] === TranslationSettings::MODE_FREE_ONLY ? 'queued' : 'planned',
                'mode'           => $settings['mode'],
                'scope'          => json_encode($kinds),
                'target_locales' => json_encode($locales),
                'plan'           => json_encode(['source_locale' => $settings['source_locale'], 'public_content_only' => true]),
                'created_by'     => $actorId,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
            $entries = DB::table('lgt_entries')->whereIn('kind', $kinds)->where('is_public', true)->where('is_tombstoned', false)->orderBy('id')->get(['id', 'source_hash']);
            foreach ($entries->chunk(250) as $chunk) {
                $rows = [];
                foreach ($chunk as $entry) {
                    foreach ($locales as $locale) {
                        $rows[] = [
                            'job_id' => $id, 'entry_id' => $entry->id, 'target_locale' => $locale, 'source_hash' => $entry->source_hash,
                            'status' => 'ready', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
                        ];
                    }
                }
                DB::table('lgt_job_items')->insertOrIgnore($rows);
            }
            $total = DB::table('lgt_job_items')->where('job_id', $id)->count();
            DB::table('lgt_jobs')->where('id', $id)->update(['total_items' => $total]);

            return (array) DB::table('lgt_jobs')->where('id', $id)->first() + ['created' => true];
        });
        if (($job['created'] ?? false) && $job['mode'] === TranslationSettings::MODE_FREE_ONLY) {
            ProcessTranslationJob::dispatch((int) $job['id'])->afterCommit();
        }

        return ['job' => $job, 'coverage' => $report];
    }

    public function process(int $jobId, int $limit = 50, ?string $runToken = null): void
    {
        $runToken = $runToken ?: (string) Str::uuid();
        $job      = DB::table('lgt_jobs')->where('id', $jobId)->first();
        if ($job === null || in_array($job->status, ['cancelled', 'completed', 'partial_failed', 'paused'], true)) {
            return;
        }
        $settings = $this->settings->all();
        if ($settings['mode'] !== TranslationSettings::MODE_FREE_ONLY || $job->mode !== TranslationSettings::MODE_FREE_ONLY) {
            $this->setJobStatus($jobId, 'paused');

            return;
        }
        $this->setJobStatus($jobId, 'running');
        for ($i = 0; $i < min(50, max(1, $limit)); $i++) {
            $item = $this->claim($jobId, $runToken);
            if ($item === null) {
                break;
            }

            try {
                $this->processItem($job, $item, (string) $settings['source_locale'], $runToken);
            } catch (Throwable $exception) {
                // Errors before a provider reservation (for example malformed
                // source content) must not strand a claimed item forever. A
                // reservation created after a provider call is always handled
                // by processItem's conservative uncertainty path instead.
                $this->finishItem((int) $item->id, 'failed', $runToken, $this->failureCode($exception));
            }
            if (DB::table('lgt_jobs')->where('id', $jobId)->value('status') !== 'running') {
                break;
            }
        }
        $this->finishIfTerminal($jobId);
    }

    /**
     * Fail only claims owned by an exhausted Laravel queue run. The durable
     * run token avoids one worker ever completing another worker's item.
     */
    public function failClaimsForRun(int $jobId, string $runToken): void
    {
        $attemptIds = DB::transaction(function () use ($jobId, $runToken): array {
            $items = DB::table('lgt_job_items')
                ->where('job_id', $jobId)
                ->where('status', 'claimed')
                ->where('claim_token', $runToken)
                ->lockForUpdate()
                ->get();
            $attemptIds = [];
            foreach ($items as $item) {
                if ($item->attempt_id !== null) {
                    $attemptIds[] = (int) $item->attempt_id;
                }
                DB::table('lgt_job_items')->where('id', $item->id)->where('status', 'claimed')->where('claim_token', $runToken)->update([
                    'status'  => 'failed', 'failure_code' => 'WORKER_EXHAUSTED', 'completed_at' => now(), 'claim_token' => null,
                    'version' => (int) $item->version + 1, 'updated_at' => now(),
                ]);
            }
            if ($items->isNotEmpty()) {
                $job = DB::table('lgt_jobs')->where('id', $jobId)->lockForUpdate()->first();
                if ($job !== null) {
                    DB::table('lgt_jobs')->where('id', $jobId)->update([
                        'completed_items' => (int) $job->completed_items + $items->count(),
                        'failed_items'    => (int) $job->failed_items    + $items->count(),
                        'updated_at'      => now(),
                    ]);
                }
            }

            return array_values(array_unique($attemptIds));
        }, 3);
        foreach ($attemptIds as $attemptId) {
            try {
                $this->ledger->recoverWorkerLoss($attemptId);
            } catch (Throwable) {
                // A successful path may have committed the ledger just before
                // the process died; that terminal state is already safer.
            }
        }
        $this->finishIfTerminal($jobId);
    }

    /** Resume paused work only after an operator has explicitly authorized a provider. */
    public function resumePausedJobs(): int
    {
        if ($this->settings->all()['mode'] !== TranslationSettings::MODE_FREE_ONLY) {
            return 0;
        }
        $ids = DB::transaction(function (): array {
            $jobs = DB::table('lgt_jobs')->where('status', 'paused')->where('mode', TranslationSettings::MODE_FREE_ONLY)->lockForUpdate()->get(['id']);
            if ($jobs->isEmpty()) {
                return [];
            }
            $ids = $jobs->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            DB::table('lgt_jobs')->whereIn('id', $ids)->update(['status' => 'queued', 'updated_at' => now()]);

            return $ids;
        }, 3);
        foreach ($ids as $id) {
            ProcessTranslationJob::dispatch($id)->afterCommit();
        }

        return count($ids);
    }

    private function processItem(object $job, object $item, string $sourceLocale, string $runToken): void
    {
        $entry = DB::table('lgt_entries')->where('id', $item->entry_id)->first();
        if ($entry === null || ! $entry->is_public || $entry->is_tombstoned || ! hash_equals((string) $entry->source_hash, (string) $item->source_hash)) {
            $this->finishItem($item->id, 'stale', $runToken);

            return;
        }
        $accounts = $this->accountsFor($sourceLocale, (string) $item->target_locale);
        if ($accounts === []) {
            // No account is a recoverable operator configuration state, not
            // a source failure. Put this exact item back and stop dispatching
            // until an operator explicitly enables a verified account.
            $this->releaseItem((int) $item->id, $runToken);
            $this->setJobStatus((int) $job->id, 'paused');

            return;
        }
        $prepared           = TextContract::protect((string) $entry->source_text, $this->protectedTerms($sourceLocale, (string) $item->target_locale));
        $segments           = TextContract::segments($prepared['text'], 4500, 4800, array_keys($prepared['tokens']));
        $successful         = [];
        $translatedSegments = [];
        $providersUsed      = [];
        $accountsUsed       = [];
        foreach ($segments as $segment) {
            // A single segment is a valid request for every supported
            // provider.  Keeping this boundary provider-neutral makes it
            // safe to move from a Volcengine batch to an Aliyun/Baidu backup
            // without ever replaying or dropping part of a source field.
            $result = $this->translateSegmentWithFailover(
                $accounts,
                $segment,
                $sourceLocale,
                (string) $item->target_locale,
                (string) $entry->source_hash,
                (int) $item->id,
            );
            if ($result['state'] === 'exhausted') {
                $this->commitSuccessfulAttempts($successful);
                if ($successful === []) {
                    // No provider has received this field, so pausing leaves
                    // it safe to resume when an operator tops up or enables a
                    // further free provider.
                    $this->releaseItem((int) $item->id, $runToken);
                    $this->setJobStatus((int) $job->id, 'paused');
                } else {
                    // Earlier segments may already have been accepted. Never
                    // requeue the whole field and risk charging them twice.
                    $this->finishItem($item->id, 'failed', $runToken, 'FREE_QUOTA_EXHAUSTED_AFTER_PARTIAL_SEGMENT');
                }

                return;
            }
            if ($result['state'] !== 'success') {
                $this->commitSuccessfulAttempts($successful);
                $this->finishItem($item->id, 'failed', $runToken, (string) ($result['failure_code'] ?? 'PROCESSING_EXCEPTION'));

                return;
            }
            $translatedSegments[] = $result['translation'];
            $successful[]         = $result['attempt'];
            $providersUsed[]      = $result['provider'];
            $accountsUsed[]       = $result['account_id'];
        }

        try {
            // Protected placeholders may be distributed across batches. Join
            // the full provider response first, then restore and verify each
            // token exactly once. Restoring batch-by-batch falsely fails when
            // another batch owns a protected token.
            $translated    = TextContract::restore(implode('', $translatedSegments), $prepared['tokens']);
            $providersUsed = array_values(array_unique($providersUsed));
            $accountsUsed  = array_values(array_unique($accountsUsed));
            $providerLabel = count($providersUsed) === 1 ? $providersUsed[0] : 'multi_provider';
            $accountId     = count($accountsUsed)  === 1 ? $accountsUsed[0] : null;
            DB::transaction(function () use ($successful, $entry, $item, $translated, $providerLabel, $accountId): void {
                $this->library->saveMachineResult((int) $entry->id, (string) $item->target_locale, (string) $entry->source_hash, $translated, $providerLabel, $accountId);
                foreach ($successful as $attempt) {
                    $this->ledger->commit((int) $attempt['attempt_id'], (string) $attempt['request_id']);
                }
            }, 3);
        } catch (Throwable $exception) {
            // The provider has already accepted each successful batch. Commit
            // its durable cost even if a stale source or token integrity check
            // prevents a dictionary write. This blocks an unsafe automatic
            // retry and leaves a clear failed job item for an operator.
            $this->commitSuccessfulAttempts($successful);
            $this->finishItem($item->id, 'failed', $runToken, $this->failureCode($exception));

            return;
        }
        $this->finishItem($item->id, 'completed', $runToken);
    }

    /**
     * Send one provider-neutral segment through verified accounts in priority
     * order. Only a local cap or an explicit, pre-translation account-capacity
     * response may fail over; timeouts and unknown responses remain pinned to
     * their original account so a retry cannot create duplicate billable work.
     *
     * @param list<object> $accounts
     * @return array{state:'success',translation:string,attempt:array{attempt_id:int,request_id:string},provider:string,account_id:int}|array{state:'exhausted'}|array{state:'failed',failure_code:string}
     */
    private function translateSegmentWithFailover(array $accounts, string $segment, string $sourceLocale, string $targetLocale, string $sourceHash, int $jobItemId): array
    {
        $units = TextContract::units($segment)['codepoints'];
        foreach ($accounts as $account) {
            try {
                $reservation = $this->ledger->reserve((int) $account->id, $units, $jobItemId);
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() === 'LOCAL_BUDGET_EXHAUSTED') {
                    continue;
                }

                throw $exception;
            }
            DB::table('lgt_job_items')->where('id', $jobItemId)->update(['attempt_id' => $reservation['id'], 'updated_at' => now()]);
            $requestMarkedSending = false;

            try {
                $provider = $this->providers->get((string) $account->provider);
                $this->ledger->markSending($reservation['id']);
                $requestMarkedSending = true;
                $response             = $provider->send(new TranslationProviderRequest(
                    $reservation['request_uuid'], (int) $account->id, $sourceLocale, $targetLocale, [$segment], $sourceHash,
                ), $this->accounts->credentials($account));
                if ($response->ok && count($response->translations) === 1 && is_string($response->translations[0]) && trim($response->translations[0]) !== '') {
                    return [
                        'state'       => 'success',
                        'translation' => $response->translations[0],
                        'attempt'     => ['attempt_id' => $reservation['id'], 'request_id' => (string) ($response->requestId ?? '')],
                        'provider'    => (string) $account->provider,
                        'account_id'  => (int) $account->id,
                    ];
                }
                $failure = $provider->classifyFailure($response);
                if ($failure['definitely_unbilled']) {
                    $this->ledger->definiteFailure($reservation['id'], $failure['code']);
                    if (in_array($failure['code'], ['BALANCE_EXHAUSTED', 'NOT_OPENED'], true)) {
                        continue;
                    }
                } else {
                    $this->ledger->uncertain($reservation['id'], $failure['code']);
                }

                return ['state' => 'failed', 'failure_code' => $failure['code']];
            } catch (Throwable $exception) {
                // Once markSending succeeds, an exception might have occurred
                // after the provider received bytes. Before it succeeds no
                // external request exists, so release the reservation instead
                // of stranding free quota in a permanent reserved state.
                $failure = isset($provider)
                    ? $provider->classifyFailure($exception)
                    : ['code' => 'PRE_SEND_FAILURE', 'definitely_unbilled' => true];
                try {
                    if ($requestMarkedSending) {
                        $this->ledger->uncertain($reservation['id'], $failure['definitely_unbilled'] ? 'WORKER_EXCEPTION' : $failure['code']);
                    } else {
                        $this->ledger->definiteFailure($reservation['id'], $failure['definitely_unbilled'] ? $failure['code'] : 'PRE_SEND_FAILURE');
                    }
                } catch (Throwable) {
                    // Preserve the original error state; a later operator can
                    // reconcile the durable sending attempt.
                }

                return ['state' => 'failed', 'failure_code' => $this->safeFailureCode((string) $failure['code'])];
            }
        }

        return ['state' => 'exhausted'];
    }

    /** @param list<array{attempt_id:int,request_id:string}> $attempts */
    private function commitSuccessfulAttempts(array $attempts): void
    {
        foreach ($attempts as $attempt) {
            try {
                $this->ledger->commit((int) $attempt['attempt_id'], (string) $attempt['request_id']);
            } catch (Throwable) {
                // It may already be committed by the atomic happy-path
                // transaction. Do not hide the original provider outcome.
            }
        }
    }

    /** @return array<string, string> Exact source term => protected replacement. */
    private function protectedTerms(string $sourceLocale, string $targetLocale): array
    {
        return DB::table('lgt_glossary_terms')
            ->where('source_locale', $sourceLocale)
            ->where('protect', true)
            ->where(static function ($query) use ($targetLocale): void {
                $query->whereNull('target_locale')->orWhere('target_locale', $targetLocale);
            })
            // LENGTH is available in both MySQL and the SQLite test harness.
            ->orderByRaw('LENGTH(term) DESC')
            ->limit(200)
            ->get(['term', 'replacement'])
            ->reduce(static function (array $terms, object $row): array {
                $term = trim((string) $row->term);
                if ($term !== '') {
                    $replacement  = trim((string) ($row->replacement ?? ''));
                    $terms[$term] = $replacement !== '' ? $replacement : $term;
                }

                return $terms;
            }, []);
    }

    /** @return list<object> */
    private function accountsFor(string $source, string $target): array
    {
        $accounts = [];
        foreach (DB::table('lgt_provider_accounts')->where('owner_scope', 'tenant')->where('enabled', true)->where('dispatch_enabled', true)->whereNotNull('verified_at')->orderBy('failover_priority')->orderBy('id')->get() as $account) {
            if ($this->providers->get((string) $account->provider)->supports($source, $target)) {
                $accounts[] = $account;
            }
        }

        return $accounts;
    }

    private function claim(int $jobId, string $runToken): ?object
    {
        return DB::transaction(function () use ($jobId, $runToken): ?object {
            $job = DB::table('lgt_jobs')->where('id', $jobId)->lockForUpdate()->first();
            if ($job === null || $job->status !== 'running') {
                return null;
            }
            $item = DB::table('lgt_job_items')->where('job_id', $jobId)->where('status', 'ready')->orderBy('id')->lockForUpdate()->first();
            if ($item === null) {
                return null;
            }
            $updated = DB::table('lgt_job_items')->where('id', $item->id)->where('status', 'ready')->where('version', $item->version)->update([
                'status' => 'claimed', 'claim_token' => $runToken, 'claimed_at' => now(), 'version' => (int) $item->version + 1, 'updated_at' => now(),
            ]);

            return $updated === 1 ? (object) [...(array) $item, 'claim_token' => $runToken, 'version' => (int) $item->version + 1] : null;
        }, 3);
    }

    private function releaseItem(int $itemId, string $runToken): void
    {
        DB::table('lgt_job_items')->where('id', $itemId)->where('status', 'claimed')->where('claim_token', $runToken)->update([
            'status' => 'ready', 'claim_token' => null, 'claimed_at' => null, 'updated_at' => now(),
        ]);
    }

    private function finishItem(int $itemId, string $status, string $runToken, ?string $failureCode = null): void
    {
        $failureCode = $status === 'failed' ? $this->safeFailureCode($failureCode ?: 'PROCESSING_EXCEPTION') : null;
        DB::transaction(function () use ($itemId, $status, $runToken, $failureCode): void {
            $item = DB::table('lgt_job_items')->where('id', $itemId)->lockForUpdate()->first();
            if ($item === null || $item->status !== 'claimed' || ! hash_equals((string) $item->claim_token, $runToken)) {
                return;
            }
            DB::table('lgt_job_items')->where('id', $itemId)->update([
                'status'       => $status,
                'failure_code' => $failureCode,
                'claim_token'  => null,
                'completed_at' => now(),
                'version'      => (int) $item->version + 1,
                'updated_at'   => now(),
            ]);
            $job = DB::table('lgt_jobs')->where('id', $item->job_id)->lockForUpdate()->first();
            DB::table('lgt_jobs')->where('id', $item->job_id)->update([
                'completed_items' => (int) $job->completed_items + 1,
                'failed_items'    => (int) $job->failed_items    + ($status === 'failed' ? 1 : 0),
                'updated_at'      => now(),
            ]);
        }, 3);
    }

    private function failureCode(Throwable $exception): string
    {
        return $this->safeFailureCode($exception->getMessage());
    }

    private function safeFailureCode(string $code): string
    {
        $code = strtoupper(trim($code));

        return preg_match('/^[A-Z0-9_]{3,96}$/', $code) === 1 ? $code : 'PROCESSING_EXCEPTION';
    }

    private function finishIfTerminal(int $jobId): void
    {
        $job = DB::table('lgt_jobs')->where('id', $jobId)->first();
        // Paused work has no dispatchable account (or mode) and must wait for
        // an explicit operator action; re-queuing here would create a hot
        // loop that continually burns workers without translating anything.
        if ($job === null || $job->status !== 'running') {
            return;
        }
        $pending = DB::table('lgt_job_items')->where('job_id', $jobId)->whereIn('status', ['ready', 'claimed'])->exists();
        if ($pending) {
            ProcessTranslationJob::dispatch($jobId)->afterCommit();

            return;
        }
        $this->setJobStatus($jobId, (int) ($job->failed_items ?? 0) > 0 ? 'partial_failed' : 'completed', true);
    }

    private function setJobStatus(int $jobId, string $status, bool $finished = false): void
    {
        DB::table('lgt_jobs')->where('id', $jobId)->update(['status' => $status, 'started_at' => $status === 'running' ? now() : DB::raw('started_at'), 'finished_at' => $finished ? now() : DB::raw('finished_at'), 'updated_at' => now()]);
    }
}
