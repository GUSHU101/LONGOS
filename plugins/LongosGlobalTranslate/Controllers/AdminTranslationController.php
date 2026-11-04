<?php

namespace Plugin\LongosGlobalTranslate\Controllers;

use Beike\Admin\Http\Controllers\Controller;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Plugin\LongosGlobalTranslate\Services\ContentScanner;
use Plugin\LongosGlobalTranslate\Services\CountryLocaleCatalog;
use Plugin\LongosGlobalTranslate\Services\ProviderAccountService;
use Plugin\LongosGlobalTranslate\Services\ProviderVerificationService;
use Plugin\LongosGlobalTranslate\Services\PublicationService;
use Plugin\LongosGlobalTranslate\Services\TranslationJobService;
use Plugin\LongosGlobalTranslate\Services\TranslationLibrary;
use Plugin\LongosGlobalTranslate\Services\TranslationSchema;
use Plugin\LongosGlobalTranslate\Services\TranslationSettings;
use Throwable;

final class AdminTranslationController extends Controller
{
    public function __construct(
        private readonly TranslationSettings $settings,
        private readonly ProviderAccountService $accounts,
        private readonly ProviderVerificationService $verification,
        private readonly ContentScanner $scanner,
        private readonly TranslationJobService $jobs,
        private readonly TranslationLibrary $library,
        private readonly PublicationService $publications,
        private readonly CountryLocaleCatalog $countries,
    ) {}

    public function index()
    {
        $schema = TranslationSchema::inspect();
        if (! $schema['ready']) {
            return $this->recoveryPage($schema);
        }

        // A ready schema must lead to the actual workspace.  Do not turn a
        // coding or presentation failure into a reassuring but unusable
        // “upgrade” screen: dashboard() isolates recoverable tenant records
        // section-by-section and an unexpected defect remains observable in
        // both monitoring and the test suite.
        return response()->view('LongosGlobalTranslate::admin.index', $this->dashboard())
            ->header('Cache-Control', 'private, no-store');
    }

    public function diagnostics(): JsonResponse
    {
        $schema = TranslationSchema::inspect();
        if (! $schema['ready']) {
            return $this->json([
                'ready'                 => false,
                'schema'                => $schema,
                'mode'                  => TranslationSettings::MODE_PREVIEW,
                'dispatch_enabled'      => false,
                'paid_cap'              => 0,
                'provider_calls'        => 'schema_repair_required',
                'tenant_resolved'       => current_site() !== null,
                'core_locale_unchanged' => true,
            ], 503);
        }

        return $this->json($this->dashboard()['diagnostics']);
    }

