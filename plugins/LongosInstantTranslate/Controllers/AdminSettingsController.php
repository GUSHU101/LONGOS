<?php

namespace Plugin\LongosInstantTranslate\Controllers;

use Beike\Repositories\SettingRepo;
use Beike\Services\CloudflareIpRangeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Plugin\LongosInstantTranslate\Jobs\ProcessTranslationLibrary;
use Plugin\LongosInstantTranslate\Jobs\ScanTranslationLibrary;
use Plugin\LongosInstantTranslate\Services\InstantTranslateSettings;
use Plugin\LongosInstantTranslate\Services\LanguageCatalog;
use Plugin\LongosInstantTranslate\Services\LibraryBuilder;
use Plugin\LongosInstantTranslate\Services\LibrarySchema;
use Plugin\LongosInstantTranslate\Services\ProviderAccountService;
use Plugin\LongosInstantTranslate\Services\TranslationLibrary;
use Plugin\LongosInstantTranslate\Services\VerifiedVisitorCountry;

final class AdminSettingsController
{
    public function index(
        Request $request,
        InstantTranslateSettings $settings,
        CloudflareIpRangeService $cloudflare,
        VerifiedVisitorCountry $country,
        ProviderAccountService $accounts,
        TranslationLibrary $library,
    ): View {
        abort_if(current_site() === null, 404);

        return view('LongosInstantTranslate::admin.index', [
            'settings'        => $settings->all(),
            'languages'       => LanguageCatalog::all(),
            'diagnostics'     => $this->diagnostics($request, $cloudflare, $country),
            'clientHealthKey' => 'longos_instant_translate_v1:' . $settings->storageScope() . ':health',
            'engine'          => [
                'version' => InstantTranslateSettings::XNX3_VERSION,
                'commit'  => InstantTranslateSettings::XNX3_COMMIT,
                'sha256'  => InstantTranslateSettings::XNX3_SHA256,
                'channel' => 'client.edge',
            ],
            'schemaReady'      => LibrarySchema::ready(),
            'providerProfiles' => ProviderAccountService::profiles(),
            'providerAccounts' => $accounts->accounts(),
            'libraryStats'     => $library->stats(),
            'scanStatus'       => Cache::get(ScanTranslationLibrary::statusKey($settings->storageScope())),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_if(current_site() === null, 404);

        $allowedLanguages = array_keys(LanguageCatalog::all());
        $validated        = $request->validate([
            'enabled_languages'    => ['nullable', 'array'],
            'enabled_languages.*'  => ['string', Rule::in($allowedLanguages)],
            'position'             => ['required', Rule::in(['left', 'right'])],
            'bottom_offset'        => ['required', 'integer', 'between:16,240'],
            'mobile_bottom_offset' => ['required', 'integer', 'between:16,260'],
            'theme'                => ['required', Rule::in(['system', 'light', 'dark'])],
            'accent_color'         => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'label_mode'           => ['required', Rule::in(['short', 'name'])],
        ]);

        SettingRepo::update('plugin', InstantTranslateSettings::CODE, [
            'runtime_enabled'        => $request->boolean('runtime_enabled') ? '1' : '0',
            'auto_country'           => $request->boolean('auto_country') ? '1' : '0',
            'browser_fallback'       => $request->boolean('browser_fallback') ? '1' : '0',
            'enabled_languages'      => LanguageCatalog::normalize($validated['enabled_languages'] ?? []),
            'position'               => $validated['position'],
            'bottom_offset'          => (string) $validated['bottom_offset'],
            'mobile_bottom_offset'   => (string) $validated['mobile_bottom_offset'],
            'theme'                  => $validated['theme'],
            'accent_color'           => strtolower($validated['accent_color']),
            'label_mode'             => $validated['label_mode'],
            'library_enabled'        => $request->boolean('library_enabled') ? '1' : '0',
            'library_build_enabled'  => $request->boolean('library_build_enabled') ? '1' : '0',
            'build_interval_seconds' => (string) max(5, min(3600, $request->integer('build_interval_seconds', 30))),
        ]);

        if ($request->boolean('library_build_enabled') && LibrarySchema::ready()) {
            app(TranslationLibrary::class)->resumeDeferred();
            ProcessTranslationLibrary::dispatch(app(InstantTranslateSettings::class)->storageScope());
        }

        return redirect(admin_route('longos_instant_translate.index'))
            ->with('success', trans('LongosInstantTranslate::instant.saved'));
    }

    public function saveProvider(Request $request, ProviderAccountService $accounts): RedirectResponse
    {
        abort_if(current_site() === null || ! LibrarySchema::ready(), 404);
        $validated = $request->validate([
            'provider'                => ['required', Rule::in(ProviderAccountService::PROVIDERS)],
            'label'                   => ['required', 'string', 'max:64'],
            'credentials'             => ['required', 'array'],
            'priority'                => ['required', 'integer', 'between:1,1000'],
            'qps_limit'               => ['required', 'integer', 'between:1,10'],
            'rpm_limit'               => ['required', 'integer', 'between:1,10000'],
            'tpm_limit'               => ['required', 'integer', 'between:1,5000000'],
            'monthly_character_limit' => ['nullable', 'integer', 'min:0'],
        ]);
        $profile     = ProviderAccountService::profiles()[$validated['provider']];
        $credentials = [];
        foreach ($profile['fields'] as $field) {
            $credentials[$field['key']] = trim((string) data_get($validated, 'credentials.' . $field['key'], $field['default'] ?? ''));
        }
        $accounts->save([
            ...$validated,
            'enabled' => $request->boolean('enabled'),
        ], $credentials);

        return back()->with('success', '服务商账户已加密保存；验证成功后才能开启慢速建库。');
    }

    public function verifyProvider(int $account, LibraryBuilder $builder): RedirectResponse
    {
        abort_if(current_site() === null || ! LibrarySchema::ready(), 404);
        $result = $builder->verify($account);

        return back()->with($result['ok'] ? 'success' : 'error', $result['ok'] ? '服务商验证成功。' : '验证失败：' . $result['error']);
    }

    public function toggleProvider(Request $request, int $account, ProviderAccountService $accounts): RedirectResponse
    {
        abort_if(current_site() === null || ! LibrarySchema::ready(), 404);
        $accounts->setDispatch($account, $request->boolean('enabled'));
        if ($request->boolean('enabled') && app(InstantTranslateSettings::class)->libraryBuildEnabled()) {
            app(TranslationLibrary::class)->resumeDeferred();
            ProcessTranslationLibrary::dispatch(app(InstantTranslateSettings::class)->storageScope());
        }

        return back()->with('success', $request->boolean('enabled') ? '已开启此账户派发。' : '已暂停此账户派发。');
    }

    public function scan(InstantTranslateSettings $settings): RedirectResponse
    {
        abort_if(current_site() === null || ! LibrarySchema::ready(), 404);
        $locales = [];
        foreach ($settings->all()['enabled_languages'] as $language) {
            $locale = TranslationLibrary::localeForLanguage($language);
            if ($locale && $locale !== TranslationLibrary::SOURCE_LOCALE) {
                $locales[] = $locale;
            }
        }
        $scope   = $settings->storageScope();
        $locales = array_values(array_unique($locales));
        Cache::put(ScanTranslationLibrary::statusKey($scope), [
            'state' => 'queued', 'queued_at' => now()->toIso8601String(),
        ], now()->addHour());
        ScanTranslationLibrary::dispatch($scope, $locales);

        return back()->with('success', '扫描任务已进入后台队列；本页无需等待，完成后会自动衔接慢速建库。');
    }

    public function processOne(LibraryBuilder $builder): RedirectResponse
    {
        abort_if(current_site() === null || ! LibrarySchema::ready(), 404);
        $result = $builder->processOne(true);

        return back()->with('success', '单步处理结果：' . ($result['state'] ?? 'unknown'));
    }

    /** @return array<string, mixed> */
    private function diagnostics(
        Request $request,
        CloudflareIpRangeService $cloudflare,
        VerifiedVisitorCountry $country,
    ): array {
        $sourceRoot = plugin_path('LongosInstantTranslate/Static/public');
        $publicRoot = public_path('plugin/longos_instant_translate');
        $files      = ['translate-widget.js', 'translate-widget.css', 'vendor/translate.min.js'];
        $assets     = [];
        foreach ($files as $file) {
            $source        = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
            $public        = $publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
            $assets[$file] = [
                'source'    => File::isFile($source),
                'published' => File::isFile($public),
                'current'   => File::isFile($source) && File::isFile($public)
                                                     && hash_file('sha256', $source) === hash_file('sha256', $public),
            ];
        }

        return [
            'assets'                => $assets,
            'cloudflare_request'    => $cloudflare->isCloudflareRequest($request),
            'verified_country'      => $country->countryCode($request),
            'vendor_hash_verified'  => File::isFile($sourceRoot . '/vendor/translate.min.js')
                && hash_file('sha256', $sourceRoot . '/vendor/translate.min.js') === InstantTranslateSettings::XNX3_SHA256,
        ];
    }
}
