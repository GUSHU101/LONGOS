<?php

namespace Beike\Services;

use Beike\Models\AdminUser;
use Beike\Repositories\FooterRepo;
use Beike\Repositories\MenuRepo;
use Illuminate\Support\Facades\Auth;

/**
 * Owns the Store Builder draft/published boundary.
 *
 * The live storefront continues to read the established design/header/footer/
 * menu settings. The editor writes one atomic draft bundle and only copies that
 * bundle to the live settings after an explicit publish action.
 */
class BuilderStateService
{
    public const DRAFT_SETTING = 'design_draft';

    public const FORMAT = 'beikeshop-builder-draft';

    /** @return array{format: string, version: int, updated_at: ?string, updated_by: ?int, design: array, header: array, footer: array, menu: array} */
    public static function published(): array
    {
        return self::normalize([
            'design' => system_setting('base.design_setting'),
            'header' => system_setting('base.header_setting'),
            'footer' => system_setting('base.footer_setting'),
            'menu'   => system_setting('base.menu_setting'),
        ]);
    }

    /** @return array{format: string, version: int, updated_at: ?string, updated_by: ?int, design: array, header: array, footer: array, menu: array} */
    public static function draft(): array
    {
        $published = self::published();
        $draft     = system_setting('base.' . self::DRAFT_SETTING);

        return is_array($draft) && is_array($draft['design'] ?? null)
            ? self::normalize($draft, $published)
            : $published;
    }

    /**
     * Normalize one complete visual state. Missing chrome values fall back to
     * the supplied state so old page-only snapshots remain recoverable.
     *
     * @return array{format: string, version: int, updated_at: ?string, updated_by: ?int, design: array, header: array, footer: array, menu: array}
     */
    public static function normalize(mixed $state, ?array $fallback = null): array
    {
        $state    = is_array($state) ? $state : [];
        $fallback = is_array($fallback) ? $fallback : [];

        return [
            'format'     => self::FORMAT,
            'version'    => max(0, (int) ($state['version'] ?? 0)),
            'updated_at' => is_string($state['updated_at'] ?? null) ? $state['updated_at'] : null,
            'updated_by' => is_numeric($state['updated_by'] ?? null) ? max(1, (int) $state['updated_by']) : null,
            'design'     => DesignService::normalizeDesignSetting($state['design'] ?? $fallback['design'] ?? []),
            'header'     => DesignService::normalizeHeaderSetting($state['header'] ?? $fallback['header'] ?? []),
            'footer'     => FooterRepo::normalizeFooterSetting($state['footer'] ?? $fallback['footer'] ?? []),
            'menu'       => MenuRepo::normalizeMenuSetting($state['menu'] ?? $fallback['menu'] ?? []),
        ];
    }

    /** Build a durable draft after the controller has acquired its write lock. */
    public static function makeDraft(array $state, int $version, ?int $adminId = null): array
    {
        $state['version']    = max(1, $version);
        $state['updated_at'] = now()->toIso8601String();
        $state['updated_by'] = $adminId;

        return self::normalize($state);
    }

    /** Only an authenticated administrator may activate draft rendering. */
    public static function isPreviewRequest(): bool
    {
        if (! app()->bound('request') || ! request()->boolean('design')) {
            return false;
        }

        try {
            return Auth::guard(AdminUser::AUTH_GUARD)->check();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{format: string, version: int, updated_at: ?string, updated_by: ?int, design: array, header: array, footer: array, menu: array} */
    public static function storefrontState(): array
    {
        return self::isPreviewRequest() ? self::draft() : self::published();
    }

    public static function hasUnpublishedChanges(array $draft, array $published): bool
    {
        return ! hash_equals(self::contentHash($published), self::contentHash($draft));
    }

    /** Stable comparison which deliberately excludes draft bookkeeping. */
    public static function contentHash(array $state): string
    {
        $state   = self::normalize($state);
        $content = array_intersect_key($state, array_flip(['design', 'header', 'footer', 'menu']));
        $encoded = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', is_string($encoded) ? $encoded : '');
    }
}