    /**
     * A compact, credential-free snapshot for the admin workspace.
     *
     * Translation runs are intentionally asynchronous. Rendering an admin
     * page once and expecting an operator to refresh it hides that fact and
     * made interrupted/provider-failed runs look like the UI was frozen.
     * This endpoint is deliberately tenant-local (the plugin route already
     * carries the tenant/plugin/permission middleware) and exposes neither
     * source text nor encrypted provider credentials.
     */
    public function workspaceStatus(): JsonResponse
    {
        $schema = TranslationSchema::inspect();
        if (! $schema['ready']) {
            return $this->json([
                'ready'  => false,
                'schema' => $schema,
            ], 503);
        }

        $dashboard = $this->dashboard();

        return $this->json([
            'ready'          => true,
            'generated_at'   => now()->toIso8601String(),
            'diagnostics'    => $dashboard['diagnostics'],
            'jobs'           => $dashboard['jobs'],
            'accounts'       => $dashboard['accounts'],
            'publications'   => count($dashboard['publications']),
            'workspace_alerts' => $dashboard['workspace_warnings'],
        ]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->requireSchema();
        $data = $request->validate([
            'mode'                        => ['required', 'string'],
            'source_locale'               => ['required', 'string', 'max:24'],
            'enabled_locales'             => ['present', 'array', 'max:24'],
            'enabled_locales.*'           => ['string', 'max:24'],
            'accept_language_enabled'     => ['boolean'],
            'widget'                      => ['required', 'array'],
            'widget.enabled'              => ['boolean'],
            'widget.position'             => ['required', 'string'],
            'widget.bottom_offset'        => ['nullable', 'integer', 'min:16', 'max:240'],
            'widget.mobile_bottom_offset' => ['nullable', 'integer', 'min:16', 'max:260'],
            'widget.theme'                => ['required', 'string'],
        ]);
        $saved = $this->settings->save($data);
        $this->audit('settings_updated', 'settings', null, [], ['mode' => $saved['mode'], 'locales' => $saved['enabled_locales']]);

        return $this->json(['settings' => $saved]);
    }

    public function saveCountryRule(Request $request): JsonResponse
    {
        $this->requireSchema();
        $data = $request->validate([
            'country' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'locale'  => ['required', 'string', 'max:24'],
            'enabled' => ['required', 'boolean'],
        ]);
        $country  = strtoupper((string) $data['country']);
        $locale   = TranslationSettings::locale((string) $data['locale']);
        $settings = $this->settings->all();
        if (! in_array($locale, array_merge([(string) $settings['source_locale']], (array) $settings['enabled_locales']), true)) {
            throw ValidationException::withMessages([
                'locale' => '国家/地区默认语言必须先在“展示语言设置”中启用。',
            ]);
        }
        $before = DB::table('lgt_country_rules')->where('country', $country)->first();
        DB::table('lgt_country_rules')->updateOrInsert(
            ['country' => $country],
            ['locale' => $locale, 'enabled' => (bool) $data['enabled'], 'updated_at' => now(), 'created_at' => $before?->created_at ?? now()],
        );
        $rule = DB::table('lgt_country_rules')->where('country', $country)->first();
        $this->audit('country_rule_saved', 'country_rule', (int) $rule->id, (array) $before, ['country' => $country, 'locale' => $locale, 'enabled' => (bool) $data['enabled']]);

        return $this->json(['rule' => $rule]);
    }

    /**
     * Apply the language recommendations for selected, enabled system
     * countries. This is intentionally additive: it never deletes a manual
     * locale or a country rule that the operator did not select.
     */
    public function applyCountryRecommendations(Request $request): JsonResponse
    {
        $this->requireSchema();
        $data = $request->validate([
            'countries'   => ['required', 'array', 'min:1', 'max:250'],
            'countries.*' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
        ]);
        $countryCodes = array_values(array_unique(array_map(
            static fn (string $country): string => strtoupper(trim($country)),
            $data['countries'],
        )));
        $suggestions = $this->countries->suggestionsFor($countryCodes);
        if (count($suggestions) !== count($countryCodes)) {
            throw ValidationException::withMessages([
                'countries' => '请仅选择系统中已启用且具有受支持推荐语言的国家/地区。',
            ]);
        }

        $current = $this->settings->all();
        $locales = array_values(array_unique(array_merge(
            (array) $current['enabled_locales'],
            array_values($suggestions),
        )));
        if (count($locales) > 24) {
            throw ValidationException::withMessages([
                'countries' => '所选国家对应的展示语言超过 24 种上限，请减少选择后重试。',
            ]);
        }

        $result = DB::transaction(function () use ($current, $locales, $suggestions): array {
            $settings = $this->settings->save([...$current, 'enabled_locales' => $locales]);
            $rules    = [];
            foreach ($suggestions as $country => $locale) {
                $before = DB::table('lgt_country_rules')->where('country', $country)->first();
                DB::table('lgt_country_rules')->updateOrInsert(
                    ['country' => $country],
                    ['locale' => $locale, 'enabled' => true, 'updated_at' => now(), 'created_at' => $before?->created_at ?? now()],
                );
                $rule = DB::table('lgt_country_rules')->where('country', $country)->first();
                $this->audit('country_recommendation_applied', 'country_rule', (int) $rule->id, (array) $before, ['country' => $country, 'locale' => $locale, 'enabled' => true]);
                $rules[] = $rule;
            }

            return ['settings' => $settings, 'rules' => $rules];
        });
        $this->audit('country_recommendations_applied', 'settings', null, [], ['countries' => array_keys($suggestions), 'locales' => array_values($suggestions)]);

        return $this->json([
            ...$result,
            'notice' => sprintf('已将 %d 个国家/地区与 %d 种推荐展示语言联动保存。', count($suggestions), count(array_unique($suggestions))),
        ]);
    }

    public function saveProvider(Request $request): JsonResponse
    {
        $this->requireSchema();
        $data = $request->validate([
            'provider'              => ['required', 'in:aliyun,volcengine,baidu'],
            'billing_identity'      => ['required', 'string', 'max:255'],
            'account_tier'          => ['nullable', 'string', 'max:48'],
            'free_quota'            => ['nullable', 'integer', 'min:0', 'max:10000000000'],
            'soft_cap'              => ['nullable', 'integer', 'min:0', 'max:10000000000'],
            'external_usage_buffer' => ['nullable', 'integer', 'min:0', 'max:10000000000'],
            'cycle_timezone'        => ['nullable', 'string', 'max:64'],
            'qps_limit'             => ['nullable', 'integer', 'min:1', 'max:10'],
            'failover_priority'     => ['nullable', 'integer', 'min:1', 'max:1000'],
            'enabled'               => ['boolean'],
            'source_link'           => ['nullable', 'url', 'max:512'],
            'credentials'           => ['required', 'array'],
            'credentials.*'         => ['nullable', 'string', 'max:1024'],
        ]);
        /** @var array<string, string> $credentials */
        $credentials = array_filter((array) $data['credentials'], static fn (mixed $value): bool => is_string($value));
        $account     = $this->accounts->save($data, $credentials, $this->actorId());

        return $this->json([
            'account' => $account,
            'notice'  => 'API 凭据已加密保存，派发保持关闭。请先由管理员完成受控验证，再显式开启内容派发。',
        ], 201);
    }

    public function verifyProvider(Request $request, int $provider): JsonResponse
    {
        $this->requireSchema();
        $request->validate(['confirm_provider_charge' => ['accepted']]);

        return $this->json([
            'account' => $this->verification->verify($provider, $this->actorId()),
            'notice'  => '受控验证请求已成功。内容派发仍处于关闭状态，需由管理员手动开启。',
        ]);
    }

    public function dispatchProvider(Request $request, int $provider): JsonResponse
    {
        $this->requireSchema();
        $data    = $request->validate(['enabled' => ['required', 'boolean'], 'confirm_dispatch' => ['accepted_if:enabled,true']]);
        $account = $this->accounts->setDispatch($provider, (bool) $data['enabled'], $this->actorId());
        $resumed = (bool) $data['enabled'] ? $this->jobs->resumePausedJobs() : 0;

        return $this->json([
            'account'      => $account,
            'resumed_jobs' => $resumed,
            'notice'       => $resumed > 0
                ? "已开启服务商派发，并恢复 {$resumed} 个因免费额度模式暂停的任务。"
                : '服务商派发设置已保存。',
        ]);
    }

    public function scan(Request $request): JsonResponse
    {
        $this->requireSchema();
        $data  = $request->validate(['scope' => ['present', 'array', 'max:5'], 'scope.*' => ['in:product,category,page,builder,commerce_ui']]);
        $scope = $data['scope'] ?: ['product', 'category', 'page', 'builder', 'commerce_ui'];

        return $this->json(['report' => $this->scanner->scan($scope, $this->actorId())]);
    }

    public function plan(Request $request): JsonResponse
    {
        $this->requireSchema();
        $data = $request->validate([
            'locales'   => ['required', 'array', 'min:1', 'max:24'],
            'locales.*' => ['string', 'max:24'],
            'scope'     => ['required', 'array', 'min:1', 'max:5'],
            'scope.*'   => ['in:product,category,page,builder,commerce_ui'],
        ]);

        return $this->json($this->jobs->plan($data['locales'], $data['scope'], $this->actorId()), 201);
    }

    public function translations(Request $request): JsonResponse
    {
        $this->requireSchema();
        $locale = $request->filled('locale') ? TranslationSettings::locale((string) $request->query('locale')) : null;
        $query  = DB::table('lgt_translations as t')->join('lgt_entries as e', 'e.id', '=', 't.entry_id')
            ->orderByDesc('t.updated_at')->limit(100);
        if ($locale !== null) {
            $query->where('t.locale', $locale);
        }

        return $this->json(['items' => $query->get([
            't.id', 't.locale', 't.text', 't.status', 't.locked', 't.version', 't.quality_code', 't.updated_at',
            'e.kind', 'e.entity_id', 'e.field', 'e.source_text', 'e.source_hash',
        ])]);
    }

    public function updateTranslation(Request $request, int $translation): JsonResponse
    {
        $this->requireSchema();
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'text'    => ['required', 'string', 'max:100000'],
            'lock'    => ['boolean'],
        ]);

