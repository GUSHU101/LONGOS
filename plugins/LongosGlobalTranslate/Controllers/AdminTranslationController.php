<?php

namespace Plugin\LongosGlobalTranslate\Controllers;

use Beike\Admin\Http\Controllers\Controller;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Plugin\LongosGlobalTranslate\Services\ContentScanner;
use Plugin\LongosGlobalTranslate\Services\LocaleCoverageService;
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
        private readonly LocaleCoverageService $coverage,
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
        $health = $dashboard['library_health'];
        $issues = [];
        if ($runtime['asset_current'] === false) {
            $issues[] = '前端 widget.js 与插件源码不同步：ZIP 覆盖不会自动重新发布 public/plugin 静态资源。请通过 LONGOS 的正式 Git/Coolify 发布流程同步插件与资源。';
        }
        if ($runtime['asset_current'] === null) {
            $issues[] = '未找到可对比的前端静态脚本：请检查插件资源是否发布到 public/plugin/longos_global_translate。';
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
        if (! $health['widget_enabled']) {
            $issues[] = '前台语言选择器处于关闭状态：发布成功后还需开启。';
        }

        return $this->json([
            'ready'            => true,
            'runtime'          => $runtime,
            'actionable_issues'=> $issues,
            'generated_at'     => now()->toIso8601String(),
            'diagnostics'      => $dashboard['diagnostics'],
            'library_health'   => $dashboard['library_health'],
            'jobs'             => $jobs,
            'accounts'         => $dashboard['accounts'],
            'publications'     => count($dashboard['publications']),
            'workspace_alerts' => array_values(array_unique([...$dashboard['workspace_warnings'], ...$warnings])),
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
            'geo_auto_switch_enabled'     => ['boolean'],
            'accept_language_enabled'     => ['boolean'],
            'publication'                 => ['nullable', 'array'],
            'publication.auto_publish_safe' => ['boolean'],
            'publication.auto_enable_widget' => ['boolean'],
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

    /**
     * Small, searchable, paginated review queue. Avoid downloading 100+ raw
     * translations (and a merchant's entire inventory) on every click.
     */
    public function translations(Request $request): JsonResponse
    {
        $this->requireSchema();
        $data = $request->validate([
            'locale' => ['nullable', 'string', 'max:24'],
            'status' => ['nullable', 'in:pending,translated,needs_review,approved,stale'],
            'kind'   => ['nullable', 'in:product,category,page,builder,commerce_ui'],
            'q'      => ['nullable', 'string', 'max:100'],
            'page'   => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $page = (int) ($data['page'] ?? 1);
        $perPage = 25;
        $query = DB::table('lgt_translations as t')->join('lgt_entries as e', 'e.id', '=', 't.entry_id')
            ->where('e.is_public', true)->where('e.is_tombstoned', false);
        if (! empty($data['locale'])) {
            $query->where('t.locale', TranslationSettings::locale((string) $data['locale']));
        }
        if (! empty($data['status'])) {
            $query->where('t.status', $data['status']);
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
        $items = $query->orderByDesc('t.id')->forPage($page, $perPage)->get([
            't.id', 't.locale', 't.text', 't.status', 't.locked', 't.version', 't.quality_code',
            't.source_hash as translation_hash', 't.updated_at', 'e.kind', 'e.entity_id',
            'e.field', 'e.source_text', 'e.source_hash',
        ]);

        return $this->json([
            'items' => $items, 'page' => $page, 'per_page' => $perPage,
            'total' => $total, 'has_more' => $page * $perPage < $total,
        ]);
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
                'replacement' => $term, 'protect' => true,
                'created_at' => now(), 'updated_at' => now(),
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
            'locale' => ['required', 'string', 'max:24'],
            'confirm_auto_review' => ['accepted'],
            'enable_widget' => ['boolean'],
        ]);
        $locale = TranslationSettings::locale($data['locale']);
        $settings = $this->settings->all();
        if (! in_array($locale, $settings['enabled_locales'], true)) {
            throw ValidationException::withMessages(['locale' => '请先在展示语言设置中启用此语言。']);
        }
        $result = $this->publications->publishReady($locale, $this->actorId(), $this->library);
        if ((bool) ($data['enable_widget'] ?? false)) {
            $this->settings->save([...$settings, 'widget' => [...$settings['widget'], 'enabled' => true]]);
        }
        $this->audit('safe_translations_published', 'publication', (int) $result['publication']['id'], [], [
            'locale' => $locale, 'auto_approved' => $result['auto_approved'],
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

    /**
     * Release diagnostic: do not read source text, provider credentials, or
     * a browser-supplied path. This checks the exact installed artifact that
     * the immutable LONGOS container is serving, not the uploaded ZIP name.
     * @return array{version:string,asset_current:?bool,queue_connection:string}
     */
    private function runtimeReleaseHealth(): array
    {
        $root = dirname(__DIR__);
        $manifest = json_decode((string) @file_get_contents($root . '/config.json'), true);
        $source = $root . '/Static/public/widget.js';
        $published = public_path('plugin/longos_global_translate/widget.js');
        return [
            'version' => (string) (is_array($manifest) ? ($manifest['version'] ?? 'unknown') : 'unknown'),
            'asset_current' => is_file($source) && is_file($published)
                ? hash_equals((string) hash_file('sha256', $source), (string) hash_file('sha256', $published))
                : null,
            'queue_connection' => (string) config('queue.default', 'unknown'),
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
        $jobs         = $this->safeDashboardValue('job history', fn () => DB::table('lgt_jobs')->latest('id')->limit(12)->get(), collect(), $warnings);
        $publications = $this->safeDashboardValue('publication history', fn () => DB::table('lgt_publications')->orderByDesc('published_at')->limit(24)->get(), collect(), $warnings);
        $countryRules = $this->safeDashboardValue('country defaults', fn () => DB::table('lgt_country_rules')->orderBy('country')->limit(250)->get(), collect(), $warnings);
        $countries    = $this->safeDashboardValue('system countries', fn (): array => $this->countries->enabledCountries(), [], $warnings);
        $glossaryTerms = $this->safeDashboardValue('protected glossary', fn () => DB::table('lgt_glossary_terms')->where('source_locale', 'en')->whereNull('target_locale')->where('protect', true)->orderBy('term')->limit(200)->get(['id','term']), collect(), $warnings);

        $dispatchEnabled = collect($accounts)->contains(static fn (mixed $account): bool => (bool) data_get($account, 'dispatch_enabled', false));

        $libraryHealth = $this->safeDashboardValue(
            'library status',
            fn (): array => $this->coverage->snapshot($settings),
            ['source_entries' => 0, 'translated' => 0, 'approved' => 0,
                'published_languages' => 0, 'published_items' => 0, 'widget_enabled' => false,
                'ready_to_display' => false, 'locales' => []],
            $warnings,
        );

        return [
            'library_health'    => $libraryHealth,
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

            $plan = json_decode((string) ($job->plan ?? ''), true);
            $autoPublication = is_array($plan) && is_array($plan['auto_publication'] ?? null)
                ? $plan['auto_publication'] : null;

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
                'auto_publication'        => $autoPublication,
            ];
        })->all();
    }

    private function failureLabel(string $code): string
    {
        if (str_starts_with($code, 'BAIDU_') || str_starts_with($code, 'ALIYUN_') || str_starts_with($code, 'VOLCENGINE_')) {
            return '服务商返回了未归类的错误；未自动重试以避免重复计费。';
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
            'PRE_SEND_FAILURE' => '请求在发送前失败，请检查服务商配置后重试。',
            'PROVIDER_CREDENTIALS_MISSING', 'PROVIDER_CREDENTIALS_UNREADABLE', 'INVALID_PROVIDER_CREDENTIALS' => '服务商凭据缺失、无法解密或格式不正确；未发出翻译请求。',
            'PROVIDER_RATE_LIMIT_CLOCK_SKEW', 'PROVIDER_RATE_LIMIT_WAIT_INTERRUPTED' => '本地 QPS 安全限制异常，未发出请求；请检查服务器时间和账户 QPS 设置。',
            'PROTECTED_TOKEN_CHANGED'  => '服务商改写了保护占位符（链接、价格、变量等），译文已被安全拒绝，请人工审核。',
            'UNKNOWN_PROTECTED_TOKEN'  => '译文出现非法的保护占位符，译文已被安全拒绝。',
            'INVALID_TRANSLATION_TEXT' => '译文为空、过长或不是有效 UTF-8，已被安全拒绝。',
            'SEGMENT_TOO_LARGE', 'ATOMIC_TOKEN_TOO_LARGE' => '源文本中含有超过服务商安全长度的不可拆分片段，请人工缩短或排除。',
            'TRANSLATION_ROW_UNAVAILABLE', 'ENTRY_INSERT_FAILED' => '翻译库写入未完成；请稍后重试或查看系统日志。',
            'PROCESSING_EXCEPTION' => '发生未归类的本地处理异常；为避免重复计费已停止自动重试。请查看该任务时间点的应用日志。',
            default                => '任务处理失败；请打开任务详情或使用小范围任务验证服务商。',
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
