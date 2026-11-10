<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Illuminate\Http\Request;

/**
 * Decorates the already-public product detail payload with reviewed variant
 * labels from the local publication.  This is deliberately a read-only
 * presentation step: it never calls a provider, does not inspect shopper
 * input and leaves option indexes/values untouched so SKU selection remains
 * exactly the same in every language.
 */
final class PublishedProductVariantLocalizer
{
    public function __construct(
        private readonly TranslationSettings $settings,
        private readonly PresentationLocaleResolver $locales,
        private readonly DictionaryService $dictionary,
    ) {}

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function localize(array $payload, Request $request): array
    {
        $productId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($productId === false || ! is_array($payload['variables'] ?? null) || $payload['variables'] === []) {
            return $payload;
        }
        if (! TranslationSchema::ready()) {
            return $payload;
        }

        $settings = $this->settings->all();
        if (! TranslationSettings::presentationActive($settings)) {
            return $payload;
        }
        $locale = $this->locales->decide($request)->locale;
        if ($locale === $settings['source_locale'] || ! in_array($locale, $settings['enabled_locales'], true)) {
            return $payload;
        }

        try {
            $dictionary = $this->dictionary->forEntity('product', (int) $productId, $locale);
        } catch (\Throwable) {
            // Product shopping must never fail because an optional published
            // presentation dictionary is unavailable.
            return $payload;
        }

        return self::applyPublishedFields($payload, (array) ($dictionary['fields'] ?? []));
    }

    /** @param array<string, mixed> $payload
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function applyPublishedFields(array $payload, array $fields): array
    {
        if (! is_array($payload['variables'] ?? null)) {
            return $payload;
        }

        foreach ($payload['variables'] as $variableIndex => $variable) {
            if (! is_array($variable)) {
                continue;
            }
            $name = $fields['variables.' . $variableIndex . '.name'] ?? null;
            if (is_string($name) && trim($name) !== '') {
                $variable['name'] = $name;
            }
            if (is_array($variable['values'] ?? null)) {
                foreach ($variable['values'] as $valueIndex => $value) {
                    if (! is_array($value)) {
                        continue;
                    }
                    $label = $fields['variables.' . $variableIndex . '.values.' . $valueIndex . '.name'] ?? null;
                    if (is_string($label) && trim($label) !== '') {
                        $value['name'] = $label;
                    }
                    $variable['values'][$valueIndex] = $value;
                }
            }
            $payload['variables'][$variableIndex] = $variable;
        }

        return $payload;
    }
}
