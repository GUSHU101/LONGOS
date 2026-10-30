<?php

namespace Plugin\PixelTracking\Services;

final class TrackingEventId
{
    /**
     * Build a provider-safe ID that stays stable for one site and entity while
     * remaining unique when several LONGOS sites share the same pixel.
     */
    public static function make(string $event, string|int $entity): string
    {
        $prefix = strtolower((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($event)));
        $prefix = trim(substr($prefix ?: 'event', 0, 24), '_-') ?: 'event';
        $digest = hash('sha256', implode('|', [self::siteNamespace(), $prefix, (string) $entity]));

        return $prefix . '_' . substr($digest, 0, 48);
    }

    /**
     * The application key distinguishes separate installations; the tenant
     * UUID distinguishes storefronts inside one SaaS installation. Neither
     * value is exposed because only this one-way digest reaches providers.
     */
    public static function siteNamespace(): string
    {
        $site         = function_exists('current_site') ? current_site() : null;
        $installation = (string) config('app.key', 'longos');
        $siteIdentity = (string) ($site?->uuid ?: $site?->code ?: 'single-store');

        return substr(hash('sha256', $installation . '|' . $siteIdentity), 0, 24);
    }
}
