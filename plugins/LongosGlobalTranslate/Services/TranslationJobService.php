<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Plugin\LongosGlobalTranslate\Jobs\ProcessTranslationJob;
use Plugin\LongosGlobalTranslate\Jobs\ResumeSiliconFlowTranslationJob;
use Plugin\LongosGlobalTranslate\Providers\ProviderRegistry;
use Plugin\LongosGlobalTranslate\Providers\SiliconFlowProvider;
use Plugin\LongosGlobalTranslate\Providers\TranslationProviderRequest;
use RuntimeException;
use Throwable;

final class TranslationJobService
{
    /** @var array<string, array<string, string>> */
    private array $protectedTermsCache = [];

    /** @var array<string, list<object>> */
    private array $accountsCache = [];

    /** @var array<string, string> */
    private array $translationMemoryCache = [];

    /**
     * These are explicit, pre-translation provider replies. They are safe to
     * try on the next verified account because no translation result was
     * accepted by the current provider.
     */
    private const FAILOVER_DEFINITE_FAILURES = [
        'AUTH', 'NOT_OPENED', 'BALANCE_EXHAUSTED', 'UNSUPPORTED_LANGUAGE', 'RATE_LIMIT', 'NETWORK_CONNECT_FAILED',
    ];

    /**
     * Only rows with one of these item codes *and* a definite-failed ledger
     * attempt can be manually retried. Never replay a timeout, a provider
     * response with uncertain delivery, or an accepted result that failed a
     * local quality/token check.
     */
    private const RETRYABLE_DEFINITE_FAILURES = [
        'AUTH', 'NOT_OPENED', 'BALANCE_EXHAUSTED', 'UNSUPPORTED_LANGUAGE', 'RATE_LIMIT', 'PRE_SEND_FAILURE', 'NETWORK_CONNECT_FAILED',
    ];

    /**
     * Failover is also safe before `markSending()` when an account cannot be
     * decrypted, does not have the required key shape, or is blocked by the
     * local rate limiter. No provider has received bytes in these branches.
     */
    private const FAILOVER_PRE_SEND_FAILURES = [
        'PROVIDER_CREDENTIALS_MISSING', 'PROVIDER_CREDENTIALS_UNREADABLE', 'INVALID_PROVIDER_CREDENTIALS',
        'UNSUPPORTED_LANGUAGE', 'PROVIDER_RATE_LIMIT_CLOCK_SKEW', 'PROVIDER_RATE_LIMIT_WAIT_INTERRUPTED',
    ];

    private const SUSPEND_PRE_SEND_ACCOUNT_FAILURES = [
        'PROVIDER_CREDENTIALS_MISSING', 'PROVIDER_CREDENTIALS_UNREADABLE', 'INVALID_PROVIDER_CREDENTIALS',
    ];

