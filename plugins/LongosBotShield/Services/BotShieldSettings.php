<?php

namespace Plugin\LongosBotShield\Services;

use Illuminate\Support\Facades\Crypt;
use Plugin\LongosBotShield\Support\IpAddress;
use Throwable;

class BotShieldSettings
{
    public const ENCRYPTED_PREFIX = 'enc:v1:';

    private const LEGACY_ORGANIZATION_DEFAULTS = [
        'facebook', 'meta', 'google', 'tiktok', 'amazon', 'hosting', 'data center',
        'datacenter', 'microsoft azure', 'digitalocean', 'linode', 'akamai', 'ovh',
        'hetzner', 'choopa', 'm2f',
    ];

    private const ORGANIZATION_DEFAULTS = [
        'hosting', 'data center', 'datacenter', 'microsoft azure', 'digitalocean',
        'linode', 'akamai', 'ovh', 'hetzner', 'choopa', 'm2f',
    ];

    public static function normalizeForStorage(array $fields): array
    {
        foreach (['ipinfo_token', 'cloudflare_radar_token'] as $name) {
            if (! array_key_exists($name, $fields)) {
                continue;
            }

            $secret = trim((string) $fields[$name]);
            if ($secret !== '' && ! str_starts_with($secret, self::ENCRYPTED_PREFIX)) {
                $fields[$name] = self::ENCRYPTED_PREFIX . Crypt::encryptString($secret);
            }
        }

        if (array_key_exists('organization_keywords', $fields)) {
            $keywords = self::parseCommaList($fields['organization_keywords']);
            if (self::isLegacyOrganizationDefaults($keywords)) {
                $fields['organization_keywords'] = implode(', ', self::ORGANIZATION_DEFAULTS);
            }
        }

        return $fields;
    }

    public function ipinfoToken(): string
    {
        return $this->secret('ipinfo_token');
    }

    public function ipinfoApiTier(): string
    {
        return (string) $this->value('ipinfo_api_tier', 'lite') === 'lookup'
            ? 'lookup'
            : 'lite';
    }

    public function cloudflareRadarToken(): string
    {
        $tenantToken = $this->secret('cloudflare_radar_token');

        return $tenantToken !== ''
            ? $tenantToken
            : trim((string) config('longos.cloudflare.radar_api_token', ''));
    }

    public function cloudflareRadarFallbackEnabled(): bool
    {
        return filter_var($this->value('cloudflare_radar_fallback_enabled', true), FILTER_VALIDATE_BOOLEAN);
    }

    private function secret(string $name): string
    {
        $stored = trim((string) $this->value($name, ''));
        if ($stored === '' || ! str_starts_with($stored, self::ENCRYPTED_PREFIX)) {
            return $stored;
        }

        try {
            return trim(Crypt::decryptString(substr($stored, strlen(self::ENCRYPTED_PREFIX))));
        } catch (Throwable) {
            return '';
        }
    }

    public function enforcementMode(): string
    {
        $mode = (string) $this->value('enforcement_mode', 'homepage');

        return in_array($mode, ['monitor', 'challenge', 'block', 'homepage'], true) ? $mode : 'homepage';
    }

    public function crawlerPolicy(): string
    {
        return (string) $this->value('crawler_policy', 'block_all') === 'allow_verified'
            ? 'allow_verified'
            : 'block_all';
    }

    public function requestsPerMinute(): int
    {
        return max(10, min(5000, (int) $this->value('requests_per_minute', 120)));
    }

    public function burstPerTenSeconds(): int
    {
        return max(5, min(500, (int) $this->value('burst_per_10_seconds', 35)));
    }

    public function cacheMinutes(): int
    {
        return max(5, min(10080, (int) $this->value('cache_minutes', 1440)));
    }

    public function providerRequestsPerMinute(): int
    {
        return max(10, min(5000, (int) $this->value('provider_requests_per_minute', 300)));
    }

    /** @return list<string> */
    public function allowCidrs(): array
    {
        return $this->list('allow_cidrs');
    }

    /** @return list<string> */
    public function denyCidrs(): array
    {
        return $this->list('deny_cidrs');
    }

    /** @return list<string> */
    public function blockedAsns(): array
    {
        $values = $this->list('blocked_asns', 'AS174, AS3356, AS6939');

        if ($this->useBuiltinCatalog()) {
            $values = array_merge(ThreatCatalog::HOSTING_ASNS, $values);
        }

        return $this->normalizeAsns($values);
    }

