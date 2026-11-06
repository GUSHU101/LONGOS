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
    private const PLUGIN_RELEASE = '1.5.0';

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
            ->whereExists(static function ($query): void {
                $query->selectRaw('1')->from('lgt_publication_items as pi')
                    ->whereColumn('pi.publication_id', 'lgt_publications.id');
            })
            ->orderByDesc('revision')
            ->pluck('locale')
            ->map(static fn (mixed $locale): string => TranslationSettings::locale((string) $locale))
            ->unique()
            ->values()
            ->all();

        // Public builder module IDs only, restricted to the current immutable
        // publication. These allow a no-theme-changes fallback on the homepage.
        $currentPublicationIds = DB::table('lgt_publications')->where('state', 'published')
            ->whereIn('locale', $settings['enabled_locales'])->pluck('id')->all();
        $builderEntities = $currentPublicationIds === [] ? [] : DB::table('lgt_entries as e')
            ->join('lgt_translations as t', 't.entry_id', '=', 'e.id')
            ->join('lgt_publication_items as pi', 'pi.translation_id', '=', 't.id')
            ->whereIn('pi.publication_id', $currentPublicationIds)
            ->where('e.kind', 'builder')->where('e.is_public', true)->where('e.is_tombstoned', false)
            ->whereColumn('pi.source_hash', 'e.source_hash')
            ->distinct()->limit(16)->pluck('e.entity_id')->map(static fn ($id): string => (string) $id)->all();

        return $this->json([
            'plugin_release' => self::PLUGIN_RELEASE,
            'enabled'        => (bool) data_get($settings, 'widget.enabled', false),
            'source_locale'  => $settings['source_locale'],
            'locales'        => array_values(array_unique(array_merge([$settings['source_locale']], $settings['enabled_locales']))),
            'published_locales' => $publishedLocales,
            'builder_entities' => $builderEntities,
            'locale'         => $decision->locale,
            'locale_source'  => $decision->source,
            'country'        => $decision->country,
            'fallback'       => $decision->fallback,
            'dictionary_url' => shop_route('longos_global_translate.dictionary', ['kind' => '__kind__', 'entityId' => '__entity__']),
            'batch_dictionary_url' => shop_route('longos_global_translate.dictionary-batch'),
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
            'locale' => ['required', 'string', 'max:24'],
            'entities' => ['required', 'string', 'max:2500'],
        ]);
        $locale = TranslationSettings::locale($data['locale']);
        if (! in_array($locale, array_merge([$settings['source_locale']], $settings['enabled_locales']), true)) {
            abort(422, 'Unsupported presentation locale.');
        }
        $keys = array_values(array_unique(explode(',', (string) $data['entities'])));
        if (count($keys) > 48) {
            abort(422, 'Too many entities.');
        }
        $dictionaries = [];
        foreach ($keys as $key) {
            if (! preg_match('/^(product|category|page|builder):([1-9][0-9]{0,18})$/D', $key, $matches)) {
                abort(422, 'Invalid public entity.');
            }
            $id = filter_var($matches[2], FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                abort(422, 'Invalid entity ID.');
            }
            $dictionary = $this->dictionary->forEntity($matches[1], $id, $locale);
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
    /** @return array<string, mixed> */
    private function disabledConfig(): array
    {
        return [
            'plugin_release' => self::PLUGIN_RELEASE,
            'enabled'        => false,
            'source_locale'  => 'en',
            'locales'        => ['en'],
            'published_locales' => [],
            'builder_entities' => [],
            'locale'         => 'en',
            'locale_source'  => 'schema_not_ready',
            'country'        => null,
            'fallback'       => true,
            'dictionary_url' => '',
            'batch_dictionary_url' => '',
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
