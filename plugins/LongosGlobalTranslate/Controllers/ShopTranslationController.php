<?php

namespace Plugin\LongosGlobalTranslate\Controllers;

use Beike\Shop\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $decision = $this->locales->decide($request);

        return $this->json([
            'enabled'        => (bool) data_get($settings, 'widget.enabled', false),
            'source_locale'  => $settings['source_locale'],
            'locales'        => array_values(array_unique(array_merge([$settings['source_locale']], $settings['enabled_locales']))),
            'locale'         => $decision->locale,
            'locale_source'  => $decision->source,
            'dictionary_url' => shop_route('longos_global_translate.dictionary', ['kind' => '__kind__', 'entityId' => '__entity__']),
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
        $decision = $this->locales->decide($request);
        $locale   = (string) $request->query('locale', $decision->locale);
        // The server retains authority over enabled locales. An arbitrary URL
        // parameter never becomes a cross-locale dictionary enumeration tool.
        $settings = $this->settings->all();
        if (! in_array($locale, array_merge([$settings['source_locale']], $settings['enabled_locales']), true)) {
            abort(422, 'Unsupported presentation locale.');
        }

        return $this->json($this->dictionary->forEntity($kind, $entityId, $locale));
    }

    /** @param array<string, mixed> $payload */
    /** @return array<string, mixed> */
    private function disabledConfig(): array
    {
        return [
            'enabled'        => false,
            'source_locale'  => 'en',
            'locales'        => ['en'],
            'locale'         => 'en',
            'locale_source'  => 'schema_not_ready',
            'dictionary_url' => '',
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
