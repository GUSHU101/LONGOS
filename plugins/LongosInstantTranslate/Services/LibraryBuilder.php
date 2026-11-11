<?php

namespace Plugin\LongosInstantTranslate\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Plugin\LongosInstantTranslate\Providers\ProviderRegistry;
use Plugin\LongosInstantTranslate\Providers\SiliconFlowProvider;
use Plugin\LongosInstantTranslate\Providers\TranslationProviderRequest;
use Throwable;

final class LibraryBuilder
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly ProviderAccountService $accounts,
    ) {}

    /** @return array{state:string,entry_id?:int,provider?:string,error?:string} */
    public function processOne(): array
    {
        if (! LibrarySchema::ready() || ! app(InstantTranslateSettings::class)->libraryBuildEnabled()) {
            return ['state' => 'paused'];
        }

        $entry = $this->claim();
        if (! $entry) {
            return ['state' => 'idle'];
        }

        $sawTransient = false;

        try {
            $candidates = DB::table('lit_provider_accounts')
                ->where('enabled', true)
                ->where('dispatch_enabled', true)
                ->whereNotNull('verified_at')
                ->where(function ($query): void {
                    $query->whereNull('cooldown_until')->orWhere('cooldown_until', '<=', now());
                })
                ->orderBy('priority')->orderBy('id')->get();

            foreach ($candidates as $account) {
                $provider = $this->providers->get((string) $account->provider);
                if (! $provider->supports((string) $entry->source_locale, (string) $entry->target_locale)) {
                    continue;
                }
                $units = mb_strlen((string) $entry->source_text, 'UTF-8');
                if (! $this->reserveUsage($account, $units)) {
                    continue;
                }
                $wait = $this->reservePace($account, strlen((string) $entry->source_text) * 2 + 512);
                if ($wait > 0) {
                    $this->settleUsage($account, $units, 'release');
                    $sawTransient = true;

                    continue;
                }

                try {
                    $credentials = $this->accounts->credentials($account);
                    $provider->validateCredentialShape($credentials);
                    $masked  = TextContract::protect((string) $entry->source_text, $this->glossary((string) $entry->target_locale));
                    $request = new TranslationProviderRequest(
                        requestUuid: (string) Str::uuid(),
                        providerAccountId: (int) $account->id,
                        sourceLocale: (string) $entry->source_locale,
                        targetLocale: (string) $entry->target_locale,
                        segments: [$masked['text']],
                        contentHash: (string) $entry->source_hash,
                        timeoutSeconds: $provider instanceof SiliconFlowProvider ? SiliconFlowProvider::REQUEST_TIMEOUT_SECONDS : 12,
                        context: [
                            'kind'            => (string) $entry->source_kind,
                            'field'           => 'text',
                            'domain'          => 'commerce',
                            'prompt'          => 'Public storefront content. Preserve product names, placeholders, numbers and formatting.',
                            'profile_version' => 'lit-v1',
                        ],
                    );
                    $response = $provider->send($request, $credentials);
                    if ($response->ok && isset($response->translations[0])) {
                        $translated = TextContract::restore((string) $response->translations[0], $masked['tokens']);
                        $quality    = TranslationQualityGate::evaluate($translated, (string) $entry->source_text, (string) $entry->target_locale);
                        $this->complete($entry, $account, $translated, $quality, $response->requestId);

                        return [
                            'state'    => $quality === null ? 'ready' : 'review',
                            'entry_id' => (int) $entry->id,
                            'provider' => (string) $account->provider,
                        ];
                    }

                    $failure = $provider->classifyFailure($response);
                    $code    = (string) $failure['code'];
                    if (in_array($code, ['AUTH', 'NOT_OPENED'], true)) {
                        $this->settleUsage($account, $units, 'release');
                        $this->accounts->disableAfterAuthFailure((int) $account->id, $code, $response->requestId);

                        continue;
                    }
                    if (in_array($code, ['RATE_LIMIT', 'SERVICE_OVERLOADED'], true)) {
                        $this->settleUsage($account, $units, 'release');
                        $this->cooldown($account, $code, $response->requestId, $response->retryAfterSeconds ?? ($code === 'RATE_LIMIT' ? 60 : 120));
                        $sawTransient = true;

                        continue;
                    }
                    if ($failure['kind'] === 'definite_failure') {
                        $this->settleUsage($account, $units, 'release');
                        $this->fail($entry, $code, false);

                        return ['state' => 'failed', 'entry_id' => (int) $entry->id, 'error' => $code];
                    }

                    $this->settleUsage($account, $units, 'uncertain');
                    $this->fail($entry, $code, true);

                    return ['state' => 'review', 'entry_id' => (int) $entry->id, 'error' => $code];
                } catch (Throwable $exception) {
                    $failure = $provider->classifyFailure($exception);
                    if ($failure['definitely_unbilled']) {
                        $this->settleUsage($account, $units, 'release');
                        if ($failure['code'] === 'NETWORK_CONNECT_FAILED') {
                            $sawTransient = true;

                            continue;
                        }
                        $this->fail($entry, (string) $failure['code'], false);

                        return ['state' => 'failed', 'entry_id' => (int) $entry->id, 'error' => (string) $failure['code']];
                    }

                    $this->settleUsage($account, $units, 'uncertain');
                    $this->fail($entry, (string) $failure['code'], true);

                    return ['state' => 'review', 'entry_id' => (int) $entry->id, 'error' => (string) $failure['code']];
                }
            }

            $this->release($entry, $sawTransient ? 'PROVIDER_BUSY' : 'NO_VERIFIED_PROVIDER', $sawTransient ? 120 : 600);

            return ['state' => $sawTransient ? 'delayed' : 'no_provider', 'entry_id' => (int) $entry->id];
        } catch (Throwable $exception) {
            $this->release($entry, 'WORKER_EXCEPTION', 600);
            report($exception);

            return ['state' => 'failed', 'entry_id' => (int) $entry->id, 'error' => 'WORKER_EXCEPTION'];
        }
    }

    /** @return array{ok:bool,error:?string} */
    public function verify(int $accountId): array
    {
        $account = DB::table('lit_provider_accounts')->where('id', $accountId)->first();
        if (! $account || ! $account->enabled) {
            return ['ok' => false, 'error' => 'ACCOUNT_DISABLED'];
        }
        $provider = $this->providers->get((string) $account->provider);

        try {
            $credentials = $this->accounts->credentials($account);
            $request     = new TranslationProviderRequest(
                requestUuid: (string) Str::uuid(),
                providerAccountId: (int) $account->id,
                sourceLocale: 'en',
                targetLocale: 'fr',
                segments: ['Hello'],
                contentHash: hash('sha256', 'Hello'),
                timeoutSeconds: $provider instanceof SiliconFlowProvider ? SiliconFlowProvider::REQUEST_TIMEOUT_SECONDS : 12,
                context: ['kind' => 'verification', 'field' => 'text', 'domain' => 'commerce', 'prompt' => 'Connection verification.', 'profile_version' => 'lit-v1'],
            );
            $response = $provider->send($request, $credentials);
            if (! $response->ok || trim((string) ($response->translations[0] ?? '')) === '') {
                $code = (string) ($response->errorCode ?: 'VERIFY_FAILED');
                DB::table('lit_provider_accounts')->where('id', $accountId)->update([
                    'verified_at'   => null, 'dispatch_enabled' => false, 'last_error_code' => $code,
                    'last_trace_id' => $response->requestId, 'updated_at' => now(),
                ]);

                return ['ok' => false, 'error' => $code];
            }
            DB::table('lit_provider_accounts')->where('id', $accountId)->update([
                'verified_at'    => now(), 'last_error_code' => null, 'last_trace_id' => $response->requestId,
                'cooldown_until' => null, 'updated_at' => now(),
            ]);

            return ['ok' => true, 'error' => null];
        } catch (Throwable $exception) {
            $failure = $provider->classifyFailure($exception);
            DB::table('lit_provider_accounts')->where('id', $accountId)->update([
                'verified_at'     => null, 'dispatch_enabled' => false,
                'last_error_code' => (string) $failure['code'], 'updated_at' => now(),
            ]);

            return ['ok' => false, 'error' => (string) $failure['code']];
        }
    }

    private function claim(): ?object
    {
        return DB::transaction(function (): ?object {
            $entry = DB::table('lit_translation_entries')
                ->where('status', 'pending')
                ->where(function ($query): void {
                    $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                })
                ->where(function ($query): void {
                    $query->whereNull('locked_at')->orWhere('locked_at', '<', now()->subMinutes(10));
                })
                ->orderBy('id')->lockForUpdate()->first();
            if (! $entry) {
                return null;
            }
            DB::table('lit_translation_entries')->where('id', $entry->id)->update([
                'locked_at' => now(), 'attempts' => (int) $entry->attempts + 1, 'updated_at' => now(),
            ]);
            $entry->attempts = (int) $entry->attempts + 1;

            return $entry;
        });
    }

    private function reservePace(object $account, int $tokenEstimate): int
    {
        return DB::transaction(function () use ($account, $tokenEstimate): int {
            $fresh     = DB::table('lit_provider_accounts')->where('id', $account->id)->lockForUpdate()->first();
            $minimumUs = (int) ceil(1_000_000 / max(1, (int) $fresh->qps_limit));
            $nowUs     = (int) floor(microtime(true) * 1_000_000);
            $remaining = ((int) $fresh->last_dispatch_at_us + $minimumUs) - $nowUs;
            if ($remaining > 0) {
                return max(1, (int) ceil($remaining / 1_000_000));
            }
            $windowAt = $fresh->rate_window_at ? \Illuminate\Support\Carbon::parse($fresh->rate_window_at) : null;
            $expired  = $windowAt === null || $windowAt->lte(now()->subMinute());
            $requests = $expired ? 0 : (int) $fresh->rate_request_count;
            $tokens   = $expired ? 0 : (int) $fresh->rate_token_estimate;
            if ($requests >= (int) $fresh->rpm_limit || $tokens + $tokenEstimate > (int) $fresh->tpm_limit) {
                if ($windowAt === null) {
                    return 60;
                }

                $retryAt = $windowAt->copy()->addMinute();

                return $retryAt->isFuture()
                    ? max(1, min(60, (int) ceil(now()->diffInSeconds($retryAt))))
                    : 1;
            }
            DB::table('lit_provider_accounts')->where('id', $account->id)->update([
                'last_dispatch_at_us' => $nowUs,
                'rate_window_at'      => $expired ? now() : $windowAt,
                'rate_request_count'  => $requests + 1,
                'rate_token_estimate' => $tokens   + $tokenEstimate,
            ]);

            return 0;
        });
    }

    private function reserveUsage(object $account, int $units): bool
    {
        return DB::transaction(function () use ($account, $units): bool {
            $period = now()->format('Y-m');
            DB::table('lit_provider_usage')->insertOrIgnore([
                'provider_account_id' => $account->id, 'period' => $period,
                'committed_units'     => 0, 'reserved_units' => 0, 'uncertain_units' => 0,
                'request_count'       => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $usage = DB::table('lit_provider_usage')->where('provider_account_id', $account->id)
                ->where('period', $period)->lockForUpdate()->first();
            $limit = (int) $account->monthly_character_limit;
            $used  = (int) $usage->committed_units + (int) $usage->reserved_units + (int) $usage->uncertain_units;
            if ((string) $account->provider !== 'siliconflow' && ($limit <= 0 || $used + $units > $limit)) {
                return false;
            }
            DB::table('lit_provider_usage')->where('id', $usage->id)->update([
                'reserved_units' => (int) $usage->reserved_units + $units,
                'updated_at'     => now(),
            ]);

            return true;
        });
    }

    /** @return array<string,string> */
    private function glossary(string $targetLocale): array
    {
        return DB::table('lit_glossary_terms')->where('source_locale', 'en')->where('protect', true)
            ->where(function ($query) use ($targetLocale): void {
                $query->whereNull('target_locale')->orWhere('target_locale', $targetLocale);
            })->pluck('replacement', 'term')->mapWithKeys(static function ($replacement, $term): array {
                return [(string) $term => trim((string) $replacement) !== '' ? (string) $replacement : (string) $term];
            })->all();
    }

    private function complete(object $entry, object $account, string $translated, ?string $quality, ?string $traceId): void
    {
        DB::transaction(function () use ($entry, $account, $translated, $quality, $traceId): void {
            DB::table('lit_translation_entries')->where('id', $entry->id)->update([
                'translated_text'     => $translated,
                'status'              => $quality === null ? 'ready' : 'review',
                'provider'            => $account->provider,
                'provider_account_id' => $account->id,
                'quality_code'        => $quality,
                'trace_id'            => $traceId,
                'error_code'          => null,
                'locked_at'           => null,
                'next_attempt_at'     => null,
                'completed_at'        => now(),
                'updated_at'          => now(),
            ]);
            $period = now()->format('Y-m');
            DB::table('lit_provider_usage')->insertOrIgnore([
                'provider_account_id' => $account->id, 'period' => $period,
                'committed_units'     => 0, 'reserved_units' => 0, 'uncertain_units' => 0,
                'request_count'       => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $usage = DB::table('lit_provider_usage')->where('provider_account_id', $account->id)
                ->where('period', $period)->lockForUpdate()->first();
            $units = mb_strlen((string) $entry->source_text, 'UTF-8');
            DB::table('lit_provider_usage')->where('id', $usage->id)->update([
                'reserved_units'  => max(0, (int) $usage->reserved_units - $units),
                'committed_units' => (int) $usage->committed_units + $units,
                'request_count'   => (int) $usage->request_count   + 1,
                'updated_at'      => now(),
            ]);
        });
    }

    private function settleUsage(object $account, int $units, string $outcome): void
    {
        DB::transaction(function () use ($account, $units, $outcome): void {
            $usage = DB::table('lit_provider_usage')->where('provider_account_id', $account->id)
                ->where('period', now()->format('Y-m'))->lockForUpdate()->first();
            if (! $usage) {
                return;
            }
            $values = [
                'reserved_units' => max(0, (int) $usage->reserved_units - $units),
                'updated_at'     => now(),
            ];
            if ($outcome === 'uncertain') {
                $values['uncertain_units'] = (int) $usage->uncertain_units + $units;
                $values['request_count']   = (int) $usage->request_count   + 1;
            }
            DB::table('lit_provider_usage')->where('id', $usage->id)->update($values);
        });
    }

    private function cooldown(object $account, string $code, ?string $traceId, int $seconds): void
    {
        DB::table('lit_provider_accounts')->where('id', $account->id)->update([
            'cooldown_until'  => now()->addSeconds(max(5, min(3600, $seconds))),
            'last_error_code' => $code, 'last_trace_id' => $traceId, 'updated_at' => now(),
        ]);
    }

    private function release(object $entry, string $code, int $delaySeconds): void
    {
        $attempts = (int) $entry->attempts;
        DB::table('lit_translation_entries')->where('id', $entry->id)->update([
            'status'          => $attempts >= 5 ? 'failed' : 'pending',
            'error_code'      => $code,
            'locked_at'       => null,
            'next_attempt_at' => $attempts >= 5 ? null : now()->addSeconds($delaySeconds),
            'updated_at'      => now(),
        ]);
    }

    private function fail(object $entry, string $code, bool $uncertain): void
    {
        DB::table('lit_translation_entries')->where('id', $entry->id)->update([
            'status'          => $uncertain ? 'review' : 'failed',
            'error_code'      => mb_substr($code, 0, 64),
            'locked_at'       => null,
            'next_attempt_at' => null,
            'updated_at'      => now(),
        ]);
    }
}