        return $this->json(['translation' => $this->library->edit($translation, (int) $data['version'], $data['text'], $this->actorId(), (bool) ($data['lock'] ?? false))]);
    }

    public function reviewTranslation(Request $request, int $translation): JsonResponse
    {
        $this->requireSchema();
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'approved' => ['required', 'boolean']]);

        return $this->json(['translation' => $this->library->review($translation, (int) $data['version'], (bool) $data['approved'], $this->actorId())]);
    }

    public function publish(Request $request): JsonResponse
    {
        $this->requireSchema();
        $data = $request->validate(['locale' => ['required', 'string', 'max:24']]);

        return $this->json(['publication' => $this->publications->publish($data['locale'], $this->actorId())], 201);
    }

    public function rollback(Request $request, int $publication): JsonResponse
    {
        $this->requireSchema();
        $data = $request->validate(['locale' => ['required', 'string', 'max:24']]);

        return $this->json(['publication' => $this->publications->rollback($data['locale'], $publication, $this->actorId())], 201);
    }

    /** @return array<string, mixed> */
    private function dashboard(): array
    {
        $warnings     = [];
        $settings     = $this->safeDashboardValue('settings', fn (): array => $this->settings->all(), $this->defaultSettings(), $warnings);
        $latest       = $this->safeDashboardValue('coverage', fn (): mixed => DB::table('lgt_coverage_snapshots')->latest('id')->value('report'), null, $warnings);
        $accounts     = $this->safeDashboardValue('provider accounts', fn (): array => $this->accounts->publicAccounts(), [], $warnings);
        $jobs         = $this->safeDashboardValue('job history', fn (): array => $this->jobSummaries(), [], $warnings);
        $publications = $this->safeDashboardValue('publication history', fn () => DB::table('lgt_publications')->orderByDesc('published_at')->limit(24)->get(), collect(), $warnings);
        $countryRules = $this->safeDashboardValue('country defaults', fn () => DB::table('lgt_country_rules')->orderBy('country')->limit(250)->get(), collect(), $warnings);
        $countries    = $this->safeDashboardValue('system countries', fn (): array => $this->countries->enabledCountries(), [], $warnings);

        $dispatchEnabled = collect($accounts)->contains(static fn (mixed $account): bool => (bool) data_get($account, 'dispatch_enabled', false));

        return [
            'settings'           => $settings,
            'accounts'           => $accounts,
            'jobs'               => $jobs,
            'publications'       => $publications,
            'countryRules'       => $countryRules,
            'countries'          => $countries,
            'providerProfiles'   => ProviderAccountService::profiles(),
            'latest_coverage'    => is_string($latest) ? (json_decode($latest, true) ?: []) : [],
            'workspace_warnings' => $warnings,
            'diagnostics'        => [
                'mode'                  => $settings['mode'],
                'dispatch_enabled'      => $dispatchEnabled,
                'paid_cap'              => 0,
                'provider_calls'        => $dispatchEnabled ? 'free_quota_only' : 'requires_operator_verification',
                'tenant_resolved'       => current_site() !== null,
                'core_locale_unchanged' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function defaultSettings(): array
    {
        return [
            'mode'                    => TranslationSettings::MODE_PREVIEW,
            'source_locale'           => 'en',
            'enabled_locales'         => [],
            'accept_language_enabled' => false,
            'widget'                  => [
                'enabled'              => false,
                'position'             => 'right',
                'bottom_offset'        => 84,
                'mobile_bottom_offset' => 88,
                'theme'                => 'system',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jobSummaries(): array
    {
        return DB::table('lgt_jobs')->latest('id')->limit(12)->get()->map(function (object $job): array {
            $total     = (int) $job->total_items;
            $completed = (int) $job->completed_items;
            $failed    = (int) $job->failed_items;
            $reasons   = DB::table('lgt_job_items')
                ->where('job_id', $job->id)
                ->where('status', 'failed')
                ->whereNotNull('failure_code')
                ->select('failure_code', DB::raw('COUNT(*) as total'))
                ->groupBy('failure_code')
                ->orderByDesc('total')
                ->limit(4)
                ->get()
                ->map(fn (object $reason): array => [
                    'code'  => (string) $reason->failure_code,
                    'label' => $this->failureLabel((string) $reason->failure_code),
                    'total' => (int) $reason->total,
                ])->all();
            // Older task rows predate durable item failure codes. Preserve a
            // useful, honest fallback rather than pretending no error exists.
            if ($failed > 0 && $reasons === []) {
                $reasons[] = [
                    'code'  => 'HISTORICAL_FAILURE',
                    'label' => '历史任务未记录细分原因；请按当前凭据重新创建一个小范围任务进行验证。',
                    'total' => $failed,
                ];
            }

            return [
                'public_id'       => (string) $job->public_id,
                'status'          => (string) $job->status,
                'mode'            => (string) $job->mode,
                'total_items'     => $total,
                'completed_items' => $completed,
                'failed_items'    => $failed,
                'progress_percent'=> $total > 0 ? min(100, (int) floor(($completed / $total) * 100)) : 0,
                'created_at'      => $this->isoTimestamp($job->created_at ?? null),
                'updated_at'      => $this->isoTimestamp($job->updated_at ?? null),
                'active'          => in_array((string) $job->status, ['queued', 'scanning', 'running', 'pausing'], true),
                'failure_summary' => $reasons,
            ];
        })->all();
    }

    private function failureLabel(string $code): string
    {
        return match ($code) {
            'AUTH' => '服务商凭据被拒绝，请检查 API 密钥与应用权限。',
            'NOT_OPENED' => '服务商翻译服务尚未在该账号开通。',
            'UNSUPPORTED_LANGUAGE' => '该服务商不支持此目标语言。',
            'RATE_LIMIT' => '服务商请求过于频繁，请降低 QPS 或稍后再试。',
            'BALANCE_EXHAUSTED', 'LOCAL_BUDGET_EXHAUSTED', 'FREE_QUOTA_EXHAUSTED_AFTER_PARTIAL_SEGMENT' => '可用免费额度已耗尽。',
            'INVALID_INPUT' => '源文本不符合服务商的输入要求。',
            'UNSAFE_TRANSLATION' => '译文包含不允许的标记，已安全拒绝。',
            'STALE_SOURCE' => '原文在翻译期间已变更，请重新扫描。',
            'NETWORK_AFTER_SEND', 'WORKER_EXCEPTION', 'WORKER_EXHAUSTED' => '请求结果不确定，为避免重复计费已停止自动重试。',
            'PRE_SEND_FAILURE' => '请求在发送前失败，请检查服务商配置后重试。',
            default => '任务处理失败；请打开任务详情或使用小范围任务验证服务商。',
        };
    }

    private function isoTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_object($value) && method_exists($value, 'toIso8601String')) {
            return $value->toIso8601String();
        }

        try {
            return now()->parse((string) $value)->toIso8601String();
        } catch (Throwable) {
            return (string) $value;
        }
    }

    /**
     * Keep an optional history panel from blanking the configuration workspace.
     * Schema readiness is still required for all writes, and failures are
     * reported server-side without exposing tenant internals in the browser.
     *
     * @param array<int, string> $warnings
     */
    private function safeDashboardValue(string $section, callable $resolver, mixed $fallback, array &$warnings): mixed
    {
        try {
            return $resolver();
        } catch (Throwable $exception) {
            report($exception);
            $warnings[] = $section;

            return $fallback;
        }
    }

    private function requireSchema(): void
    {
        $schema = TranslationSchema::inspect();
        if ($schema['ready']) {
            return;
        }

        throw new HttpResponseException($this->json([
            'message' => 'Translation workspace is temporarily unavailable while its safe schema repair is pending.',
            'code'    => 'LGT_SCHEMA_NOT_READY',
            'schema'  => $schema,
        ], 503));
    }

    /** @param array<string, mixed> $schema */
    private function recoveryPage(array $schema)
    {
        return response()->view('LongosGlobalTranslate::admin.not_ready', compact('schema'), 503)
            ->header('Cache-Control', 'private, no-store');
    }

    private function actorId(): ?int
    {
        return current_user()?->id ? (int) current_user()->id : null;
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function audit(string $action, string $type, ?int $id, array $before, array $after): void
    {
        try {
            DB::table('lgt_audit_logs')->insert([
                'actor_id' => $this->actorId(), 'action' => $action, 'subject_type' => $type, 'subject_id' => $id,
                'before'   => json_encode($before), 'after' => json_encode($after), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // An audit outage must not convert an atomic primary write into a
            // partial configuration update. The primary error still surfaces.
        }
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
