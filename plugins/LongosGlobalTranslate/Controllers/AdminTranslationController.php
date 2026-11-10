<?php

namespace Plugin\LongosGlobalTranslate\Controllers;

use Beike\Admin\Http\Controllers\Controller;
use Beike\Services\VisitorCurrencyService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Plugin\LongosGlobalTranslate\Services\ContentScanner;
use Plugin\LongosGlobalTranslate\Services\CountryLocaleCatalog;
use Plugin\LongosGlobalTranslate\Services\LocaleCoverageService;
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
        private readonly LocaleCoverageService $coverage,
        private readonly VisitorCurrencyService $visitorCurrency,
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
        $warnings  = [];
        $jobs      = $this->safeDashboardValue('live job status', fn (): array => $this->jobSummaries(), [], $warnings);

        $runtime = $this->runtimeReleaseHealth();
        $health  = $dashboard['library_health'];
        $issues  = [];
        if ($runtime['asset_current'] === false) {
            $issues[] = '前端 widget.js 或 widget.css 与插件源码不同步：请通过 LONGOS 正式发布流程同步 public/plugin 静态资源并刷新 CDN。';
        }
        if ($runtime['asset_current'] === null) {
            $issues[] = '未找到可对比的前端静态脚本：请检查插件资源是否发布到 public/plugin/longos_global_translate。';
        }
        if ($runtime['translation_workers'] < 8) {
            $issues[] = "当前翻译并发仅为 {$runtime['translation_workers']}：若非内存受限主机，请在 Coolify 将 LONGOS_TRANSLATION_WORKERS 设为默认值 12 并重新部署。";
        }
        if (empty($dashboard['settings']['enabled_locales'])) {
            $issues[] = '还没有保存目标语言：先启用并保存至少一种语言。';
        }
        if ($health['source_entries'] === 0) {
            $issues[] = '翻译索引没有公开原文：请执行“扫描并创建翻译任务”，检查扫描错误。';
        }
        if ($dashboard['settings']['mode'] === TranslationSettings::MODE_FREE_ONLY && empty($dashboard['diagnostics']['dispatch_enabled'])) {
            $issues[] = '仅免费额度模式尚未开启已验证服务商的派发授权，翻译任务会暂停。';
        }
        if (collect($jobs)->contains(static fn (array $job): bool => in_array((string) ($job['status'] ?? ''), ['queued', 'running'], true)
            && (int) ($job['completed_items'] ?? 0) === 0)) {
            $issues[] = '存在尚未产出结果的队列任务：检查 queue worker 运行状态及任务失败原因；单凭任务已创建不代表翻译成功。';
        }
        if ($health['approved'] === 0) {
            $issues[] = '目前没有审核通过的译文：翻译任务必须完成，再审核合格译文。';
        }
        if ($health['published_languages'] === 0) {
            $issues[] = '没有为已启用语言发布包含有效译文的词典：需执行审核并发布。';
        }
        $commerceExpected       = (int) ($health['commerce_ui_expected'] ?? 0);
        $commerceSources        = (int) ($health['commerce_ui_sources'] ?? 0);
        $commerceReadyLanguages = (int) ($health['commerce_ui_ready_languages'] ?? 0);
        $enabledLocaleCount     = count((array) ($dashboard['settings']['enabled_locales'] ?? []));
        if ($commerceExpected > 0 && $commerceSources < $commerceExpected) {
            $issues[] = "结账、账户与支付的固定界面词典尚未完整扫描（{$commerceSources}/{$commerceExpected}）：创建任务时请保留“全前台固定界面文案”。";
        } elseif ($commerceExpected > 0 && $enabledLocaleCount > 0 && $commerceReadyLanguages < $enabledLocaleCount) {
            $issues[] = "结账、账户与支付的固定界面词典尚未完整发布到全部启用语言（{$commerceReadyLanguages}/{$enabledLocaleCount}）：商品词典已发布并不表示关键结账页面已翻译。";
        }
        if (! ($health['presentation_enabled'] ?? false)) {
            $issues[] = '前端本地化展示尚未开启：请启用地区自动语言匹配、浏览器语言回退或语言选择器。';
        }
        $geoDiagnostics = (array) ($dashboard['diagnostics']['geo_localization'] ?? []);
        if (($dashboard['settings']['geo_auto_switch_enabled'] ?? false)
            && ! ($geoDiagnostics['detection_ready'] ?? false)) {
            $issues[] = '已开启 IP 自动本地化，但当前请求未检测到可信 Cloudflare 国家头或可用 GeoLite2 数据库；请检查 Cloudflare 访客位置头和 Caddy 信任链。';
        }

        return $this->json([
            'ready'             => true,
            'runtime'           => $runtime,
            'actionable_issues' => $issues,
            'generated_at'      => now()->toIso8601String(),
            'diagnostics'       => $dashboard['diagnostics'],
            'library_health'    => $dashboard['library_health'],
            'jobs'              => $jobs,
            'accounts'          => $dashboard['accounts'],
            'publications'      => count($dashboard['publications']),
            'workspace_alerts'  => array_values(array_unique([...$dashboard['workspace_warnings'], ...$warnings])),
        ]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->requireSchema();
        $data = $request->validate([
            'mode'                           => ['required', 'string'],
            'source_locale'                  => ['required', 'string', 'max:24'],
            'enabled_locales'                => ['present', 'array', 'max:24'],
            'enabled_locales.*'              => ['string', 'max:24'],
            'geo_auto_switch_enabled'        => ['boolean'],
            'accept_language_enabled'        => ['boolean'],
            'publication'                    => ['nullable', 'array'],
            'publication.auto_publish_safe'  => ['boolean'],
            'publication.auto_enable_widget' => ['boolean'],
            'widget'                         => ['required', 'array'],
            'widget.enabled'                 => ['boolean'],
            'widget.position'                => ['required', 'string'],
            'widget.bottom_offset'           => ['nullable', 'integer', 'min:16', 'max:240'],
            'widget.mobile_bottom_offset'    => ['nullable', 'integer', 'min:16', 'max:260'],
            'widget.theme'                   => ['required', 'string'],
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
        $data = $request->validate($this->providerRules(true));
        /** @var array<string, string> $credentials */
        $credentials = $this->submittedCredentials((array) $data['credentials']);
        $account     = $this->accounts->save($data, $credentials, $this->actorId());

        return $this->json([
            'account' => $account,
            'notice'  => 'API 凭据已加密保存，派发保持关闭。请先由管理员完成受控验证，再显式开启内容派发。',
        ], 201);
    }

    /**
     * Change quota, priority and other operational limits without sending an
     * existing credential back to the browser. Provider and account identity
     * deliberately remain immutable: they key the durable usage ledger and
     * audit history. A newly supplied complete credential pair replaces the
     * encrypted pair and consequently requires a fresh verification.
     */
    public function updateProvider(Request $request, int $provider): JsonResponse
    {
        $this->requireSchema();
        $data = $request->validate($this->providerRules(false));
        /** @var array<string, string> $credentials */
        $credentials = $this->submittedCredentials((array) ($data['credentials'] ?? []));
        $account     = $this->accounts->update($provider, $data, $credentials, $this->actorId());
        // A quota/limit edit is an explicit administrator action. When it
        // leaves an already verified, dispatch-enabled account usable, resume
        // any free-only work that was safely paused for capacity instead of
        // making the operator rediscover and recreate the job.
        $resumed = (bool) ($account['enabled'] ?? false)
            && (bool) ($account['dispatch_enabled'] ?? false)
            && ! empty($account['verified_at'])
            ? $this->jobs->resumePausedJobs() : 0;

        return $this->json([
            'account'      => $account,
            'resumed_jobs' => $resumed,
            'notice'       => $credentials === []
                ? ($resumed > 0
                    ? "服务商额度与运行设置已更新；已自动恢复 {$resumed} 个等待安全容量的任务。"
                    : '服务商额度与运行设置已更新；已加密凭据和当前验证状态未被回传或改写。')
                : '新的 API 凭据已加密保存。为保护额度，已关闭派发并需要重新验证。',
        ]);
    }

    public function deleteProvider(Request $request, int $provider): JsonResponse
    {
        $this->requireSchema();
        $request->validate(['confirm_delete' => ['required', 'accepted']]);
        $this->accounts->archive($provider, $this->actorId());

        return $this->json([
            'deleted' => true,
            'notice'  => '服务商账户已移除，保存的 API 密钥已清除。历史用量和审计记录已保留。',
        ]);
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
        $data  = $request->validate(['scope' => ['present', 'array', 'max:7'], 'scope.*' => ['in:product,category,page,page_category,builder,chrome,commerce_ui']]);
        $scope = $this->normalizeScope($data['scope'] ?: ['product', 'category', 'page', 'page_category', 'builder', 'chrome', 'commerce_ui']);

        return $this->json(['report' => $this->scanner->scan($scope, $this->actorId())]);
    }

    public function plan(Request $request): JsonResponse
    {
        $this->requireSchema();
        $data = $request->validate([
            'locales'   => ['required', 'array', 'min:1', 'max:24'],
            'locales.*' => ['string', 'max:24'],
            'scope'     => ['required', 'array', 'min:1', 'max:7'],
            'scope.*'   => ['in:product,category,page,page_category,builder,chrome,commerce_ui'],
        ]);

        return $this->json($this->jobs->plan($data['locales'], $this->normalizeScope($data['scope']), $this->actorId()), 201);
    }

    /**
     * Recover only provider attempts that the durable usage ledger marks as
     * definitively unbilled. In particular, timeouts and response-validation
     * failures remain deliberately unavailable here: retrying those might
     * charge a provider twice.
     */
    public function retryDefiniteFailures(Request $request, int $job): JsonResponse
    {
        $this->requireSchema();
        $request->validate(['confirm_safe_retry' => ['accepted']]);
        $requeued = $this->jobs->retryDefiniteFailures($job);

        return $this->json([
            'requeued' => $requeued,
            'notice'   => $requeued > 0
                ? "已重新排队 {$requeued} 条确认未计费的失败项；不确定结果不会被重新发送。"
                : '当前任务没有可安全重试的失败项。可能需要先修复账户，或人工处理不确定结果。',
        ]);
    }

    public function cancelJob(Request $request, int $job): JsonResponse
    {
        $this->requireSchema();
        $request->validate(['confirm_cancel' => ['accepted']]);

        try {
            $result = $this->jobs->cancelJob($job, $this->actorId());
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['job' => match ($exception->getMessage()) {
                'JOB_NOT_FOUND'        => '任务不存在或已从列表删除。',
                'JOB_ALREADY_FINISHED' => '任务已经结束，可直接从列表删除。',
                default                => '任务停止失败，请刷新并重试。',
            }]);
        }

        return $this->json(['result' => $result, 'notice' => $result['inflight'] > 0
            ? "已取消后续派发，仍有 {$result['inflight']} 条已领取的请求在收尾。待其结束后可删除任务或清理译文。"
            : '任务已停止，未处理条目不再派发。现在可以删除任务或清理该语言翻译库。']);
    }

    public function archiveJob(Request $request, int $job): JsonResponse
    {
        $this->requireSchema();
        $request->validate(['confirm_archive' => ['accepted']]);

        try {
            $this->jobs->archiveJob($job, $this->actorId());
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['job' => match ($exception->getMessage()) {
                'JOB_MUST_BE_STOPPED'       => '请先停止任务，再执行删除。',
                'JOB_HAS_INFLIGHT_REQUESTS' => '仍有已发送或正在处理的请求，完成收尾后才能删除任务。',
                'JOB_NOT_FOUND'             => '任务已删除或不存在。',
                default                     => '任务删除失败，请刷新后重试。',
            }]);
        }

        return $this->json(['deleted' => true, 'notice' => '任务已从列表移除；计费、请求与审计记录继续保留。']);
    }

    public function recoverJobClaims(Request $request, int $job): JsonResponse
    {
        $this->requireSchema();
        $request->validate(['confirm_recovery' => ['accepted']]);

        try {
            $result = $this->jobs->recoverTimedOutClaims($job, $this->actorId());
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['job' => match ($exception->getMessage()) {
                'JOB_CLAIMS_NOT_STALE' => '请求尚未超过 30 分钟的安全等待期，或缺少领取时间；不能贸然清理。',
                'JOB_MUST_BE_STOPPED'  => '请先停止任务，才能恢复超时中的请求。',
                default                => '超时请求恢复失败，请查看队列运行情况。',
            }]);
        }

        return $this->json(['result' => $result,
            'notice'                 => "已安全结束 {$result['recovered']} 条超时领取记录，剩余 {$result['remaining']} 条。结果不确定的服务商请求不会自动重试。"]);
    }

    /**
     * Small, searchable, paginated review queue. Avoid downloading 100+ raw
     * translations (and a merchant's entire inventory) on every click.
     */
    public function translations(Request $request): JsonResponse
    {
        $this->requireSchema();
        $data = $request->validate([
            'locale' => ['nullable', 'string', 'max:24', 'regex:/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8}){0,2}$/'],
            'status' => ['nullable', 'in:pending,translated,needs_review,approved,stale'],
            'kind'   => ['nullable', 'in:product,category,page,page_category,builder,chrome,commerce_ui'],
            'q'      => ['nullable', 'string', 'max:100'],
            'page'   => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $page    = (int) ($data['page'] ?? 1);
        $perPage = 25;
        $locale  = ! empty($data['locale']) ? TranslationSettings::locale((string) $data['locale']) : null;
        // With one selected locale include scanned sources that have no
        // translation yet, rather than displaying a misleading empty library.
        // The all-language view remains bounded to actual translation rows.
        $query = DB::table('lgt_entries as e');
        if ($locale !== null) {
            $query->leftJoin('lgt_translations as t', static function ($join) use ($locale): void {
                $join->on('e.id', '=', 't.entry_id')->where('t.locale', '=', $locale);
            });
        } else {
            $query->join('lgt_translations as t', 'e.id', '=', 't.entry_id');
        }
        $query->where('e.is_public', true)->where('e.is_tombstoned', false);
        if (! empty($data['status'])) {
            if ($data['status'] === 'pending' && $locale !== null) {
                $query->where(static function ($builder): void {
                    $builder->whereNull('t.id')->orWhere('t.status', 'pending');
                });
            } else {
                $query->where('t.status', $data['status']);
            }
        }
        if (! empty($data['kind'])) {
            $query->where('e.kind', $data['kind']);
        }
        if (! empty($data['q'])) {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($data['q']));
            $query->where(static function ($builder) use ($search): void {
                $builder->where('e.source_text', 'like', '%' . $search . '%')
                    ->orWhere('t.text', 'like', '%' . $search . '%');
            });
        }
        $total = (clone $query)->count();
        $items = $query->orderByDesc('e.id')->orderBy('t.locale')->forPage($page, $perPage)->get([
            't.id', 't.locale', 't.text', 't.status', 't.locked', 't.version', 't.quality_code',
            't.source_hash as translation_hash', 't.updated_at', 'e.kind', 'e.entity_id',
            'e.field', 'e.source_text', 'e.source_hash',
        ]);

        return $this->json([
            'items' => $items->map(static function (object $item) use ($locale): object {
                $item->locale = $item->locale ?: $locale;
                $item->status = $item->status ?: 'pending';

                return $item;
            }), 'page' => $page, 'per_page' => $perPage,
            'total' => $total, 'has_more' => $page * $perPage < $total,
        ]);
    }

    public function deleteTranslations(Request $request): JsonResponse
    {
        $this->requireSchema();
        $data = $request->validate([
            'ids'          => ['required', 'array', 'min:1', 'max:100'],
            'ids.*'        => ['required', 'integer', 'min:1', 'distinct'],
            'confirmation' => ['required', 'string', 'max:40'],
        ]);
        if (! hash_equals('DELETE ' . count($data['ids']), trim($data['confirmation']))) {
            throw ValidationException::withMessages(['confirmation' => '删除确认文字不匹配。']);
        }

        try {
            $result = $this->library->deleteTranslations($data['ids'], $this->actorId());
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['ids' => $this->libraryDeletionError($exception)]);
        }

        return $this->json(['deleted' => true, 'result' => $result]);
    }

    public function resetLibraries(Request $request): JsonResponse
    {
        $this->requireSchema();
        $data = $request->validate([
            'locales'      => ['required', 'array', 'min:1', 'max:24'],
            'locales.*'    => ['required', 'string', 'max:24', 'distinct', 'regex:/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8}){0,2}$/'],
            'confirmation' => ['required', 'string', 'max:200'],
        ]);
        $locales = array_values(array_unique(array_map(static fn ($locale) => TranslationSettings::locale($locale), $data['locales'])));
        sort($locales, SORT_STRING);
        if (! hash_equals('RESET ' . strtoupper(implode(',', $locales)), trim($data['confirmation']))) {
            throw ValidationException::withMessages(['confirmation' => '语言列表与确认文字不一致，未删除任何数据。']);
        }

        try {
            $result = $this->library->resetLocales($locales, $this->actorId());
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['locales' => $this->libraryDeletionError($exception)]);
        }

        return $this->json(['deleted' => true, 'result' => $result]);
    }

    private function libraryDeletionError(\RuntimeException $exception): string
    {
        return match ($exception->getMessage()) {
            'SOURCE_LOCALE_CANNOT_BE_RESET' => '不能删除英语原文。',
            'LOCALE_HAS_ACTIVE_JOB'         => '所选语言有待执行、运行或暂停的任务。请先停止对应任务。',
            'LOCALE_HAS_INFLIGHT_REQUEST'   => '仍有已领取的翻译请求；请等待任务收尾后再删除。',
            'TRANSLATION_SELECTION_CHANGED' => '条目已发生变化，请重新查询并选择。',
            default                         => '删除操作失败，未完成提交。请刷新后重试。',
        };
    }

    public function resetLibrary(Request $request, string $locale): JsonResponse
    {
        $this->requireSchema();
        $locale = TranslationSettings::locale($locale);
        $data   = $request->validate(['confirmation' => ['required', 'string', 'max:40']]);
        if (! hash_equals('RESET ' . strtoupper($locale), trim((string) $data['confirmation']))) {
            throw ValidationException::withMessages(['confirmation' => '确认文字不正确，未删除任何数据。']);
        }

        try {
            $result = $this->library->resetLocale($locale, $this->actorId());
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['locale' => $this->libraryDeletionError($exception)]);
        }

        return $this->json(['deleted' => true, 'result' => $result]);
    }

    /** Brand/SKU protection for future provider requests; never auto-resends existing text. */
    public function saveProtectedTerm(Request $request): JsonResponse
    {
        $this->requireSchema();
        $data = $request->validate(['term' => ['required', 'string', 'min:2', 'max:120']]);
        $term = trim((string) $data['term']);
        if (preg_match('/[<>\r\n]/u', $term) || str_contains($term, '__LGT_')) {
            throw ValidationException::withMessages(['term' => '保护词不能包含 HTML 标记、换行或系统占位符。']);
        }
        if (mb_strlen($term, 'UTF-8') < 2) {
            throw ValidationException::withMessages(['term' => '保护词至少需要两个字符。']);
        }
        $record = DB::transaction(static function () use ($term) {
            $existing = DB::table('lgt_glossary_terms')->where('source_locale', 'en')
                ->whereNull('target_locale')->where('term', $term)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }
            $count = DB::table('lgt_glossary_terms')->where('source_locale', 'en')
                ->whereNull('target_locale')->where('protect', true)->count();
            if ($count >= 200) {
                throw ValidationException::withMessages(['term' => '保护词最多 200 条，请先删除不再使用的词条。']);
            }
            $id = DB::table('lgt_glossary_terms')->insertGetId([
                'source_locale' => 'en', 'target_locale' => null, 'term' => $term,
                'replacement'   => $term, 'protect' => true,
                'created_at'    => now(), 'updated_at' => now(),
            ]);

            return DB::table('lgt_glossary_terms')->where('id', $id)->first();
        }, 3);
        $this->audit('glossary_term_saved', 'glossary', (int) $record->id, [], ['term' => $term]);

        return $this->json(['term' => ['id' => (int) $record->id, 'term' => (string) $record->term]]);
    }

    public function deleteProtectedTerm(int $term): JsonResponse
    {
        $this->requireSchema();
        $deleted = DB::table('lgt_glossary_terms')->where('id', $term)
            ->where('source_locale', 'en')->whereNull('target_locale')->where('protect', true)->delete();
        if ($deleted === 0) {
            throw ValidationException::withMessages(['term' => '该保护词不存在或已删除。']);
        }
        $this->audit('glossary_term_deleted', 'glossary', $term, [], []);

        return $this->json(['deleted' => true]);
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

    public function quickPublish(Request $request): JsonResponse
    {
        $this->requireSchema();
        $data = $request->validate([
            'locale'              => ['required', 'string', 'max:24'],
            'confirm_auto_review' => ['accepted'],
            'enable_widget'       => ['boolean'],
        ]);
        $locale   = TranslationSettings::locale($data['locale']);
        $settings = $this->settings->all();
        if (! in_array($locale, $settings['enabled_locales'], true)) {
            throw ValidationException::withMessages(['locale' => '请先在展示语言设置中启用此语言。']);
        }
        $result = $this->publications->publishReady($locale, $this->actorId(), $this->library);
        if ((bool) ($data['enable_widget'] ?? false)) {
            $this->settings->save([...$settings, 'widget' => [...$settings['widget'], 'enabled' => true]]);
        }
        $this->audit('safe_translations_published', 'publication', (int) $result['publication']['id'], [], [
            'locale'         => $locale, 'auto_approved' => $result['auto_approved'],
            'widget_enabled' => (bool) ($data['enable_widget'] ?? false),
        ]);

        return $this->json($result, 201);
    }

    public function rollback(Request $request, int $publication): JsonResponse
    {
        $this->requireSchema();
        $data = $request->validate(['locale' => ['required', 'string', 'max:24']]);

        return $this->json(['publication' => $this->publications->rollback($data['locale'], $publication, $this->actorId())], 201);
    }

    /** @return array<string, array<int, string>> */
    private function providerRules(bool $creating): array
    {
        return [
            'provider'              => $creating ? ['required', 'in:' . implode(',', ProviderAccountService::PROVIDERS)] : ['prohibited'],
            'billing_identity'      => $creating ? ['required', 'string', 'max:255'] : ['prohibited'],
            'account_tier'          => ['nullable', 'string', 'max:48'],
            'free_quota'            => ['nullable', 'integer', 'min:0', 'max:10000000000'],
            'soft_cap'              => ['nullable', 'integer', 'min:0', 'max:10000000000'],
            'external_usage_buffer' => ['nullable', 'integer', 'min:0', 'max:10000000000'],
            'cycle_timezone'        => ['nullable', 'string', 'max:64'],
            'qps_limit'             => ['nullable', 'integer', 'min:1', 'max:10'],
            'rpm_limit'             => ['nullable', 'integer', 'min:1', 'max:10000'],
            'tpm_limit'             => ['nullable', 'integer', 'min:1', 'max:5000000'],
            'failover_priority'     => ['nullable', 'integer', 'min:1', 'max:1000'],
            'enabled'               => ['boolean'],
            'source_link'           => ['nullable', 'url', 'max:512'],
            'credentials'           => $creating ? ['required', 'array'] : ['nullable', 'array'],
            'credentials.*'         => ['nullable', 'string', 'max:1024'],
        ];
    }

    /** @param array<string, mixed> $credentials @return array<string, string> */
    private function submittedCredentials(array $credentials): array
    {
        return array_filter($credentials, static fn (mixed $value): bool => is_string($value) && trim($value) !== '');
    }

    /**
     * Release diagnostic: do not read source text, provider credentials, or
     * a browser-supplied path. This checks the exact installed artifact that
     * the immutable LONGOS container is serving, not the uploaded ZIP name.
     * @return array{version:string,asset_current:?bool,queue_connection:string,translation_workers:int}
     */
    private function runtimeReleaseHealth(): array
    {
        $root       = dirname(__DIR__);
        $manifest   = json_decode((string) @file_get_contents($root . '/config.json'), true);
        $assetPairs = [
            [$root . '/Static/public/widget.js', public_path('plugin/longos_global_translate/widget.js')],
            [$root . '/Static/public/widget.css', public_path('plugin/longos_global_translate/widget.css')],
        ];
        $allPresent = collect($assetPairs)->every(static fn (array $pair): bool => is_file($pair[0]) && is_file($pair[1]));

        return [
            'version'       => (string) (is_array($manifest) ? ($manifest['version'] ?? 'unknown') : 'unknown'),
            'asset_current' => $allPresent
                ? collect($assetPairs)->every(static fn (array $pair): bool => hash_equals(
                    (string) hash_file('sha256', $pair[0]), (string) hash_file('sha256', $pair[1]),
                )) : null,
            'queue_connection'    => (string) config('queue.default', 'unknown'),
            'translation_workers' => min(24, max(1, (int) config('longos.translation.workers', 12))),
        ];
    }

    /** @return array<string, mixed> */
    private function dashboard(): array
    {
        $warnings     = [];
        $settings     = $this->safeDashboardValue('settings', fn (): array => $this->settings->all(), $this->defaultSettings(), $warnings);
        $latest       = $this->safeDashboardValue('coverage', fn (): mixed => DB::table('lgt_coverage_snapshots')->latest('id')->value('report'), null, $warnings);
        $accounts     = $this->safeDashboardValue('provider accounts', fn (): array => $this->accounts->publicAccounts(), [], $warnings);
        // The first page render needs database rows because the Blade history
        // table intentionally reads object properties.  The credential-free
        // JSON projection used by the live-poll endpoint is built separately
        // in workspaceStatus(); mixing those contracts caused a 500 whenever
        // a tenant already had a task history.
        $jobs           = $this->safeDashboardValue('job history', fn () => DB::table('lgt_jobs')->whereNull('archived_at')->latest('id')->limit(12)->get(), collect(), $warnings);
        $publications   = $this->safeDashboardValue('publication history', fn () => DB::table('lgt_publications')->orderByDesc('published_at')->limit(24)->get(), collect(), $warnings);
        $countryRules   = $this->safeDashboardValue('country defaults', fn () => DB::table('lgt_country_rules')->orderBy('country')->limit(250)->get(), collect(), $warnings);
        $countries      = $this->safeDashboardValue('system countries', fn (): array => $this->countries->enabledCountries(), [], $warnings);
        $glossaryTerms  = $this->safeDashboardValue('protected glossary', fn () => DB::table('lgt_glossary_terms')->where('source_locale', 'en')->whereNull('target_locale')->where('protect', true)->orderBy('term')->limit(200)->get(['id', 'term']), collect(), $warnings);
        $geoDiagnostics = $this->safeDashboardValue(
            'geo localization',
            fn (): array => $this->visitorCurrency->diagnostics(request()),
            [
                'detection_ready' => false, 'resolution_source' => 'unavailable',
                'blocking_issue'  => 'diagnostic_failed', 'detected_country' => null,
            ],
            $warnings,
        );

        $dispatchEnabled = collect($accounts)->contains(static fn (mixed $account): bool => (bool) data_get($account, 'dispatch_enabled', false));

        $libraryHealth = $this->safeDashboardValue(
            'library status',
            fn (): array => $this->coverage->snapshot($settings),
            ['source_entries'                  => 0, 'translated' => 0, 'approved' => 0,
                'published_languages'          => 0, 'published_items' => 0, 'widget_enabled' => false,
                'presentation_enabled'         => false, 'ready_to_display' => false, 'commerce_ui_expected' => 0,
                'commerce_ui_sources'          => 0, 'commerce_ui_ready_languages' => 0,
                'commerce_ui_ready_to_display' => false, 'locales' => []],
            $warnings,
        );

        return [
            'library_health'     => $libraryHealth,
            'settings'           => $settings,
            'accounts'           => $accounts,
            'jobs'               => $jobs,
            'publications'       => $publications,
            'countryRules'       => $countryRules,
            'countries'          => $countries,
            'glossaryTerms'      => $glossaryTerms,
            'providerProfiles'   => ProviderAccountService::profiles(),
            'latest_coverage'    => is_string($latest) ? (json_decode($latest, true) ?: []) : [],
            'workspace_warnings' => $warnings,
            'diagnostics'        => [
                'mode'                  => $settings['mode'],
                'dispatch_enabled'      => $dispatchEnabled,
                'paid_cap'              => 0,
                'provider_calls'        => $dispatchEnabled ? 'operator_verified_limits' : 'requires_operator_verification',
                'tenant_resolved'       => current_site() !== null,
                'core_locale_unchanged' => true,
                'geo_localization'      => $geoDiagnostics,
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
            'geo_auto_switch_enabled' => true,
            'accept_language_enabled' => false,
            'publication'             => ['auto_publish_safe' => true, 'auto_enable_widget' => true],
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
        return DB::table('lgt_jobs')->whereNull('archived_at')->latest('id')->limit(12)->get()->map(function (object $job): array {
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

            $plan            = json_decode((string) ($job->plan ?? ''), true);
            $autoPublication = is_array($plan) && is_array($plan['auto_publication'] ?? null)
                ? $plan['auto_publication'] : null;
            $pause = is_array($plan) && is_array($plan['pause'] ?? null) ? $plan['pause'] : null;
            // Jobs created before pause causes were persisted still need an
            // actionable explanation.  Do not invent a specific historical
            // cause; label it honestly as a legacy row instead.
            if ($pause === null && (string) $job->status === 'paused') {
                $pause = ['code' => 'PAUSE_REASON_UNAVAILABLE'];
            }

            return [
                'id'                      => (int) $job->id,
                'public_id'               => (string) $job->public_id,
                'status'                  => (string) $job->status,
                'mode'                    => (string) $job->mode,
                'total_items'             => $total,
                'completed_items'         => $completed,
                'failed_items'            => $failed,
                'progress_percent'        => $total > 0 ? min(100, (int) floor(($completed / $total) * 100)) : ((string) $job->status === 'completed' ? 100 : 0),
                'created_at'              => $this->isoTimestamp($job->created_at ?? null),
                'updated_at'              => $this->isoTimestamp($job->updated_at ?? null),
                'active'                  => in_array((string) $job->status, ['queued', 'scanning', 'running', 'pausing'], true),
                'failure_summary'         => $reasons,
                'retryable_failure_count' => $this->jobs->retryableFailureCount((int) $job->id),
                'inflight_items'          => DB::table('lgt_job_items')->where('job_id', $job->id)->where('status', 'claimed')->count(),
                'auto_publication'        => $autoPublication,
                'pause_reason'            => $pause === null ? null : [
                    'code'  => (string) ($pause['code'] ?? 'PAUSE_REASON_UNAVAILABLE'),
                    'label' => $this->pauseLabel((string) ($pause['code'] ?? 'PAUSE_REASON_UNAVAILABLE')),
                ],
            ];
        })->all();
    }

    private function failureLabel(string $code): string
    {
        if (str_starts_with($code, 'BAIDU_') || str_starts_with($code, 'ALIYUN_') || str_starts_with($code, 'VOLCENGINE_')
                                             || str_starts_with($code, 'DEEPL_') || str_starts_with($code, 'GOOGLE_') || str_starts_with($code, 'AZURE_')
                                             || str_starts_with($code, 'SILICONFLOW_')) {
            return '服务商返回错误码 ' . $code . '；请检查账户权限、区域、语种、额度或服务状态。为避免重复计费，未对已发送请求自动重试。';
        }

        return match ($code) {
            'AUTH'                 => '服务商凭据被拒绝，请检查 API 密钥与应用权限。',
            'NOT_OPENED'           => '服务商翻译服务尚未在该账号开通。',
            'UNSUPPORTED_LANGUAGE' => '该服务商不支持此目标语言。',
            'RATE_LIMIT'           => '服务商请求过于频繁，请降低 QPS 或稍后再试。',
            'BALANCE_EXHAUSTED', 'LOCAL_BUDGET_EXHAUSTED', 'FREE_QUOTA_EXHAUSTED_AFTER_PARTIAL_SEGMENT' => '可用免费额度已耗尽。',
            'INVALID_INPUT'      => '源文本不符合服务商的输入要求。',
            'UNSAFE_TRANSLATION' => '译文包含不允许的标记，已安全拒绝。',
            'STALE_SOURCE'       => '原文在翻译期间已变更，请重新扫描。',
            'NETWORK_AFTER_SEND', 'WORKER_EXCEPTION', 'WORKER_EXHAUSTED' => '请求结果不确定，为避免重复计费已停止自动重试。',
            'RATE_LIMIT_AFTER_PARTIAL_SEGMENT', 'JOB_STOPPED_AFTER_PARTIAL_SEGMENT' => '该字段已有部分片段被服务商接受；为避免重复计费，系统未重放整个字段，请人工复核后重新提交。',
            'PRIOR_BILLING_OUTCOME_UNRESOLVED' => '同一原文存在已发送但未确认或已计费却未保存的请求；为防重复收费已阻止新调用，请人工核对。',
            'PRE_SEND_FAILURE'                 => '请求在发送前失败，请检查服务商配置后重试。',
            'PROVIDER_CREDENTIALS_MISSING', 'PROVIDER_CREDENTIALS_UNREADABLE', 'INVALID_PROVIDER_CREDENTIALS' => '服务商凭据缺失、无法解密或格式不正确；未发出翻译请求。',
            'PROVIDER_RATE_LIMIT_CLOCK_SKEW', 'PROVIDER_RATE_LIMIT_WAIT_INTERRUPTED' => '本地 QPS 安全限制异常，未发出请求；请检查服务器时间和账户 QPS 设置。',
            'PROTECTED_TOKEN_CHANGED'     => '这是旧版任务的保护占位符失配记录。新任务已改为本地切片与重组，链接、价格、变量和邮箱不再发给服务商；请在发布新版后新建增量任务。',
            'UNKNOWN_PROTECTED_TOKEN'     => '译文出现非法的保护占位符，译文已被安全拒绝。',
            'PROVIDER_TOKEN_INCOMPATIBLE' => '旧版服务商验证使用过保护占位符；新版已改用本地切片，请重新验证该账户。',
            'PROVIDER_RESPONSE_UNSAFE'    => '服务商验证返回了空内容、无效编码或 HTML 标记，未通过安全检查。',
            'INVALID_TRANSLATION_TEXT'    => '译文为空、过长或不是有效 UTF-8，已被安全拒绝。',
            'SOURCE_TEXT_REJECTED'        => '原文不符合安全文本契约，已在发送给服务商前拦截，未产生翻译请求。',
            'SEGMENT_TOO_LARGE', 'ATOMIC_TOKEN_TOO_LARGE' => '源文本中含有超过服务商安全长度的不可拆分片段，请人工缩短或排除。',
            'TRANSLATION_ROW_UNAVAILABLE', 'ENTRY_INSERT_FAILED' => '翻译库写入未完成；请稍后重试或查看系统日志。',
            'PROCESSING_EXCEPTION' => '发生未归类的本地处理异常；为避免重复计费已停止自动重试。请查看该任务时间点的应用日志。',
            default                => '未识别的失败代码 ' . $code . '；请保留任务 ID 并查看同一时间的应用日志，不会将已发送请求盲目重试。',
        };
    }

    private function pauseLabel(string $code): string
    {
        return match ($code) {
            'FREE_QUOTA_EXHAUSTED'          => '所有支持该语言的已验证账户均达到本周期本地免费额度上限；提高任一账户额度或开启备用账户后会自动恢复。',
            'NO_VERIFIED_DISPATCH_ACCOUNT'  => '没有已启用、已验证且允许派发的翻译账户；验证并开启至少一个账户后会自动恢复。',
            'NO_PROVIDER_FOR_TARGET_LOCALE' => '现有可派发账户均不支持当前目标语言；添加支持该语种的账户后会自动恢复。',
            'DISPATCH_MODE_CHANGED'         => '翻译运行模式已变更为非“仅免费额度”；恢复该模式后可继续执行。',
            'SILICONFLOW_RATE_WINDOW'       => '硅基流动免费模型达到当前分钟速率窗口；系统已安排冷却后自动恢复，无需重复创建任务。',
            default                         => '该任务在旧版本中暂停，未保存具体原因；请检查账户派发状态与本周期免费额度。',
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

    /**
     * Header/menu/footer copy belongs to the same immutable published visual
     * state as Builder modules. Keep it coupled to Builder runs so an
     * operator cannot accidentally publish a translated homepage whose
     * navigation remains in the old language. Explicit chrome-only runs are
     * still supported for a small navigation/footer correction.
     *
     * @param list<string> $scope
     * @return list<string>
     */
    private function normalizeScope(array $scope): array
    {
        $scope = array_values(array_unique($scope));
        if (in_array('builder', $scope, true) && ! in_array('chrome', $scope, true)) {
            $scope[] = 'chrome';
        }

        return $scope;
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
