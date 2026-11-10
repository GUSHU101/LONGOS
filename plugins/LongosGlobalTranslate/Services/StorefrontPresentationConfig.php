<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Builds the tiny, non-secret storefront bootstrap contract.
 *
 * Runs as a fresh, non-shared /config response so Cloudflare country and
 * visitor cookie preferences cannot leak across cached storefront HTML.
 * It deliberately contains only locale decisions, public publication
 * revisions and route URLs — never visitor IPs or provider credentials.
 */
final class StorefrontPresentationConfig
{
    public const PLUGIN_RELEASE = '1.17.1';

    public function __construct(
        private readonly TranslationSettings $settings,
        private readonly PresentationLocaleResolver $locales,
    ) {}

    /**
     * @param array<string, mixed>|null $resolvedSettings
     * @return array<string, mixed>
     */
    public function forRequest(Request $request, ?array $resolvedSettings = null): array
    {
        if (! TranslationSchema::ready()) {
            return $this->disabled();
        }

        $settings = $resolvedSettings ?? $this->settings->all();
        if (! TranslationSettings::presentationActive($settings)) {
            return $this->disabled('presentation_disabled');
        }
        if (((array) ($settings['enabled_locales'] ?? [])) === []
            && ! (bool) data_get($settings, 'widget.enabled', false)) {
            return $this->disabled('no_enabled_locales');
        }
        // A recovered database can temporarily retain more than one published
        // row for a locale.  Use the same newest-revision rule as
        // DictionaryService, so the browser cache key always names the exact
        // immutable snapshot that will be returned by the public endpoints.
        $published = [];
        foreach (DB::table('lgt_publications')->where('state', 'published')
            // A publication without entries is not a usable locale. Do not
            // advertise it to the browser or it would select a language that
            // can only render the source text until a later deployment.
            ->whereExists(static function ($query): void {
                $query->selectRaw('1')
                    ->from('lgt_publication_items as pi')
                    ->whereColumn('pi.publication_id', 'lgt_publications.id');
            })
            ->orderByDesc('revision')->orderByDesc('id')->get(['id', 'locale', 'revision']) as $publication) {
            try {
                $locale = TranslationSettings::locale((string) $publication->locale);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if (! isset($published[$locale])) {
                $published[$locale] = [
                    'id'       => (int) $publication->id,
                    'revision' => (int) $publication->revision,
                ];
            }
        }

        $enabled        = array_flip((array) $settings['enabled_locales']);
        $published      = array_intersect_key($published, $enabled);
        // Decide *after* loading publications. A location-matched but still
        // untranslated language must not mask an available browser fallback.
        $decision       = $this->locales->decide($request, $settings, array_keys($published));
        $publicationIds = array_values(array_map(
            static fn (array $publication): int => $publication['id'],
            array_intersect_key($published, $enabled),
        ));
        $builderEntities = $publicationIds === [] ? [] : DB::table('lgt_entries as e')
            ->join('lgt_translations as t', 't.entry_id', '=', 'e.id')
            ->join('lgt_publication_items as pi', 'pi.translation_id', '=', 't.id')
            ->whereIn('pi.publication_id', $publicationIds)
            ->where('e.kind', 'builder')->where('e.is_public', true)->where('e.is_tombstoned', false)
            ->whereColumn('pi.source_hash', 'e.source_hash')
            ->distinct()->limit(240)->pluck('e.entity_id')->map(static fn (mixed $id): string => (string) $id)->all();

        return [
            'plugin_release'           => self::PLUGIN_RELEASE,
            'enabled'                  => $published !== [] || (bool) data_get($settings, 'widget.enabled', false),
            'widget_visible'           => (bool) data_get($settings, 'widget.enabled', false),
            'source_locale'            => $settings['source_locale'],
            'locales'                  => array_values(array_unique(array_merge([$settings['source_locale']], $settings['enabled_locales']))),
            'published_locales'        => array_keys($published),
            'published_revisions'      => array_map(static fn (array $publication): int => $publication['revision'], $published),
            'builder_entities'         => $builderEntities,
            'locale'                   => $decision->locale,
            'locale_source'            => $decision->source,
            'country'                  => $decision->country,
            'fallback'                 => $decision->fallback,
            'dictionary_url'           => shop_route('longos_global_translate.dictionary', ['kind' => '__kind__', 'entityId' => '__entity__']),
            'batch_dictionary_url'     => shop_route('longos_global_translate.dictionary-batch'),
            'interface_dictionary_url' => shop_route('longos_global_translate.interface-dictionary'),
        ];
    }

    /** @return array<string, mixed> */
    private function disabled(string $reason = 'schema_not_ready'): array
    {
        return [
            'plugin_release'           => self::PLUGIN_RELEASE,
            'enabled'                  => false,
            'widget_visible'           => false,
            'source_locale'            => 'en',
            'locales'                  => ['en'],
            'published_locales'        => [],
            'published_revisions'      => [],
            'builder_entities'         => [],
            'locale'                   => 'en',
            'locale_source'            => $reason,
            'country'                  => null,
            'fallback'                 => true,
            'dictionary_url'           => '',
            'batch_dictionary_url'     => '',
            'interface_dictionary_url' => '',
        ];
    }
}
