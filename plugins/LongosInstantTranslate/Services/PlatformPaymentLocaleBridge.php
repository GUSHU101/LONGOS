<?php

namespace Plugin\LongosInstantTranslate\Services;

use App\Saas\Models\Site;
use App\Saas\TenantManager;
use Beike\Repositories\SettingRepo;
use Illuminate\Http\Request;

/** Applies only already-built local translations to the control-hosted payment page. */
final class PlatformPaymentLocaleBridge
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function apply(Site $site, Request $request, string $fallbackLocale = 'en'): string
    {
        try {
            return $this->tenants->run($site, function () use ($fallbackLocale): string {
                $locale = strtolower(str_replace('_', '-', trim($fallbackLocale)));
                if ($locale === 'en' || ! LibrarySchema::ready() || ! SettingRepo::getPluginStatus(InstantTranslateSettings::CODE)) {
                    return $fallbackLocale;
                }
                $sources = $this->staticSources();
                if ($sources === []) {
                    return $fallbackLocale;
                }
                $translations = app(TranslationLibrary::class)->lookup($locale, array_values($sources));
                $lines        = [];
                foreach ($sources as $key => $source) {
                    $normalized = TranslationLibrary::normalize($source);
                    if (! isset($translations[$normalized])) {
                        return $fallbackLocale;
                    }
                    $lines[$key] = $translations[$normalized];
                }
                app('translator')->addLines($lines, $locale);

                return $locale;
            });
        } catch (\Throwable) {
            return $fallbackLocale;
        }
    }

    /** @return array<string,string> */
    private function staticSources(): array
    {
        $path = lang_path('en/platform_pay.php');
        if (! is_file($path)) {
            return [];
        }
        $values = require $path;
        if (! is_array($values)) {
            return [];
        }
        $sources = [];
        $this->flatten($values, 'platform_pay', $sources);

        return $sources;
    }

    /** @param array<string|int,mixed> $values @param array<string,string> $sources */
    private function flatten(array $values, string $prefix, array &$sources): void
    {
        foreach ($values as $key => $value) {
            $path = $prefix . '.' . $key;
            if (is_array($value)) {
                $this->flatten($value, $path, $sources);
            } elseif (is_string($value) && TranslationLibrary::normalize($value) !== '') {
                $sources[$path] = $value;
            }
        }
    }
}