    /** @return list<string> */
    public function allowedAsns(): array
    {
        return $this->normalizeAsns($this->list('allowed_asns'));
    }

    /** @return list<string> */
    public function organizationKeywords(): array
    {
        $values = $this->commaList(
            'organization_keywords',
            implode(', ', self::ORGANIZATION_DEFAULTS),
        );
        if (self::isLegacyOrganizationDefaults(array_map(mb_strtolower(...), $values))) {
            $values = self::ORGANIZATION_DEFAULTS;
        }
        if ($this->useBuiltinCatalog()) {
            $values = array_merge(ThreatCatalog::ORGANIZATION_KEYWORDS, $values);
        }

        return array_values(array_unique(array_map(
            static fn (string $value): string => mb_strtolower($value),
            $values,
        )));
    }

    /** @return list<string> */
    public function allowedOrganizationKeywords(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $value): string => mb_strtolower($value),
            $this->commaList('allowed_organization_keywords'),
        )));
    }

    public function vendorAllowlistEnabled(): bool
    {
        return filter_var($this->value('vendor_allowlist_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function vendorAllowlistConfigured(): bool
    {
        return $this->vendorAllowlistEnabled()
            && ($this->allowedAsns() !== [] || $this->allowedOrganizationKeywords() !== []);
    }

    public function useBuiltinCatalog(): bool
    {
        return filter_var($this->value('use_builtin_catalog', true), FILTER_VALIDATE_BOOLEAN);
    }

    public function suspiciousPathDetection(): bool
    {
        return filter_var($this->value('suspicious_path_detection', true), FILTER_VALIDATE_BOOLEAN);
    }

    public function headerAnomalyDetection(): bool
    {
        return filter_var($this->value('header_anomaly_detection', true), FILTER_VALIDATE_BOOLEAN);
    }

    public function diagnosticLog(): bool
    {
        return filter_var($this->value('diagnostic_log', true), FILTER_VALIDATE_BOOLEAN);
    }

    public function tenantKey(): string
    {
        $site = current_site();
        if ($site !== null && $site->getKey() !== null) {
            return 'site:' . $site->getKey();
        }

        return 'host:' . strtolower((string) request()->getHost());
    }

    public function ipHash(string $ip): string
    {
        $normalized = IpAddress::normalize($ip);
        if ($normalized !== '') {
            $ip = $normalized;
        }

        return hash_hmac('sha256', $ip, (string) config('app.key', 'longos-bot-shield'));
    }

    private function value(string $name, mixed $default = null): mixed
    {
        return plugin_setting("longos_bot_shield.{$name}", $default);
    }

    /** @return list<string> */
    private function list(string $name, string $default = ''): array
    {
        $value = $this->value($name, $default);
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = preg_split('/[\s,;]+/u', (string) $value) ?: [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $items,
        ), static fn (string $item): bool => $item !== ''));
    }

    /** @return list<string> */
    private function commaList(string $name, string $default = ''): array
    {
        $value = $this->value($name, $default);
        $items = self::parseCommaList($value);

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $items,
        ), static fn (string $item): bool => $item !== ''));
    }

    /** @return list<string> */
    private static function parseCommaList(mixed $value): array
    {
        $items = is_array($value) ? $value : (preg_split('/[,;\r\n]+/u', (string) $value) ?: []);

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => mb_strtolower(trim((string) $item)),
            $items,
        ), static fn (string $item): bool => $item !== ''));
    }

    /** @param list<string> $keywords */
    private static function isLegacyOrganizationDefaults(array $keywords): bool
    {
        if ($keywords === self::LEGACY_ORGANIZATION_DEFAULTS) {
            return true;
        }

        return $keywords === array_values(array_filter(
            self::LEGACY_ORGANIZATION_DEFAULTS,
            static fn (string $keyword): bool => $keyword !== 'datacenter',
        ));
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function normalizeAsns(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static function (string $value): string {
                if (preg_match('/^(?:AS)?([1-9]\d{0,9})$/i', trim($value), $matches) !== 1) {
                    return '';
                }

                $number = (int) $matches[1];

                return $number <= 4294967295 ? 'AS' . $number : '';
            },
            $values,
        ))));
    }
}
