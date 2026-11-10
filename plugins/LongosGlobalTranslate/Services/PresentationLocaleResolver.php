<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Beike\Services\VisitorCurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Presentation locale is deliberately independent from Laravel's core locale. */
final class PresentationLocaleResolver
{
    public const COOKIE = 'longos_presentation_locale';

    public function __construct(
        private readonly TranslationSettings $settings,
        private readonly VisitorCurrencyService $visitorCurrency,
        private readonly CountryLocaleCatalog $countries,
        private readonly CloudflareVisitorCountry $cloudflare,
    ) {}

    /**
     * @param array<string, mixed>|null $resolvedSettings
     */
    public function decide(Request $request, ?array $resolvedSettings = null, ?array $publishedLocales = null): PresentationLocaleDecision
    {
        // StorefrontPresentationConfig has already loaded the same tenant
        // settings while composing the initial HTML response. Accepting that
        // validated snapshot avoids two additional settings-table reads on a
        // cold first paint, without introducing a process-wide cache that
        // could ever cross a tenant boundary.
        $settings = $resolvedSettings ?? $this->settings->all();
        $source   = (string) $settings['source_locale'];
        if ($publishedLocales === null) {
            // Server-rendered product variants and platform payment views must
            // resolve the same available locale as the browser /config call.
            // A publication with no items cannot localize anything.
            try {
                $publishedLocales = Schema::hasTable('lgt_publications') && Schema::hasTable('lgt_publication_items')
                    ? DB::table('lgt_publications')->where('state', 'published')
                        ->whereExists(static function ($query): void {
                            $query->selectRaw('1')->from('lgt_publication_items as pi')
                                ->whereColumn('pi.publication_id', 'lgt_publications.id');
                        })->pluck('locale')->all()
                    : [];
            } catch (\Throwable) {
                $publishedLocales = [];
            }
        }
        $enabled  = array_flip(array_merge([$source], (array) $settings['enabled_locales']));
        // Never pick a locale whose publication was removed or which is
        // enabled in settings but not yet translated and published.
        $available = array_flip(array_merge([$source], array_values(array_intersect(array_keys($enabled), $publishedLocales))));
        $manual    = $request->cookie(self::COOKIE);
        if (is_string($manual) && isset($available[$manual])) {
            return new PresentationLocaleDecision($manual, 'explicit', null, false);
        }
        $country = $this->trustedCountry($request);
        if ($country !== null && (bool) ($settings['geo_auto_switch_enabled'] ?? true)) {
            if (Schema::hasTable('lgt_country_rules')) {
                $rule = DB::table('lgt_country_rules')->where('country', $country)->where('enabled', true)->value('locale');
                if (is_string($rule)) {
                    try {
                        $rule = TranslationSettings::locale($rule);
                    } catch (\InvalidArgumentException) {
                        $rule = null;
                    }
                }
                if (is_string($rule) && isset($available[$rule])) {
                    return new PresentationLocaleDecision($rule, 'geo_rule', $country, false);
                }
            }
            // Some countries have several common languages (e.g. Canada,
            // Switzerland and Belgium). Their browser preference breaks the
            // tie; otherwise use the country's ordered, conservative default.
            $candidates = $this->countries->candidateLocales($country);
            $preferred  = $this->preferredLocale($request, array_values(array_intersect($candidates, array_keys($available))));
            if ($preferred !== null) {
                return new PresentationLocaleDecision($preferred, 'geo_browser', $country, false);
            }
            foreach ($candidates as $candidate) {
                if (isset($available[$candidate])) {
                    return new PresentationLocaleDecision($candidate, 'geo_catalog', $country, false);
                }
            }
            $suggested = $this->countries->suggestedLocale($country);
            if ($suggested !== null && isset($available[$suggested])) {
                return new PresentationLocaleDecision($suggested, 'geo_catalog', $country, false);
            }
        }
        if ((bool) ($settings['accept_language_enabled'] ?? false)) {
            $candidate = $this->preferredLocale($request, array_keys($available));
            if ($candidate !== null) {
                return new PresentationLocaleDecision($candidate, 'accept_language', $country, false);
            }
        }

        return new PresentationLocaleDecision($source, 'default', $country, true);
    }

    /** Prefer verified Cloudflare country, then LONGOS and offline GeoIP2. */
    private function trustedCountry(Request $request): ?string
    {
        // Cloudflare's official CF-IPCountry is reliable only when its direct
        // source matches the verified origin-facing ranges or a deliberately
        // secured ingress. An arbitrary visitor header must never win.
        $cloudflareCountry = $this->cloudflare->countryCode($request);
        if ($cloudflareCountry !== null) {
            return $cloudflareCountry;
        }

        try {
            // LONGOS verifies its proxy configuration inside this service.
            // Do not trust arbitrary CF-IPCountry/X-Country headers directly.
            $country = $this->visitorCurrency->countryCode($request);
            $country = is_string($country) ? strtoupper(trim($country)) : null;
            if (is_string($country) && preg_match('/^[A-Z]{2}$/', strtoupper($country)) === 1) {
                return $country;
            }
        } catch (\Throwable) {
            // Currency geolocation is optional; failure must not break shops.
        }
        $path = (string) config('currency.geolite2_path', '');
        $ip   = $request->ip();
        if ($path === '' || ! is_file($path) || ! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP)
                         || ! class_exists(\GeoIp2\Database\Reader::class)) {
            return null;
        }

        try {
            $reader = new \GeoIp2\Database\Reader($path);

            try {
                $code = $reader->country($ip)->country->isoCode;
            } finally {
                if (method_exists($reader, 'close')) {
                    $reader->close();
                }
            }

            return is_string($code) && preg_match('/^[A-Z]{2}$/', strtoupper($code)) === 1
                ? strtoupper($code) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Only the browser's explicit Accept-Language choices; no artificial first-item default. */
    private function preferredLocale(Request $request, array $available): ?string
    {
        $allowed = array_flip($available);
        foreach ($request->getLanguages() as $hint) {
            $hint       = strtolower(str_replace('_', '-', (string) $hint));
            $normalized = match (true) {
                str_starts_with($hint, 'zh-hant'), str_starts_with($hint, 'zh-tw'), str_starts_with($hint, 'zh-hk') => 'zh-tw',
                $hint === 'zh', str_starts_with($hint, 'zh-hans'), str_starts_with($hint, 'zh-cn'), str_starts_with($hint, 'zh-sg') => 'zh-cn',
                default => explode('-', $hint, 2)[0],
            };
            foreach ([$hint, $normalized] as $candidate) {
                if (isset($allowed[$candidate])) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    public function persist(Response|JsonResponse $response, string $locale): PresentationLocaleDecision
    {
        $settings = $this->settings->all();
        $source   = (string) $settings['source_locale'];
        if ($locale === 'auto') {
            // null domain keeps this host-only; secure+HttpOnly prevents JS or
            // another tenant custom host from reading a visitor preference.
            $response->withoutCookie(self::COOKIE);

            return new PresentationLocaleDecision($source, 'auto', null, true);
        }
        $locale = TranslationSettings::locale($locale);
        if (! in_array($locale, array_merge([$source], (array) $settings['enabled_locales']), true)) {
            abort(422, 'Unsupported presentation locale.');
        }
        $response->cookie(self::COOKIE, $locale, 60 * 24 * 90, '/', null, true, true, false, 'lax');

        return new PresentationLocaleDecision($locale, 'explicit', null, false);
    }
}
