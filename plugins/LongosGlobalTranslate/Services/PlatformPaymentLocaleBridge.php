<?php

namespace Plugin\LongosGlobalTranslate\Services;

use App\Saas\Models\Site;
use App\Saas\TenantManager;
use Beike\Repositories\SettingRepo;
use Illuminate\Http\Request;

/**
 * Applies an already-published tenant dictionary to the platform-hosted
 * payment screen. That screen intentionally lives on the control host and
 * therefore does not inherit a tenant theme layout or the browser widget.
 *
 * This bridge performs no translation and no outbound request. It enters the
 * owning tenant only long enough to resolve the visitor's presentation locale
 * and to load a frozen local dictionary. Only finite platform_pay static
 * labels are added to Laravel's in-memory translator; amounts, store names,
 * payment tokens and every other interpolated value stay in the original
 * server-rendered message and never enter the translation library.
 */
final class PlatformPaymentLocaleBridge
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function apply(Site $site, Request $request, string $fallbackLocale = 'en'): string
    {
        try {
            return $this->tenants->run($site, function () use ($request, $fallbackLocale): string {
                if (! TranslationSchema::ready()) {
                    return $fallbackLocale;
                }

                // The bridge is reached from a core controller, unlike the
                // normal storefront widget which is reached through the
                // plugin hook. Respect the per-tenant plugin switch here as
                // well: uninstalling or disabling the plugin must restore
                // the hosted payment page to its session locale immediately.
                if (! SettingRepo::getPluginStatus('longos_global_translate')) {
                    return $fallbackLocale;
                }

                $settings = app(TranslationSettings::class)->all();
                if (! (bool) data_get($settings, 'widget.enabled', false)) {
                    return $fallbackLocale;
                }

                $decision = app(PresentationLocaleResolver::class)->decide($request);
                $locale   = TranslationSettings::locale($decision->locale);
                if ($locale === (string) ($settings['source_locale'] ?? 'en')) {
                    return $locale;
                }

                $dictionary = app(DictionaryService::class)->forCommerceInterface($locale);
                $lines      = $this->platformPaymentLines((array) ($dictionary['items'] ?? []));
                if ($lines === [] || count($lines) !== count($this->platformPaymentStaticSources())) {
                    // The catalog safely de-duplicates identical English
                    // sources across storefront files. Resolve them by their
                    // frozen source text below, then fail closed unless every
                    // safe platform-pay label is present. This prevents a
                    // partly translated payment page from claiming a locale.
                    return $fallbackLocale;
                }

                app('translator')->addLines($lines, $locale);

                return $locale;
            });
        } catch (\Throwable) {
            // A translation plugin must never block a payment session. The
            // normal session locale remains a safe, deterministic fallback.
            return $fallbackLocale;
        }
    }

    /**
     * The global catalog deliberately stores one copy of a repeated English
     * source (for example, "Secure checkout"). The hosted page still needs
     * that one reviewed translation under each of its Laravel keys, so map
     * by immutable source text rather than relying on a particular catalog
     * field surviving de-duplication.
     *
     * @param array<string, array{source:string,text:string}> $items
     * @return array<string,string>
     */
    private function platformPaymentLines(array $items): array
    {
        $bySource = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $source = CommerceUiCatalog::canonicalSource((string) ($item['source'] ?? ''));
            $text   = trim((string) ($item['text'] ?? ''));
            if ($source === '' || $text === '') {
                continue;
            }
            $bySource[$source] = $text;
        }

        $lines = [];
        foreach ($this->platformPaymentStaticSources() as $key => $source) {
            if (isset($bySource[$source])) {
                $lines[$key] = $bySource[$source];
            }
        }

        return $lines;
    }

    /** @return array<string, string> Laravel translation key => static source */
    private function platformPaymentStaticSources(): array
    {
        $path = base_path('lang/en/platform_pay.php');
        if (! is_file($path)) {
            return [];
        }

        try {
            $values = require $path;
        } catch (\Throwable) {
            return [];
        }
        if (! is_array($values)) {
            return [];
        }

        $sources = [];
        $this->collectStaticSources($values, '', $sources);

        return $sources;
    }

    /**
     * @param array<string|int, mixed> $values
     * @param array<string, string>    $sources
     */
    private function collectStaticSources(array $values, string $prefix, array &$sources): void
    {
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $this->collectStaticSources($value, $path, $sources);

                continue;
            }
            if (! is_string($value)) {
                continue;
            }

            $source = CommerceUiCatalog::canonicalSource($value);
            if (CommerceUiCatalog::acceptsStaticSource($source)) {
                $sources['platform_pay.' . $path] = $source;
            }
        }
    }
}
