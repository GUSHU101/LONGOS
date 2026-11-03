<?php

namespace Plugin\LongosGlobalTranslate\Controllers;

use Beike\Admin\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugin\LongosGlobalTranslate\Services\ContentScanner;
use Plugin\LongosGlobalTranslate\Services\ProviderAccountService;
use Plugin\LongosGlobalTranslate\Services\ProviderVerificationService;
use Plugin\LongosGlobalTranslate\Services\PublicationService;
use Plugin\LongosGlobalTranslate\Services\TranslationJobService;
use Plugin\LongosGlobalTranslate\Services\TranslationLibrary;
use Plugin\LongosGlobalTranslate\Services\TranslationSchema;
use Plugin\LongosGlobalTranslate\Services\TranslationSettings;
use Illuminate\Http\Exceptions\HttpResponseException;
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
    ) {}

    public function index()
    {
        $schema = TranslationSchema::inspect();
        if (! $schema['ready']) {
            return $this->recoveryPage($schema);
        }

        try {
            return response()->view('LongosGlobalTranslate::admin.index', $this->dashboard())
                ->header('Cache-Control', 'private, no-store');
        } catch (Throwable $exception) {
            // This plugin is presentation-only.  A damaged historical setting
            // or an unforeseen tenant-local data inconsistency must never
            // become a raw admin 500 or affect commerce routes. The exception
            // stays in the platform log for diagnosis; the browser receives
            // a non-cacheable, actionable recovery state without internals.
            report($exception);

            return $this->recoveryPage($this->runtimeRecoverySchema());
        }
    }

    public function diagnostics(): JsonResponse
    {
        $schema = TranslationSchema::inspect();
        if (! $schema['ready']) {
            return $this->json([
                'ready'             => false,
                'schema'            => $schema,
                'mode'              => TranslationSettings::MODE_PREVIEW,
                'dispatch_enabled'  => false,
                'paid_cap'          => 0,
                'provider_calls'    => 'schema_repair_required',
                'tenant_resolved'   => current_site() !== null,
                'core_locale_unchanged' => true,
            ], 503);
        }

        try {
            return $this->json($this->dashboard()['diagnostics']);
        } catch (Throwable $exception) {
            report($exception);

            return $this->json([
                'ready'             => false,
                'schema'            => $this->runtimeRecoverySchema(),
                'mode'              => TranslationSettings::MODE_PREVIEW,
                'dispatch_enabled'  => false,
                'paid_cap'          => 0,
                'provider_calls'    => 'runtime_recovery_required',
                'tenant_resolved'   => current_site() !== null,
                'core_locale_unchanged' => true,
            ], 503);
        }
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
            abort(422, 'Country-rule locale must be an enabled presentation language.');
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
            'notice'  => 'Credentials are encrypted, dispatch remains off, and an operator must complete a controlled verification before a provider can receive content.',
        ], 201);
    }

    public function verifyProvider(Request $request, int $provider): JsonResponse
    {
        $this->requireSchema();
        $request->validate(['confirm_provider_charge' => ['accepted']]);

        return $this->json([
            'account' => $this->verification->verify($provider, $this->actorId()),
            'notice'  => 'The controlled verification request succeeded. Dispatch is still off until you enable it explicitly.',
        ]);
    }

    public function dispatchProvider(Request $request, int $provider): JsonResponse
    {
        $this->requireSchema();
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'confirm_dispatch' => ['accepted_if:enabled,true']]);
        $account = $this->accounts->setDispatch($provider, (bool) $data['enabled'], $this->actorId());
        $resumed = (bool) $data['enabled'] ? $this->jobs->resumePausedJobs() : 0;

        return $this->json([
            'account' => $account,
            'resumed_jobs' => $resumed,
            'notice' => $resumed > 0
                ? "Provider dispatch enabled; {$resumed} paused free-only job(s) were resumed."
                : 'Provider dispatch setting saved.',
        ]);
    }

    public function scan(Request $request): JsonResponse
    {
        $this->requireSchema();
        $data  = $request->validate(['scope' => ['present', 'array', 'max:4'], 'scope.*' => ['in:product,category,page,builder']]);
        $scope = $data['scope'] ?: ['product', 'category', 'page', 'builder'];

        return $this->json(['report' => $this->scanner->scan($scope, $this->actorId())]);
    }

    public function plan(Request $request): JsonResponse
    {
        $this->requireSchema();
        $data = $request->validate([
            'locales'   => ['required', 'array', 'min:1', 'max:24'],
            'locales.*' => ['string', 'max:24'],
            'scope'     => ['required', 'array', 'min:1', 'max:4'],
            'scope.*'   => ['in:product,category,page,builder'],
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
        $settings = $this->settings->all();
        if (! Schema::hasTable('lgt_settings')) {
            return [
                'settings'        => $settings,
                'accounts'        => [],
                'jobs'            => collect(),
                'publications'    => collect(),
                'countryRules'    => collect(),
                'latest_coverage' => [],
                'diagnostics'     => [
                    'mode'                  => $settings['mode'],
                    'dispatch_enabled'      => false,
                    'paid_cap'              => 0,
                    'provider_calls'        => 'schema_upgrade_required',
                    'tenant_resolved'       => current_site() !== null,
                    'core_locale_unchanged' => true,
                ],
            ];
        }
        $latest   = DB::table('lgt_coverage_snapshots')->latest('id')->value('report');

        return [
            'settings'        => $settings,
            'accounts'        => $this->accounts->publicAccounts(),
            'jobs'            => DB::table('lgt_jobs')->latest('id')->limit(12)->get(),
            'publications'    => DB::table('lgt_publications')->orderByDesc('published_at')->limit(24)->get(),
            'countryRules'    => DB::table('lgt_country_rules')->orderBy('country')->limit(250)->get(),
            'latest_coverage' => is_string($latest) ? (json_decode($latest, true) ?: []) : [],
            'diagnostics'     => [
                'mode'                  => $settings['mode'],
                'dispatch_enabled'      => false,
                'paid_cap'              => 0,
                'provider_calls'        => 'requires_operator_verification',
                'tenant_resolved'       => current_site() !== null,
                'core_locale_unchanged' => true,
            ],
        ];
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

    /** @return array{ready: false, missing_tables: list<string>, missing_columns: list<string>, inspection_failed: false, runtime_recovery: true} */
    private function runtimeRecoverySchema(): array
    {
        return [
            'ready'             => false,
            'missing_tables'    => [],
            'missing_columns'   => [],
            'inspection_failed' => false,
            'runtime_recovery'  => true,
        ];
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
