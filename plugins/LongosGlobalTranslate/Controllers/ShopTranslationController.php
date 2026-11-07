<?php

namespace Plugin\LongosGlobalTranslate\Controllers;

use Beike\Shop\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugin\LongosGlobalTranslate\Services\DictionaryService;
use Plugin\LongosGlobalTranslate\Services\PresentationLocaleResolver;
use Plugin\LongosGlobalTranslate\Services\StorefrontPresentationConfig;
use Plugin\LongosGlobalTranslate\Services\TranslationSchema;
use Plugin\LongosGlobalTranslate\Services\TranslationSettings;

final class ShopTranslationController extends Controller
{
    public function __construct(
        private readonly TranslationSettings $settings,
        private readonly PresentationLocaleResolver $locales,
        private readonly DictionaryService $dictionary,
        private readonly ?StorefrontPresentationConfig $presentation = null,
    ) {}

    public function config(Request $request): JsonResponse
    {
        return $this->json(($this->presentation ?? app(StorefrontPresentationConfig::class))->forRequest($request));
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
     * Bounded public read for listings and Builder modules. The browser
     * submits only IDs found on the current page or in the published Builder
     * registry; every entity still passes the same visibility/stale checks.
     * No arbitrary table/field names and no unpublished entries are exposed.
     */
    public function batchDictionary(Request $request): JsonResponse
    {
        if (! TranslationSchema::ready()) {
            return $this->json(['dictionaries' => []]);
        }
        $settings = $this->settings->all();
        if (! (bool) data_get($settings, 'widget.enabled', false)) {
            return $this->json(['dictionaries' => []]);
        }
        $data = $request->validate([
            'locale'   => ['required', 'string', 'max:24'],
            'entities' => ['required', 'string', 'max:4000'],
        ]);
        $locale = TranslationSettings::locale($data['locale']);
        if (! in_array($locale, array_merge([$settings['source_locale']], $settings['enabled_locales']), true)) {
            abort(422, 'Unsupported presentation locale.');
        }
        $keys = array_values(array_unique(explode(',', (string) $data['entities'])));
        if (count($keys) > 72) {
            abort(422, 'Too many entities.');
        }
        $requested = [];
        foreach ($keys as $key) {
            if (! preg_match('/^(product|category|page|page_category|builder|chrome):([1-9][0-9]{0,18})$/D', $key, $matches)) {
                abort(422, 'Invalid public entity.');
            }
            $id = filter_var($matches[2], FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                abort(422, 'Invalid entity ID.');
            }
            $requested[$key] = ['kind' => $matches[1], 'entity_id' => $id];
        }
        $dictionaries = [];
        foreach ($this->dictionary->forEntities($requested, $locale) as $key => $dictionary) {
            if ($dictionary['revision'] !== null && $dictionary['items'] !== []) {
                $dictionaries[$key] = $dictionary;
            }
        }

        return $this->json(['dictionaries' => $dictionaries]);
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
