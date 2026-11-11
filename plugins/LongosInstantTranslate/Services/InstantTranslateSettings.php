<?php

namespace Plugin\LongosInstantTranslate\Services;

final class InstantTranslateSettings
{
    public const CODE = 'longos_instant_translate';

    public const VERSION = '2.0.0';

    public const XNX3_VERSION = '4.1.0.20260526';

    public const XNX3_COMMIT = '311c593ccc1f0016b6d9c47060e984a966b6fe45';

    public const XNX3_SHA256 = 'f027e461c331e21c60634725db8f13c01d72788faf87dc815882a7c0572e96e4';

    /** @return array<string, mixed> */
    public function all(): array
    {
        return [
            'runtime_enabled'        => $this->boolean('runtime_enabled', true),
            'auto_country'           => $this->boolean('auto_country', true),
            'browser_fallback'       => $this->boolean('browser_fallback', true),
            'enabled_languages'      => LanguageCatalog::normalize(plugin_setting(self::CODE . '.enabled_languages', array_keys(LanguageCatalog::all()))),
            'position'               => $this->choice('position', ['left', 'right'], 'right'),
            'bottom_offset'          => $this->integer('bottom_offset', 84, 16, 240),
            'mobile_bottom_offset'   => $this->integer('mobile_bottom_offset', 88, 16, 260),
            'theme'                  => $this->choice('theme', ['system', 'light', 'dark'], 'system'),
            'accent_color'           => $this->color(plugin_setting(self::CODE . '.accent_color', '#2458d3')),
            'label_mode'             => $this->choice('label_mode', ['short', 'name'], 'short'),
            'library_enabled'        => $this->boolean('library_enabled', true),
            'library_build_enabled'  => $this->boolean('library_build_enabled', false),
            'build_interval_seconds' => $this->integer('build_interval_seconds', 30, 5, 3600),
        ];
    }

    public function runtimeEnabled(): bool
    {
        return (bool) $this->all()['runtime_enabled'];
    }

    public function libraryBuildEnabled(): bool
    {
        return (bool) $this->all()['library_build_enabled'];
    }

    public function buildIntervalSeconds(): int
    {
        return (int) $this->all()['build_interval_seconds'];
    }

    /** @return array<string, mixed> */
    public function publicPayload(): array
    {
        $settings  = $this->all();
        $languages = [];
        $catalog   = LanguageCatalog::all();
        foreach ($settings['enabled_languages'] as $id) {
            $languages[] = ['id' => $id, ...$catalog[$id]];
        }

        return [
            'version'          => self::VERSION,
            'engine_version'   => self::XNX3_VERSION,
            'service'          => 'client.edge',
            'source_language'  => 'english',
            'languages'        => $languages,
            'auto_country'     => $settings['auto_country'],
            'browser_fallback' => $settings['browser_fallback'],
            'position'         => $settings['position'],
            'bottom_offset'    => $settings['bottom_offset'],
            'mobile_offset'    => $settings['mobile_bottom_offset'],
            'theme'            => $settings['theme'],
            'accent_color'     => $settings['accent_color'],
            'label_mode'       => $settings['label_mode'],
            'root_selectors'   => StorefrontPolicy::SAFE_ROOT_SELECTORS,
            'storage_prefix'   => 'longos_instant_translate_v1:' . $this->storageScope() . ':',
            'request_timeout'  => 8000,
            'library_enabled'  => $settings['library_enabled'] && LibrarySchema::ready(),
        ];
    }

    public function storageScope(): string
    {
        $site = current_site();
        $id   = $site?->getKey() ?? 'control';
        $host = strtolower((string) request()->getHost());

        return substr(hash('sha256', $id . '|' . $host), 0, 20);
    }

    private function boolean(string $name, bool $default): bool
    {
        return ! in_array(plugin_setting(self::CODE . '.' . $name, $default ? '1' : '0'), [false, 0, '0', 'false', 'off', null], true);
    }

    private function integer(string $name, int $default, int $minimum, int $maximum): int
    {
        return max($minimum, min($maximum, (int) plugin_setting(self::CODE . '.' . $name, $default)));
    }

    /** @param list<string> $allowed */
    private function choice(string $name, array $allowed, string $default): string
    {
        $value = strtolower(trim((string) plugin_setting(self::CODE . '.' . $name, $default)));

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function color(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : '#2458d3';
    }
}
