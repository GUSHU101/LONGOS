<?php

namespace Plugin\LongosInstantTranslate\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugin\LongosInstantTranslate\Services\LanguageCatalog;
use Plugin\LongosInstantTranslate\Services\TranslationLibrary;

final class ShopLibraryController
{
    public function __invoke(Request $request, TranslationLibrary $library): JsonResponse
    {
        $validated = $request->validate([
            'language'     => ['required', 'string', 'max:32'],
            'items'        => ['required', 'array', 'max:360'],
            'items.*.key'  => ['required', 'string', 'max:48'],
            'items.*.text' => ['required', 'string', 'max:5000'],
        ]);
        $language = strtolower((string) $validated['language']);
        abort_unless(array_key_exists($language, LanguageCatalog::all()), 422);
        $locale = TranslationLibrary::localeForLanguage($language);
        if ($locale === null || $locale === TranslationLibrary::SOURCE_LOCALE) {
            return response()->json(['items' => []])->header('Cache-Control', 'private, no-store');
        }

        $texts   = array_map(static fn (array $item): string => (string) $item['text'], $validated['items']);
        $matches = $library->lookup($locale, $texts);
        $items   = [];
        foreach ($validated['items'] as $item) {
            $normalized = TranslationLibrary::normalize((string) $item['text']);
            if ($normalized !== '' && isset($matches[$normalized])) {
                $items[] = ['key' => (string) $item['key'], 'translation' => $matches[$normalized]];
            }
        }

        return response()->json(['items' => $items])->header('Cache-Control', 'private, no-store');
    }
}
