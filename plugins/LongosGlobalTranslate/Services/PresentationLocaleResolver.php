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

    public function __construct(private readonly TranslationSettings $settings, private readonly VisitorCurrencyService $visitorCurrency) {}

    public function decide(Request $request): PresentationLocaleDecision
    {
        $settings = $this->settings->all();
        $source   = (string) $settings['source_locale'];
        $enabled  = array_flip(array_merge([$source], (array) $settings['enabled_locales']));
        $manual   = $request->cookie(self::COOKIE);
        if (is_string($manual) && isset($enabled[$manual])) {
            return new PresentationLocaleDecision($manual, 'explicit', null, false);
        }
        // The existing service itself verifies the proxy before trusting a
        // country header and never changes money when countryCode() is used.
        $country = $this->visitorCurrency->countryCode($request);
        if ($country !== null && Schema::hasTable('lgt_country_rules')) {
            $rule = DB::table('lgt_country_rules')->where('country', $country)->where('enabled', true)->value('locale');
            if (is_string($rule) && isset($enabled[$rule])) {
                return new PresentationLocaleDecision($rule, 'geo', $country, false);
            }
        }
        if ((bool) $settings['accept_language_enabled']) {
            $candidate = $request->getPreferredLanguage(array_keys($enabled));
            if (is_string($candidate) && isset($enabled[$candidate])) {
                return new PresentationLocaleDecision($candidate, 'accept_language', $country, false);
            }
        }

        return new PresentationLocaleDecision($source, 'default', $country, true);
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
