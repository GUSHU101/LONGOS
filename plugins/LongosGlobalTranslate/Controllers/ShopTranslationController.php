<?php

namespace Plugin\LongosGlobalTranslate\Controllers;

use Beike\Shop\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Plugin\LongosGlobalTranslate\Services\DictionaryService;
use Plugin\LongosGlobalTranslate\Services\PresentationLocaleResolver;
use Plugin\LongosGlobalTranslate\Services\TranslationSchema;
use Plugin\LongosGlobalTranslate\Services\TranslationSettings;

final class ShopTranslationController extends Controller
{
    public function __construct(
        private readonly TranslationSettings $settings,
        private readonly PresentationLocaleResolver $locales,
        private readonly DictionaryService $dictionary,
    ) {}

    public function config(Request $request): JsonResponse
    {
        if (! TranslationSchema::ready()) {
            return $this->json($this->disabledConfig());
        }
        $settings = $this->settings->all();
        if (! (bool) data_get($settings, 'widget.enabled', false)) {
            return $this->json($this->disabledConfig());
        }
        $decision = $this->locales->decide($request);
        // The browser must never claim a locale was applied when its frozen
        // public dictionary does not exist yet.  Keep the operator's enabled
        // language list separate from the publication list so an IP/country
        // recommendation is useful without producing a misleading mixed-
        // language storefront.
        $publishedLocales = DB::table('lgt_publications')
            ->where('state', 'published')
            ->orderByDesc('revision')
            ->pluck('locale')
            ->map(static fn (mixed $locale): string => TranslationSettings::locale((string) $locale))
            ->unique()
            ->values()
            ->all();

        return $this->json([
            'enabled'        => (bool) data_get($settings, 'widget.enabled', false),
            'source_locale'  => $settings['source_locale'],
            'locales'        => array_values(array_unique(array_merge([$settings['source_locale']], $settings['enabled_locales']))),
            'published_locales' => $publishedLocales,
            'locale'         => $decision->locale,
            'locale_source'  => $decision->source,
            'country'        => $decision->country,
            'fallback'       => $decision->fallback,
            'dictionary_url' => shop_route('longos_global_translate.dictionary', ['kind' => '__kind__', 'entityId' => '__entity__']),
            'interface_dictionary_url' => shop_route('longos_global_translate.interface-dictionary'),
        ]);
    }

    public function locale(Request $request): JsonResponse
    {
        if (! TranslationSchema::ready()) {
            return $this->json(['saved' => false, 'code' => 'LGT_SCHEMA_NOT_READY'], 503);
        }
        $locale   = (string) $request->validate(['locale' => ['required', 'string', 'max:24']])['locale'];
        $response = $this->json(['saved' => true]);
        $decision = $this->locales->persist($response, $locale);

        return $response->setData([
            'saved'  => true,
            'locale' => $decision->locale,
            'source' => $decision->source,
        ]);
    }

    public function dictionary(Request $request, string $kind, int $entityId): JsonResponse
    {
        if (! TranslationSchema::ready()) {
            return $this->json(['revision' => null, 'items' => []]);
        }
        $settings = $this->settings->all();
        // A disabled selector has no public presentation surface.  Returning
        // an empty dictionary here keeps a previously injected, cached script
        // from becoming an alternative content-discovery endpoint.
        if (! (bool) data_get($settings, 'widget.enabled', false)) {
            return $this->json(['revision' => null, 'items' => []]);
        }
        $decision = $this->locales->decide($request);
        $locale   = TranslationSettings::locale((string) $request->query('locale', $decision->locale));
        // The server retains authority over enabled locales. An arbitrary URL
        // parameter never becomes a cross-locale dictionary enumeration tool.
        if (! in_array($locale, array_merge([$settings['source_locale']], $settings['enabled_locales']), true)) {
            abort(422, 'Unsupported presentation locale.');
        }

        return $this->json($this->dictionary->forEntity($kind, $entityId, $locale));
    }

    /**
     * Published checkout/account/payment interface labels only.  This is a
     * separate endpoint because the normal dictionary endpoint intentionally
     * exposes only catalog and Builder entities.
     */
    public function interfaceDictionary(Request $request): JsonResponse
    {
        if (! TranslationSchema::ready()) {
            return $this->json(['revision' => null, 'items' => []]);
        }
        $settings = $this->settings->all();
        if (! (bool) data_get($settings, 'widget.enabled', false)) {
            return $this->json(['revision' => null, 'items' => []]);
        }
        $decision = $this->locales->decide($request);
        $locale   = TranslationSettings::locale((string) $request->query('locale', $decision->locale));
        if (! in_array($locale, array_merge([$settings['source_locale']], $settings['enabled_locales']), true)) {
            abort(422, 'Unsupported presentation locale.');
        }

        return $this->json($this->dictionary->forCommerceInterface($locale));
    }

    /** @param array<string, mixed> $payload */
    /** @return array<string, mixed> */
    private function disabledConfig(): array
    {
        return [
            'enabled'        => false,
            'source_locale'  => 'en',
            'locales'        => ['en'],
            'published_locales' => [],
            'locale'         => 'en',
            'locale_source'  => 'schema_not_ready',
            'country'        => null,
            'fallback'       => true,
            'dictionary_url' => '',
            'interface_dictionary_url' => '',
        ];
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, [
            'Cache-Control'          => 'private, no-store',
            'Pragma'                 => 'no-cache',
            'Vary'                   => 'Cookie',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy'        => 'same-origin',
        ]);
    }
}