    public function __construct(
        private readonly ContentScanner $scanner,
        private readonly TranslationSettings $settings,
        private readonly ProviderAccountService $accounts,
        private readonly ProviderRegistry $providers,
        private readonly UsageLedger $ledger,
        private readonly TranslationLibrary $library,
        private readonly ?PublicationService $publications = null,
        private readonly ?SiliconFlowRateLimiter $siliconFlowLimits = null,
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
        $kinds = array_values(array_intersect(['product', 'category', 'page', 'page_category', 'builder', ContentScanner::CHROME_KIND, CommerceUiCatalog::KIND], $kinds));
        if ($locales === [] || $kinds === []) {
            throw new RuntimeException('EMPTY_TRANSLATION_SCOPE');
        }
        // Only the explicit administrator action can scan new content or
        // enqueue billable provider requests; storefront reads never do so.
        $report = $this->scanner->scan($kinds, $actorId);
        $result = DB::transaction(function () use ($locales, $kinds, $settings, $actorId): array {
            TranslationMaintenanceGuard::lockLocales($locales);
            $existing = DB::table('lgt_jobs')
                ->whereIn('status', ['planned', 'queued', 'scanning', 'running', 'pausing', 'paused'])
                ->where('scope', json_encode($kinds))
                ->where('target_locales', json_encode($locales))
                ->where('mode', $settings['mode'])
                ->lockForUpdate()
                ->first();

            $jobId = $existing?->id;
            if ($jobId === null) {
                $jobId = DB::table('lgt_jobs')->insertGetId([
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
            }
            $newItems           = 0;
            $reusedTranslations = 0;
            $alreadyQueued      = 0;
            $deferredChanges    = 0;

            // Bounded iteration: never load the entire merchant catalog and
            // 24 target-language cross-product into PHP memory. Queue only
            // missing/stale translations, preserving approved & human edits.
            DB::table('lgt_entries')->whereIn('kind', $kinds)
                ->where('is_public', true)->where('is_tombstoned', false)
                ->orderBy('id')->chunkById(200, function ($entries) use (
                    $jobId, $locales, &$newItems, &$reusedTranslations, &$alreadyQueued, &$deferredChanges
                ): void {
                    $entryIds = $entries->pluck('id')->all();
                    $saved    = DB::table('lgt_translations')->whereIn('entry_id', $entryIds)
                        ->whereIn('locale', $locales)
                        ->get(['entry_id', 'locale', 'source_hash', 'text', 'status', 'locked']);
                    $savedByPair = [];
                    foreach ($saved as $translation) {
                        $savedByPair[(int) $translation->entry_id . ':' . $translation->locale] = $translation;
                    }
                    $jobItems = DB::table('lgt_job_items')->where('job_id', $jobId)
                        ->whereIn('entry_id', $entryIds)->whereIn('target_locale', $locales)
                        ->get(['entry_id', 'target_locale', 'source_hash']);
                    $queuedByPair = [];
                    foreach ($jobItems as $item) {
                        $queuedByPair[(int) $item->entry_id . ':' . $item->target_locale] = (string) $item->source_hash;
                    }
                    // A different scope creates a different job. The old
                    // planner only checked this job's items, so overlapping
                    // product/full-site jobs could dispatch the same pair
                    // twice. Locale maintenance locks serialize planners;
                    // active items from *other* jobs must also be considered.
                    $otherActiveByPair = [];
                    $otherActive       = DB::table('lgt_job_items as prior_item')
                        ->join('lgt_jobs as prior_job', 'prior_job.id', '=', 'prior_item.job_id')
                        ->whereIn('prior_item.entry_id', $entryIds)
                        ->whereIn('prior_item.target_locale', $locales)
                        ->where('prior_item.job_id', '!=', $jobId)
                        ->whereIn('prior_item.status', ['ready', 'claimed'])
                        ->whereIn('prior_job.status', ['queued', 'scanning', 'running', 'pausing', 'paused'])
                        ->where('prior_job.mode', TranslationSettings::MODE_FREE_ONLY)
                        ->get(['prior_item.entry_id', 'prior_item.target_locale', 'prior_item.source_hash']);
                    foreach ($otherActive as $priorItem) {
                        $otherActiveByPair[(int) $priorItem->entry_id . ':' . $priorItem->target_locale . ':' . $priorItem->source_hash] = true;
                    }
                    // A task may be recreated after an operator fixes a
                    // provider. Do not silently replay an unchanged source
                    // that has an unresolved request from *another* task:
                    // it could already have been accepted and billed.
                    $uncertainByPair   = [];
                    $uncertainAttempts = DB::table('lgt_attempts as attempt')
                        ->join('lgt_job_items as prior_item', 'prior_item.id', '=', 'attempt.job_item_id')
                        ->whereIn('prior_item.entry_id', $entryIds)
                        ->whereIn('prior_item.target_locale', $locales)
                        ->where(static function ($query): void {
                            $query->whereIn('attempt.state', [UsageLedger::SENDING, UsageLedger::UNCERTAIN])
                                ->orWhere(static function ($committed): void {
                                    // A committed request followed by a
                                    // failed local write must not be rebilled.
                                    $committed->where('attempt.state', UsageLedger::COMMITTED)
                                        ->where('prior_item.status', 'failed');
                                });
                        })
                        ->get(['prior_item.entry_id', 'prior_item.target_locale', 'prior_item.source_hash']);
                    foreach ($uncertainAttempts as $attempt) {
                        $uncertainByPair[(int) $attempt->entry_id . ':' . $attempt->target_locale . ':' . $attempt->source_hash] = true;
                    }
                    $rows = [];
                    foreach ($entries as $entry) {
                        foreach ($locales as $locale) {
                            $pair                = (int) $entry->id . ':' . $locale;
                            $translation         = $savedByPair[$pair] ?? null;
                            $translationReusable = $translation !== null;
                            if ($translationReusable) {
                                $translationReusable = hash_equals((string) $entry->source_hash, (string) $translation->source_hash)
                                    && trim((string) $translation->text) !== ''
                                    && in_array((string) $translation->status, [TranslationLibrary::TRANSLATED, TranslationLibrary::NEEDS_REVIEW, TranslationLibrary::APPROVED], true);
                            }
                            if ($translationReusable) {
                                $reusedTranslations++;

                                continue;
                            }
                            // A locked source requires deliberate human action;
                            // a provider result would be discarded anyway.
                            if ($translation !== null && (bool) $translation->locked) {
                                $deferredChanges++;

                                continue;
                            }
                            if (isset($uncertainByPair[$pair . ':' . $entry->source_hash])) {
                                $deferredChanges++;

                                continue;
                            }
                            if (isset($otherActiveByPair[$pair . ':' . $entry->source_hash])) {
                                $deferredChanges++;

                                continue;
                            }
                            if (array_key_exists($pair, $queuedByPair)) {
                                // Never rewrite an existing job item or its
                                // billing/claim history when source changes.
                                if (! hash_equals($queuedByPair[$pair], (string) $entry->source_hash)) {
                                    $deferredChanges++;
                                } else {
                                    $alreadyQueued++;
                                }

                                continue;
                            }
                            $rows[] = [
                                'job_id'      => $jobId, 'entry_id' => $entry->id, 'target_locale' => $locale,
                                'source_hash' => $entry->source_hash, 'status' => 'ready', 'version' => 1,
                                'created_at'  => now(), 'updated_at' => now(),
                            ];
                        }
                    }
                    // Keep a safe bound for SQLite and MySQL parameter limits.
                    foreach (array_chunk($rows, 100) as $batch) {
                        $newItems += DB::table('lgt_job_items')->insertOrIgnore($batch);
                    }
                }, 'id');

            $total   = DB::table('lgt_job_items')->where('job_id', $jobId)->count();
            $updates = ['total_items' => $total, 'updated_at' => now()];
            if ($existing === null && $total === 0) {
                $updates += ['status' => 'completed', 'finished_at' => now()];
            }
            DB::table('lgt_jobs')->where('id', $jobId)->update($updates);
            $job            = (array) DB::table('lgt_jobs')->where('id', $jobId)->first();
            $job['created'] = $existing === null;

            return [
                'job'        => $job,
                'statistics' => [
                    'new_items'              => $newItems,
                    'reused_translations'    => $reusedTranslations,
                    'already_in_job'         => $alreadyQueued,
                    'needs_manual_attention' => $deferredChanges,
                ],
            ];
        }, 3);
        $job            = $result['job'];
        $shouldDispatch = $job['created']
            && $job['mode'] === TranslationSettings::MODE_FREE_ONLY
            && (int) $job['total_items'] > 0;
        $shouldResume = ! $job['created']
            && $job['status'] === 'paused'
            && $job['mode']   === TranslationSettings::MODE_FREE_ONLY;
        if ($shouldDispatch) {
            $this->dispatchWorkerPool((int) $job['id']);
        } elseif ($shouldResume) {
            $this->resumePausedJobs();
        }

        return [...$result, 'coverage' => $report];
    }

    public function process(int $jobId, int $limit = 50, ?string $runToken = null): void
    {
        // Cache only immutable/read-mostly routing data for this bounded queue
        // run. A fresh worker job reloads provider and glossary changes.
        $this->protectedTermsCache    = [];
        $this->accountsCache          = [];
        $this->translationMemoryCache = [];
        $runToken                     = $runToken ?: (string) Str::uuid();
        $job                          = DB::table('lgt_jobs')->where('id', $jobId)->first();
        if ($job === null || in_array($job->status, ['cancelled', 'completed', 'partial_failed', 'paused'], true)) {
            return;
        }
        $settings = $this->settings->all();
        if ($settings['mode'] !== TranslationSettings::MODE_FREE_ONLY || $job->mode !== TranslationSettings::MODE_FREE_ONLY) {
            $this->pauseJob($jobId, 'DISPATCH_MODE_CHANGED');

            return;
        }
        if (! $this->setJobStatus($jobId, 'running')) {
            return;
        }
        for ($i = 0; $i < min(50, max(1, $limit)); $i++) {
            $item = $this->claim($jobId, $runToken);
            if ($item === null) {
                break;
            }

            // The operator can cancel between claim() and the provider call.
            if (DB::table('lgt_jobs')->where('id', $jobId)->value('status') !== 'running') {
                $this->settleInterruptedItem((int) $item->id, $runToken);

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
            // Claim/cancel/recovery all lock the job before its items.
            $job = DB::table('lgt_jobs')->where('id', $jobId)->lockForUpdate()->first();
            if ($job === null) {
                return [];
            }
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
                DB::table('lgt_jobs')->where('id', $jobId)->update([
                    'completed_items' => (int) $job->completed_items + $items->count(),
                    'failed_items'    => (int) $job->failed_items    + $items->count(),
                    'updated_at'      => now(),
                ]);
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
            $jobs = DB::table('lgt_jobs')->where('status', 'paused')->whereNull('archived_at')->where('mode', TranslationSettings::MODE_FREE_ONLY)->lockForUpdate()->get(['id']);
            if ($jobs->isEmpty()) {
                return [];
            }
            $ids = $jobs->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            DB::table('lgt_jobs')->whereIn('id', $ids)->update(['status' => 'queued', 'updated_at' => now()]);

            return $ids;
        }, 3);
        foreach ($ids as $id) {
            $this->dispatchWorkerPool($id);
        }

        return count($ids);
    }

    /**
     * Stop future claims and queued work without discarding billing evidence.
     * A provider call that was already sent cannot be recalled; its claimed
     * item finishes normally, but cancelled jobs never auto-publish or resume.
     *
     * @return array{status:string,discarded_ready:int,inflight:int}
     */
    public function cancelJob(int $jobId, ?int $actorId): array
    {
        return DB::transaction(function () use ($jobId, $actorId): array {
            $job = DB::table('lgt_jobs')->where('id', $jobId)->lockForUpdate()->first();
            if ($job === null || $job->archived_at !== null) {
                throw new RuntimeException('JOB_NOT_FOUND');
            }
            if (in_array((string) $job->status, ['completed', 'partial_failed', 'failed'], true)) {
                throw new RuntimeException('JOB_ALREADY_FINISHED');
            }
            if ($job->status === 'cancelled') {
                return [
                    'status'   => 'cancelled', 'discarded_ready' => 0,
                    'inflight' => DB::table('lgt_job_items')->where('job_id', $jobId)->where('status', 'claimed')->count(),
                ];
            }
            $discarded = DB::table('lgt_job_items')->where('job_id', $jobId)->where('status', 'ready')->update([
                'status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now(),
            ]);
            $inflight = DB::table('lgt_job_items')->where('job_id', $jobId)->where('status', 'claimed')->count();
            $plan     = json_decode((string) ($job->plan ?? ''), true);
            $plan     = is_array($plan) ? $plan : [];
            unset($plan['pause']);
            $plan['cancellation'] = ['at' => now()->toIso8601String(), 'actor_id' => $actorId, 'inflight_at_cancel' => $inflight];
            DB::table('lgt_jobs')->where('id', $jobId)->update([
                'status'      => 'cancelled', 'plan' => json_encode($plan, JSON_UNESCAPED_UNICODE),
                'finished_at' => now(), 'updated_at' => now(),
            ]);
            $this->auditJob('translation_job_cancelled', $jobId, $actorId, ['status' => $job->status], ['discarded_ready' => $discarded, 'inflight' => $inflight]);

            return ['status' => 'cancelled', 'discarded_ready' => $discarded, 'inflight' => $inflight];
        }, 3);
    }

    /**
     * 'Delete' removes the task from the operational list, but preserves
     * attempts, claims and billing evidence. An in-flight call must settle.
     */
    public function archiveJob(int $jobId, ?int $actorId): void
    {
        DB::transaction(function () use ($jobId, $actorId): void {
            $job = DB::table('lgt_jobs')->where('id', $jobId)->lockForUpdate()->first();
            if ($job === null || $job->archived_at !== null) {
                throw new RuntimeException('JOB_NOT_FOUND');
            }
            if (! in_array((string) $job->status, ['completed', 'partial_failed', 'failed', 'cancelled'], true)) {
                throw new RuntimeException('JOB_MUST_BE_STOPPED');
            }
            if (DB::table('lgt_job_items')->where('job_id', $jobId)->where('status', 'claimed')->exists()) {
                throw new RuntimeException('JOB_HAS_INFLIGHT_REQUESTS');
            }
            DB::table('lgt_jobs')->where('id', $jobId)->update(['archived_at' => now(), 'updated_at' => now()]);
            $this->auditJob('translation_job_archived', $jobId, $actorId, ['status' => $job->status], ['archived' => true]);
        }, 3);
    }

    /**
     * Manual crash recovery for a cancelled job only. Waiting at least 30
     * minutes exceeds the normal ten-minute queue timeout and avoids treating
     * a still-running provider call as definitely failed. Ledger recovery
     * retains uncertain billing; it never resends the request.
     *
     * @return array{recovered:int,remaining:int}
     */
    public function recoverTimedOutClaims(int $jobId, ?int $actorId): array
    {
        $claims = DB::transaction(function () use ($jobId): array {
            $job = DB::table('lgt_jobs')->where('id', $jobId)->lockForUpdate()->first();
            if ($job === null || $job->archived_at !== null || $job->status !== 'cancelled') {
                throw new RuntimeException('JOB_MUST_BE_STOPPED');
            }
            $claims = DB::table('lgt_job_items')->where('job_id', $jobId)->where('status', 'claimed')
                ->lockForUpdate()->get(['claim_token', 'claimed_at']);
            foreach ($claims as $claim) {
                if (! $claim->claim_token || $claim->claimed_at === null
                                          || (string) $claim->claimed_at > now()->subMinutes(30)->toDateTimeString()) {
                    throw new RuntimeException('JOB_CLAIMS_NOT_STALE');
                }
            }

            return ['tokens' => $claims->pluck('claim_token')->unique()->values()->all(), 'count' => $claims->count()];
        }, 3);

        foreach ($claims['tokens'] as $token) {
            $this->failClaimsForRun($jobId, (string) $token);
        }
        $remaining = DB::table('lgt_job_items')->where('job_id', $jobId)->where('status', 'claimed')->count();
        $result    = ['recovered' => max(0, $claims['count'] - $remaining), 'remaining' => $remaining];
        $this->auditJob('translation_job_claims_recovered', $jobId, $actorId, [], $result);

        return $result;
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private function auditJob(string $action, int $jobId, ?int $actorId, array $before, array $after): void
    {
        DB::table('lgt_audit_logs')->insert([
            'actor_id'   => $actorId, 'action' => $action, 'subject_type' => 'job', 'subject_id' => $jobId,
            'before'     => json_encode($before, JSON_UNESCAPED_UNICODE),
            'after'      => json_encode($after, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Explicitly requeue only work proven not to have produced a billable
     * provider request. This gives an operator a recovery path after fixing
     * credentials, enabling a service, or adding a backup provider, without
     * turning unknown outcomes into duplicate external requests.
     */
    public function retryDefiniteFailures(int $jobId): int
    {
        $count = DB::transaction(function () use ($jobId): int {
            $job = DB::table('lgt_jobs')->where('id', $jobId)->lockForUpdate()->first();
            if ($job === null || $job->archived_at !== null || ! in_array((string) $job->status, ['completed', 'partial_failed'], true)) {
                throw new RuntimeException('JOB_NOT_RETRYABLE');
            }
            $items = DB::table('lgt_job_items as item')
                ->join('lgt_attempts as attempt', 'attempt.id', '=', 'item.attempt_id')
                ->where('item.job_id', $jobId)
                ->where('item.status', 'failed')
                ->whereIn('item.failure_code', self::RETRYABLE_DEFINITE_FAILURES)
                ->where('attempt.state', UsageLedger::DEFINITE_FAILED)
                ->lockForUpdate()
                ->get(['item.id', 'item.version']);
            if ($items->isEmpty()) {
                return 0;
            }
            foreach ($items as $item) {
                DB::table('lgt_job_items')->where('id', $item->id)->where('status', 'failed')->update([
                    'status'       => 'ready',
                    'failure_code' => null,
                    'attempt_id'   => null,
                    'completed_at' => null,
                    'claim_token'  => null,
                    'claimed_at'   => null,
                    'version'      => (int) $item->version + 1,
                    'updated_at'   => now(),
                ]);
            }
            $count = $items->count();
            DB::table('lgt_jobs')->where('id', $jobId)->update([
                'status'          => 'queued',
                'completed_items' => max(0, (int) $job->completed_items - $count),
                'failed_items'    => max(0, (int) $job->failed_items - $count),
                'finished_at'     => null,
                'updated_at'      => now(),
            ]);

            return $count;
        }, 3);
        if ($count > 0) {
            $this->dispatchWorkerPool($jobId);
        }

        return $count;
    }

    public function retryableFailureCount(int $jobId): int
    {
        return DB::table('lgt_job_items as item')
            ->join('lgt_attempts as attempt', 'attempt.id', '=', 'item.attempt_id')
            ->where('item.job_id', $jobId)
            ->where('item.status', 'failed')
            ->whereIn('item.failure_code', self::RETRYABLE_DEFINITE_FAILURES)
            ->where('attempt.state', UsageLedger::DEFINITE_FAILED)
            ->count();
    }

    private function processItem(object $job, object $item, string $sourceLocale, string $runToken): void
    {
        $entry = DB::table('lgt_entries')->where('id', $item->entry_id)->first();
        if ($entry === null || ! $entry->is_public || $entry->is_tombstoned || ! hash_equals((string) $entry->source_hash, (string) $item->source_hash)) {
            $this->finishItem($item->id, 'stale', $runToken);

            return;
        }
        // Never bill twice for an unchanged, already saved translation.
        // Existing published/review-pending text stays under the original
        // review and version contracts; a new task is idempotent here.
        $existing = DB::table('lgt_translations')
            ->where('entry_id', $entry->id)->where('locale', $item->target_locale)->first();
        $existingReusable = $existing !== null;
        if ($existingReusable) {
            $existingReusable = hash_equals((string) $entry->source_hash, (string) $existing->source_hash)
                && trim((string) $existing->text) !== ''
                && in_array((string) $existing->status, [TranslationLibrary::TRANSLATED, TranslationLibrary::NEEDS_REVIEW, TranslationLibrary::APPROVED], true);
        }
        if ($existingReusable) {
            $this->finishItem((int) $item->id, 'completed', $runToken);

            return;
        }
        if ($existing !== null && (bool) $existing->locked) {
            // Locked human edits cannot be overwritten by machine output;
            // don't spend provider quota on a result the library would drop.
            $this->finishItem((int) $item->id, 'failed', $runToken, 'LOCKED_TRANSLATION_REQUIRES_REVIEW');

            return;
        }

        // Legacy queued jobs may overlap even after the new planner has been
        // deployed. Do not replay an unresolved send, or a provider result
        // already billed but lost by a failed local write. An administrator
        // must reconcile these explicitly before requesting new translation.
        $priorRisk = DB::table('lgt_attempts as attempt')
            ->join('lgt_job_items as prior_item', 'prior_item.id', '=', 'attempt.job_item_id')
            ->where('prior_item.entry_id', $item->entry_id)
            ->where('prior_item.target_locale', $item->target_locale)
            ->where('prior_item.source_hash', $item->source_hash)
            ->where('prior_item.id', '!=', $item->id)
            ->where(static function ($query): void {
                $query->whereIn('attempt.state', [UsageLedger::SENDING, UsageLedger::UNCERTAIN])
                    ->orWhere(static function ($committed): void {
                        $committed->where('attempt.state', UsageLedger::COMMITTED)
                            ->where('prior_item.status', 'failed');
                    });
            })->exists();
        if ($priorRisk) {
            $this->finishItem((int) $item->id, 'failed', $runToken, 'PRIOR_BILLING_OUTCOME_UNRESOLVED');

            return;
        }

        // Validate the exact text contract before a provider reservation is
        // made.  The scanner should already have reduced rich content to
        // visible text nodes, but this second boundary keeps malformed legacy
        // values, pasted markup and unsafe control content out of billable
        // requests instead of discovering them after a provider reply.
        try {
            TextContract::assertSafePlainText((string) $entry->source_text);
            $planParts = TextContract::translationPlan(
                (string) $entry->source_text,
                $this->protectedTerms($sourceLocale, (string) $item->target_locale),
            );
            $segments        = [];
            $translatedParts = [];
            $partSuffixes    = [];
            foreach ($planParts as $partIndex => $part) {
                if (! $part['translate'] || trim($part['text']) === '') {
                    $translatedParts[$partIndex] = $part['text'];

                    continue;
                }
                preg_match('~^(\s*)(.*?)(\s*)$~us', $part['text'], $whitespace);
                $core                        = (string) ($whitespace[2] ?? $part['text']);
                $translatedParts[$partIndex] = (string) ($whitespace[1] ?? '');
                $partSuffixes[$partIndex]    = (string) ($whitespace[3] ?? '');
                foreach (TextContract::segments($core, 4500, 4800) as $segment) {
                    $segments[] = ['part' => $partIndex, 'text' => $segment];
                }
            }
        } catch (Throwable $exception) {
            // No account has been reserved and no provider has received this
            // text. This is a source-index issue, not a billable translation
            // failure, and is safe to surface without any retry caveat.
            $sourceFailure = in_array($exception->getMessage(), ['UNSAFE_TRANSLATION', 'INVALID_TRANSLATION_TEXT'], true)
                ? 'SOURCE_TEXT_REJECTED'
                : $this->failureCode($exception);
            $this->finishItem((int) $item->id, 'failed', $runToken, $sourceFailure);

            return;
        }

        // Reuse an already accepted machine translation only when the
        // source locale, target locale, content kind, field, source format
        // and normalized source hash all match. This is tenant-local because
        // every worker runs on the active tenant connection. It removes a
        // large amount of duplicate provider traffic for repeated storefront
        // labels without ever sharing shopper, order or payment data.
        $memoryText = $this->translationMemoryText($entry, (string) $item->target_locale);
        if ($memoryText !== null) {
            try {
                $this->library->saveMachineResult(
                    (int) $entry->id,
                    (string) $item->target_locale,
                    (string) $entry->source_hash,
                    $memoryText,
                    'translation_memory',
                    null,
                );
                $this->finishItem((int) $item->id, 'completed', $runToken);
            } catch (Throwable $exception) {
                $this->finishItem((int) $item->id, 'failed', $runToken, $this->failureCode($exception));
            }

            return;
        }
        $accounts = $segments === [] ? [] : $this->accountsFor($sourceLocale, (string) $item->target_locale);
        if ($segments === []) {
            try {
                if (! $this->jobItemStillRunning((int) $item->id)) {
                    $this->finishItem((int) $item->id, 'cancelled', $runToken);

                    return;
                }
                $translated = TextContract::assertSafePlainText(implode('', $translatedParts));
                $this->library->saveMachineResult((int) $entry->id, (string) $item->target_locale, (string) $entry->source_hash, $translated, 'local_rules', null);
                $this->rememberTranslation($entry, (string) $item->target_locale, $translated);
                $this->finishItem((int) $item->id, 'completed', $runToken);
            } catch (Throwable $exception) {
                $this->finishItem((int) $item->id, 'failed', $runToken, $this->failureCode($exception));
            }

            return;
        }
        if ($accounts === []) {
            // No account is a recoverable operator configuration state, not
            // a source failure. Put this exact item back and stop dispatching
            // until an operator explicitly enables a verified account.
            $this->releaseItem((int) $item->id, $runToken);
            $this->pauseJob((int) $job->id, $this->noDispatchAccountReason($sourceLocale, (string) $item->target_locale));

            return;
        }
        // Build the rules/terminology/context profile before any provider is
        // called. This profile is derived solely from an allowlisted public
        // field contract; it can never contain customer, order, checkout,
        // payment or visitor values.
        $translationContext = CommerceTranslationProfile::context(
            $sourceLocale,
            (string) $item->target_locale,
            (string) $entry->kind,
            (string) $entry->field,
        );
        $successful         = [];
        $providersUsed      = [];
        $accountsUsed       = [];
        foreach ($segments as $segmentPlan) {
            // A single segment is a valid request for every supported
            // provider.  Keeping this boundary provider-neutral makes it
            // safe to move from a Volcengine batch to an Aliyun/Baidu backup
            // without ever replaying or dropping part of a source field.
            $result = $this->translateSegmentWithFailover(
                $accounts,
                $segmentPlan['text'],
                $sourceLocale,
                (string) $item->target_locale,
                (string) $entry->source_hash,
                (int) $item->id,
                $translationContext,
            );
            if ($result['state'] === 'exhausted' || $result['state'] === 'rate_limited') {
                $this->commitSuccessfulAttempts($successful);
                $rateLimited = $result['state'] === 'rate_limited';
                $pauseReason = $rateLimited ? 'SILICONFLOW_RATE_WINDOW' : 'FREE_QUOTA_EXHAUSTED';
                if ($successful === []) {
                    // No segment yielded an accepted translation. A 429 may
                    // have received bytes, but the provider explicitly
                    // rejected the call without producing a translation.
                    $pauseNonce = $this->pauseJob((int) $job->id, $pauseReason);
                    // Pause first: claim() locks the job status, so no other
                    // worker can pick up this ready item during the handoff.
                    $this->releaseItem((int) $item->id, $runToken);
                    if ($pauseNonce !== null && $rateLimited) {
                        ResumeSiliconFlowTranslationJob::dispatch((int) $job->id, $pauseNonce)
                            ->delay(now()->addSeconds((int) ($result['retry_after'] ?? 65)))
                            ->afterCommit();
                    }
                } else {
                    // Earlier segments may already have been accepted. Never
                    // requeue the whole field and risk charging them twice.
                    $this->finishItem($item->id, 'failed', $runToken, $rateLimited
                        ? 'RATE_LIMIT_AFTER_PARTIAL_SEGMENT' : 'FREE_QUOTA_EXHAUSTED_AFTER_PARTIAL_SEGMENT');
                }

                return;
            }
            if ($result['state'] === 'cancelled') {
                $this->commitSuccessfulAttempts($successful);
                if ($successful === []) {
                    // Another worker can pause the shared job after this
                    // worker claims an item but before it sends anything.
                    // A paused claim is safe to return to the ready queue;
                    // an operator-cancelled claim is terminal instead.
                    $this->settleInterruptedItem((int) $item->id, $runToken);
                } else {
                    // Earlier segments were accepted by a provider. Never
                    // replay the whole field after a concurrent stop because
                    // that could duplicate billable work.
                    $this->finishItem((int) $item->id, 'failed', $runToken, 'JOB_STOPPED_AFTER_PARTIAL_SEGMENT');
                }

                return;
            }
            if ($result['state'] !== 'success') {
                $this->commitSuccessfulAttempts($successful);
                $this->finishItem($item->id, 'failed', $runToken, (string) ($result['failure_code'] ?? 'PROCESSING_EXCEPTION'));

                return;
            }
            $translatedParts[$segmentPlan['part']] .= $result['translation'];
            $successful[]         = $result['attempt'];
            $providersUsed[]      = $result['provider'];
            $accountsUsed[]       = $result['account_id'];
        }

        foreach ($partSuffixes as $partIndex => $suffix) {
            $translatedParts[$partIndex] .= $suffix;
        }
        ksort($translatedParts);

        try {
            // Links, prices, variables, e-mail addresses and protected terms
            // were never sent to a provider. They are reassembled locally in
            // their original positions, so no provider token normalisation is
            // required and the final plain-text gate remains strict.
            $translated    = TextContract::assertSafePlainText(implode('', $translatedParts));
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
            $this->rememberTranslation($entry, (string) $item->target_locale, $translated);
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
     * @return array<string,mixed>
     */
    private function translateSegmentWithFailover(array $accounts, string $segment, string $sourceLocale, string $targetLocale, string $sourceHash, int $jobItemId, array $translationContext): array
    {
        $units     = TextContract::units($segment)['codepoints'];
        $rateDelay = 0;
        foreach ($accounts as $account) {
            if (! $this->jobItemStillRunning($jobItemId)) {
                return ['state' => 'cancelled'];
            }

            try {
                $reservation = $this->ledger->reserve((int) $account->id, $units, $jobItemId);
            } catch (RuntimeException $exception) {
                if (in_array($exception->getMessage(), ['LOCAL_BUDGET_EXHAUSTED', 'PROVIDER_ACCOUNT_NOT_DISPATCHABLE'], true)) {
                    continue;
                }

                throw $exception;
            }
            DB::table('lgt_job_items')->where('id', $jobItemId)->update(['attempt_id' => $reservation['id'], 'updated_at' => now()]);
            $requestMarkedSending = false;
            $rateCallId           = null;
            $rateModel            = null;
            $limiter              = $this->siliconFlowLimits ?? new SiliconFlowRateLimiter;

            try {
                $provider = $this->providers->get((string) $account->provider);
                // A malformed or undecryptable local credential is known not
                // to have reached the provider. Validate it before moving the
                // reservation into the conservative `sending` state.
                $credentials = $this->accounts->credentials($account);
                $provider->validateCredentialShape($credentials);
                // The account rate limiter/credential lookup can be slow.
                // Re-check before crossing the external-send boundary.
                if (! $this->jobItemStillRunning($jobItemId)) {
                    $this->ledger->definiteFailure($reservation['id'], 'JOB_CANCELLED_BEFORE_SEND');

                    return ['state' => 'cancelled'];
                }
                $request = new TranslationProviderRequest(
                    $reservation['request_uuid'], (int) $account->id, $sourceLocale, $targetLocale, [$segment], $sourceHash,
                    context: $translationContext,
                );
                if ($provider instanceof SiliconFlowProvider) {
                    $rateModel = (string) $credentials['model'];
                    $admission = $limiter->acquire(
                        (int) $account->id, $rateModel, $provider->tokenCeiling($request, $rateModel),
                        (int) ($account->rpm_limit ?? 1000), (int) ($account->tpm_limit ?? 80000),
                    );
                    if (! $admission['allowed']) {
                        $rateDelay = max($rateDelay, (int) $admission['retry_after']);
                        $this->ledger->definiteFailure($reservation['id'], $admission['reason']);

                        continue;
                    }
                    $rateCallId = (int) $admission['id'];
                }
                $this->ledger->markSending($reservation['id']);
                $requestMarkedSending = true;
                $response             = $provider->send($request, $credentials);
                if ($rateCallId !== null) {
                    $limiter->settle($rateCallId, $response->totalTokens);
                    if ($response->errorCode === 'RATE_LIMIT') {
                        $rateDelay = max($rateDelay, $limiter->cooldown((string) $rateModel, $response->retryAfterSeconds));
                    } elseif ($response->ok) {
                        $limiter->success((string) $rateModel);
                    }
                }
                if ($response->ok && count($response->translations) === 1 && is_string($response->translations[0]) && trim($response->translations[0]) !== '') {
                    return [
                        'state'       => 'success',
                        'translation' => $response->translations[0],
                        'attempt'     => ['attempt_id' => $reservation['id'], 'request_id' => (string) ($response->requestId ?? '')],
                        'provider'    => (string) $account->provider,
                        'account_id'  => (int) $account->id,
                    ];
                }
                $failure     = $provider->classifyFailure($response);
                $failureCode = $this->safeFailureCode((string) $failure['code']);
                if ($failureCode === 'RATE_LIMIT' && $rateCallId !== null) {
                    $rateDelay = max($rateDelay, 60);
                }
                if ($failure['definitely_unbilled']) {
                    $this->ledger->definiteFailure($reservation['id'], $failureCode);
                    if (in_array($failureCode, ['AUTH', 'NOT_OPENED', 'BALANCE_EXHAUSTED'], true)) {
                        // Do not spend one failed request per source item on
                        // an account the provider has explicitly rejected.
                        // Re-enabling it remains an operator action.
                        $this->accounts->suspendDispatchForSafety((int) $account->id, $failureCode);
                    }
                    if (in_array($failureCode, self::FAILOVER_DEFINITE_FAILURES, true)) {
                        continue;
                    }
                } else {
                    $this->ledger->uncertain($reservation['id'], $failureCode);
                }

                return ['state' => 'failed', 'failure_code' => $failureCode];
            } catch (Throwable $exception) {
                if (! $requestMarkedSending && $rateCallId !== null) {
                    $limiter->release($rateCallId);
                }
                // Once markSending succeeds, an exception might have occurred
                // after the provider received bytes. Before it succeeds no
                // external request exists, so release the reservation instead
                // of stranding free quota in a permanent reserved state.
                $failure = isset($provider)
                    ? $provider->classifyFailure($exception)
                    : ['code' => 'PRE_SEND_FAILURE', 'definitely_unbilled' => true];
                $exceptionCode      = $this->safeFailureCode($exception->getMessage());
                $definitelyUnbilled = ($failure['kind'] ?? null) === 'definite_failure'
                    && (bool) ($failure['definitely_unbilled'] ?? false);
                $failureCode   = $requestMarkedSending
                    ? $this->safeFailureCode((string) $failure['code'])
                    // Anything raised before markSending is provably local.
                    // Keep a structured local error where available so an
                    // operator can repair credentials without guessing.
                    : ($exceptionCode === 'PROCESSING_EXCEPTION' ? 'PRE_SEND_FAILURE' : $exceptionCode);

                try {
                    if ($requestMarkedSending && ! $definitelyUnbilled) {
                        $this->ledger->uncertain($reservation['id'], $failureCode);
                    } else {
                        $this->ledger->definiteFailure($reservation['id'], $failureCode);
                    }
                } catch (Throwable) {
                    // Preserve the original error state; a later operator can
                    // reconcile the durable sending attempt.
                }

                if (! $requestMarkedSending && in_array($failureCode, self::SUSPEND_PRE_SEND_ACCOUNT_FAILURES, true)) {
                    $this->accounts->suspendDispatchForSafety((int) $account->id, $failureCode);
                }
                if ($definitelyUnbilled && in_array($failureCode, self::FAILOVER_DEFINITE_FAILURES, true)) {
                    continue;
                }
                if (! $requestMarkedSending && in_array($failureCode, self::FAILOVER_PRE_SEND_FAILURES, true)) {
                    continue;
                }

                return ['state' => 'failed', 'failure_code' => $this->safeFailureCode($failureCode)];
            }
        }

        return $rateDelay > 0
            ? ['state' => 'rate_limited', 'retry_after' => min(600, $rateDelay + 5)]
            : ['state' => 'exhausted'];
    }

    private function jobItemStillRunning(int $jobItemId): bool
    {
        return DB::table('lgt_job_items as item')->join('lgt_jobs as job', 'job.id', '=', 'item.job_id')
            ->where('item.id', $jobItemId)->where('item.status', 'claimed')
            ->where('job.status', 'running')->whereNull('job.archived_at')->exists();
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
        $cacheKey = $sourceLocale . ':' . $targetLocale;
        if (array_key_exists($cacheKey, $this->protectedTermsCache)) {
            return $this->protectedTermsCache[$cacheKey];
        }
        $tenantTerms = DB::table('lgt_glossary_terms')
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

        // The platform-maintained commerce profile provides safe defaults;
        // an explicitly saved tenant term wins on the same exact source
        // phrase. Both routes stay local and are masked before the external
        // MT call, so a provider cannot alter a protected commercial term.
        return $this->protectedTermsCache[$cacheKey] = array_replace(
            CommerceTranslationProfile::protectedTerms($sourceLocale, $targetLocale),
            $tenantTerms,
        );
    }

    private function translationMemoryText(object $entry, string $targetLocale): ?string
    {
        $key = $this->translationMemoryKey($entry, $targetLocale);
        if (array_key_exists($key, $this->translationMemoryCache)) {
            return $this->translationMemoryCache[$key];
        }

        $translation = DB::table('lgt_translations as translation')
            ->join('lgt_entries as source_entry', 'source_entry.id', '=', 'translation.entry_id')
            ->where('source_entry.id', '<>', (int) $entry->id)
            ->where('source_entry.is_public', true)
            ->where('source_entry.is_tombstoned', false)
            ->where('source_entry.source_locale', (string) $entry->source_locale)
            ->where('source_entry.source_format', (string) $entry->source_format)
            ->where('source_entry.kind', (string) $entry->kind)
            ->where('source_entry.field', (string) $entry->field)
            ->where('source_entry.source_hash', (string) $entry->source_hash)
            ->where('translation.locale', $targetLocale)
            ->whereColumn('translation.source_hash', 'source_entry.source_hash')
            ->where('translation.locked', false)
            ->whereIn('translation.status', [TranslationLibrary::APPROVED, TranslationLibrary::TRANSLATED])
            ->whereNotNull('translation.text')
            ->where('translation.text', '<>', '')
            // APPROVED sorts before TRANSLATED and uses the tenant-aware
            // query grammar instead of a raw alias that would bypass table
            // prefix rewriting on prefix-isolated databases.
            ->orderBy('translation.status')
            ->orderByDesc('translation.updated_at')
            ->first(['translation.text']);

        if ($translation === null) {
            // Do not cache a miss: another worker may commit the matching
            // translation while this bounded queue run is still active.
            return null;
        }

        try {
            $text = TextContract::assertSafePlainText((string) $translation->text);
        } catch (Throwable) {
            return null;
        }

        return $this->translationMemoryCache[$key] = $text;
    }

    private function rememberTranslation(object $entry, string $targetLocale, string $text): void
    {
        $this->translationMemoryCache[$this->translationMemoryKey($entry, $targetLocale)] = $text;
    }

    private function translationMemoryKey(object $entry, string $targetLocale): string
    {
        return implode(':', [
            (string) $entry->source_locale,
            $targetLocale,
            (string) $entry->source_format,
            (string) $entry->kind,
            (string) $entry->field,
            (string) $entry->source_hash,
        ]);
    }

    /** @return list<object> */
    private function accountsFor(string $source, string $target): array
    {
        $cacheKey = $source . ':' . $target;
        if (array_key_exists($cacheKey, $this->accountsCache)) {
            return $this->accountsCache[$cacheKey];
        }
        $accounts = [];
        foreach (DB::table('lgt_provider_accounts')->whereNull('archived_at')->where('owner_scope', 'tenant')->where('enabled', true)->where('dispatch_enabled', true)->whereNotNull('verified_at')->orderBy('failover_priority')->orderBy('id')->get() as $account) {
            if ($this->providers->get((string) $account->provider)->supports($source, $target)) {
                $accounts[] = $account;
            }
        }

        return $this->accountsCache[$cacheKey] = $accounts;
    }

    /**
     * A paused free-only job is intentional only when it has no remaining
     * safe dispatch capacity.  Persist a compact cause in the durable job
     * plan so the dashboard explains the stop and can tell the operator what
     * action (top up, enable, or add a language-capable account) resumes it.
     */
    private function noDispatchAccountReason(string $source, string $target): string
    {
        $configured = DB::table('lgt_provider_accounts')
            ->whereNull('archived_at')
            ->where('owner_scope', 'tenant')
            ->where('enabled', true)
            ->where('dispatch_enabled', true)
            ->whereNotNull('verified_at')
            ->get(['provider']);
        if ($configured->isEmpty()) {
            return 'NO_VERIFIED_DISPATCH_ACCOUNT';
        }
        foreach ($configured as $account) {
            if ($this->providers->get((string) $account->provider)->supports($source, $target)) {
                // This branch is normally reached only if an account changed
                // between selection and processing. Use the capacity reason
                // rather than pretending the target locale is unsupported.
                return 'FREE_QUOTA_EXHAUSTED';
            }
        }

        return 'NO_PROVIDER_FOR_TARGET_LOCALE';
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
            // Serialize claims for the same source entry across separate
            // jobs. This row lock is short-lived: no HTTP call runs inside
            // the transaction. The durable 'claimed' flag guards the period
            // after the transaction commits and before a provider replies.
            DB::table('lgt_entries')->where('id', $item->entry_id)->lockForUpdate()->first(['id']);
            $alreadyClaimed = DB::table('lgt_job_items')
                ->where('entry_id', $item->entry_id)
                ->where('target_locale', $item->target_locale)
                ->where('source_hash', $item->source_hash)
                ->where('status', 'claimed')
                ->where('id', '!=', $item->id)
                ->exists();
            if ($alreadyClaimed) {
                DB::table('lgt_job_items')->where('id', $item->id)->where('status', 'ready')->update([
                    'status'       => 'skipped', 'failure_code' => 'DUPLICATE_ACTIVE_REQUEST',
                    'completed_at' => now(), 'version' => (int) $item->version + 1,
                    'updated_at'   => now(),
                ]);
                DB::table('lgt_jobs')->where('id', $jobId)->update([
                    'completed_items' => (int) $job->completed_items + 1, 'updated_at' => now(),
                ]);

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

    /**
     * Resolve a claim whose job stopped between claim and external send.
     *
     * A shared pause is resumable and must not consume the item. A terminal
     * stop is an operator cancellation and does consume it. Locking the job
     * before the item keeps this decision atomic with pause/cancel/recovery.
     */
    private function settleInterruptedItem(int $itemId, string $runToken): void
    {
        $jobId = DB::table('lgt_job_items')->where('id', $itemId)->value('job_id');
        if ($jobId === null) {
            return;
        }

        DB::transaction(function () use ($itemId, $jobId, $runToken): void {
            $job = DB::table('lgt_jobs')->where('id', $jobId)->lockForUpdate()->first();
            if ($job === null) {
                return;
            }
            $item = DB::table('lgt_job_items')->where('id', $itemId)->lockForUpdate()->first();
            if ($item === null || (int) $item->job_id !== (int) $jobId || $item->status !== 'claimed'
                               || ! hash_equals((string) $item->claim_token, $runToken)) {
                return;
            }

            if (in_array((string) $job->status, ['planned', 'queued', 'scanning', 'running', 'pausing', 'paused'], true)) {
                DB::table('lgt_job_items')->where('id', $itemId)->update([
                    'status'       => 'ready',
                    'failure_code' => null,
                    'claim_token'  => null,
                    'claimed_at'   => null,
                    'version'      => (int) $item->version + 1,
                    'updated_at'   => now(),
                ]);

                return;
            }

            DB::table('lgt_job_items')->where('id', $itemId)->update([
                'status'       => 'cancelled',
                'failure_code' => null,
                'claim_token'  => null,
                'completed_at' => now(),
                'version'      => (int) $item->version + 1,
                'updated_at'   => now(),
            ]);
            DB::table('lgt_jobs')->where('id', $jobId)->update([
                'completed_items' => (int) $job->completed_items + 1,
                'updated_at'      => now(),
            ]);
        }, 3);
    }

    private function finishItem(int $itemId, string $status, string $runToken, ?string $failureCode = null): void
    {
        $failureCode = $status === 'failed' ? $this->safeFailureCode($failureCode ?: 'PROCESSING_EXCEPTION') : null;
        $jobId       = DB::table('lgt_job_items')->where('id', $itemId)->value('job_id');
        if ($jobId === null) {
            return;
        }
        DB::transaction(function () use ($itemId, $jobId, $status, $runToken, $failureCode): void {
            $job = DB::table('lgt_jobs')->where('id', $jobId)->lockForUpdate()->first();
            if ($job === null) {
                return;
            }
            $item = DB::table('lgt_job_items')->where('id', $itemId)->lockForUpdate()->first();
            if ($item === null || (int) $item->job_id !== (int) $jobId || $item->status !== 'claimed'
                               || ! hash_equals((string) $item->claim_token, $runToken)) {
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
            DB::table('lgt_jobs')->where('id', $jobId)->update([
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
        // Only dispatch another worker if genuinely ready work exists.
        // A still-claimed item belongs to an active worker: recursively
        // requeueing for claimed-only work creates an unbounded hot loop.
        if (DB::table('lgt_job_items')->where('job_id', $jobId)->where('status', 'ready')->exists()) {
            ProcessTranslationJob::dispatch($jobId)->afterCommit();

            return;
        }
        if (DB::table('lgt_job_items')->where('job_id', $jobId)->where('status', 'claimed')->exists()) {
            return;
        }
        $terminalStatus = (int) ($job->failed_items ?? 0) > 0 ? 'partial_failed' : 'completed';
        if ($this->setJobStatus($jobId, $terminalStatus, true)) {
            $this->autoPublishSafeResults($jobId);
        }
    }

    /**
     * Prime the dedicated translation queue with one job per bounded worker.
     * Each completing worker enqueues one replacement in finishIfTerminal(),
     * maintaining steady concurrency without creating an unbounded backlog.
     */
    private function dispatchWorkerPool(int $jobId): void
    {
        $workers = min(4, max(1, (int) config('longos.translation.workers', 4)));
        for ($index = 0; $index < $workers; $index++) {
            ProcessTranslationJob::dispatch($jobId)->afterCommit();
        }
    }

    private function setJobStatus(int $jobId, string $status, bool $finished = false): bool
    {
        return DB::transaction(function () use ($jobId, $status, $finished): bool {
            $job = DB::table('lgt_jobs')->where('id', $jobId)->lockForUpdate()->first();
            if ($job === null || $job->archived_at !== null) {
                return false;
            }
            // A queued worker may start after cancellation, or a finishing
            // worker may race with the cancel request. Neither may revive it.
            if ($status === 'running' && ! in_array((string) $job->status, ['queued', 'running'], true)) {
                return false;
            }
            if ($status !== 'running' && $job->status !== 'running') {
                return false;
            }
            $updates = [
                'status'      => $status,
                'started_at'  => $status === 'running' ? now() : DB::raw('started_at'),
                'finished_at' => $finished ? now() : DB::raw('finished_at'),
                'updated_at'  => now(),
            ];
            if ($status === 'running') {
                $plan = json_decode((string) ($job->plan ?? ''), true);
                if (is_array($plan) && array_key_exists('pause', $plan)) {
                    unset($plan['pause']);
                    $updates['plan'] = json_encode($plan, JSON_UNESCAPED_UNICODE);
                }
            }
            DB::table('lgt_jobs')->where('id', $jobId)->update($updates);

            return true;
        }, 3);
    }

    private function pauseJob(int $jobId, string $reason): ?string
    {
        return DB::transaction(function () use ($jobId, $reason): ?string {
            $job = DB::table('lgt_jobs')->where('id', $jobId)->lockForUpdate()->first();
            if ($job === null || $job->status === 'paused' || $job->archived_at !== null
                              || in_array((string) $job->status, ['completed', 'partial_failed', 'failed', 'cancelled'], true)) {
                return null;
            }
            $plan          = json_decode((string) ($job->plan ?? ''), true);
            $plan          = is_array($plan) ? $plan : [];
            $nonce         = (string) Str::uuid();
            $plan['pause'] = ['code' => $reason, 'at' => now()->toIso8601String(), 'nonce' => $nonce];
            DB::table('lgt_jobs')->where('id', $jobId)->update([
                'status'     => 'paused',
                'plan'       => json_encode($plan, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

            return $nonce;
        }, 3);
    }

    /** Wake only the specific rate-limited pause; never revive an operator pause or cancelled job. */
    public function resumeSiliconFlowRateWindow(int $jobId, string $pauseNonce): void
    {
        if ($this->settings->all()['mode'] !== TranslationSettings::MODE_FREE_ONLY) {
            return;
        }
        $resumed = DB::transaction(function () use ($jobId, $pauseNonce): bool {
            $job = DB::table('lgt_jobs')->where('id', $jobId)->lockForUpdate()->first();
            if ($job === null || $job->archived_at !== null || $job->status !== 'paused'
                              || $job->mode        !== TranslationSettings::MODE_FREE_ONLY) {
                return false;
            }
            $plan = json_decode((string) ($job->plan ?? ''), true);
            if (! is_array($plan) || ($plan['pause']['code'] ?? '') !== 'SILICONFLOW_RATE_WINDOW'
                                  || ! hash_equals((string) ($plan['pause']['nonce'] ?? ''), $pauseNonce)) {
                return false;
            }
            unset($plan['pause']);
            DB::table('lgt_jobs')->where('id', $jobId)->update([
                'status' => 'queued', 'plan' => json_encode($plan, JSON_UNESCAPED_UNICODE), 'updated_at' => now(),
            ]);

            return true;
        }, 3);
        if ($resumed) {
            $this->dispatchWorkerPool($jobId);
        }
    }

    /**
     * Turn a terminal job into a usable storefront dictionary without asking
     * an operator to repeat an approval action. The publication service still
     * applies the same source-hash, quality, machine-attribution and lock
     * rules as the explicit admin action. A partial job therefore publishes
     * only its safe results and leaves rejected output visible for review.
     */
    private function autoPublishSafeResults(int $jobId): void
    {
        $settings = $this->settings->all();
        if (! (bool) ($settings['publication']['auto_publish_safe'] ?? true)) {
            return;
        }
        $job = DB::table('lgt_jobs')->where('id', $jobId)->whereIn('status', ['completed', 'partial_failed'])
            ->whereNull('archived_at')->first(['id', 'target_locales', 'plan']);
        if ($job === null) {
            return;
        }
        $locales = json_decode((string) $job->target_locales, true);
        if (! is_array($locales)) {
            return;
        }
        $publisher = $this->publications ?? new PublicationService;
        $published = [];
        $failures  = [];
        foreach (array_values(array_unique($locales)) as $locale) {
            if (! is_string($locale)) {
                continue;
            }

            try {
                $locale = TranslationSettings::locale($locale);
                if (! $publisher->hasPublishableTranslations($locale)) {
                    continue;
                }
                $result      = $publisher->publishReady($locale, null, $this->library);
                $published[] = [
                    'locale'   => $locale,
                    'revision' => (int) $result['publication']['revision'],
                    'approved' => (int) $result['publication']['coverage']['approved'],
                ];
            } catch (Throwable $exception) {
                // Translation is already terminal at this point. Keep its
                // results intact and expose a bounded operational result in
                // the job plan rather than incorrectly marking every item as
                // failed or leaking provider messages to the storefront.
                $failures[] = ['locale' => (string) $locale, 'code' => $this->safeFailureCode($exception->getMessage())];
            }
        }
        if ($published !== [] && (bool) ($settings['publication']['auto_enable_widget'] ?? true)
                              && ! (bool) ($settings['widget']['enabled'] ?? false)) {
            $latest = $this->settings->all();
            $this->settings->save([
                ...$latest,
                'widget' => [...(array) $latest['widget'], 'enabled' => true],
            ]);
        }
        $this->recordAutoPublication($jobId, $published, $failures);
    }

    /** @param list<array<string,int|string>> $published @param list<array<string,string>> $failures */
    private function recordAutoPublication(int $jobId, array $published, array $failures): void
    {
        DB::transaction(function () use ($jobId, $published, $failures): void {
            $job = DB::table('lgt_jobs')->where('id', $jobId)->lockForUpdate()->first(['id', 'plan']);
            if ($job === null) {
                return;
            }
            $plan                     = json_decode((string) $job->plan, true);
            $plan                     = is_array($plan) ? $plan : [];
            $plan['auto_publication'] = [
                'state'        => $failures === [] ? ($published === [] ? 'no_safe_results' : 'published') : 'partial',
                'published'    => $published,
                'failures'     => $failures,
                'completed_at' => now()->toIso8601String(),
            ];
            DB::table('lgt_jobs')->where('id', $jobId)->update([
                'plan'       => json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        }, 3);
    }
}
