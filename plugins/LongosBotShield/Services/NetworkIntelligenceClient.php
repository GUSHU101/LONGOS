<?php

namespace Plugin\LongosBotShield\Services;

class NetworkIntelligenceClient
{
    public function __construct(
        private readonly IpInfoClient $ipInfo,
        private readonly IpApiClient $ipApi,
    ) {}

    public function lookup(string $ip): array
    {
        $primary = $this->ipInfo->lookup($ip);
        if (! $this->ipApi->available()) {
            return $primary;
        }

        $primaryUnavailable = ($primary['status'] ?? '') === 'unavailable';
        $primaryIncomplete  = ($primary['status'] ?? '') === 'ok'
            && ((string) ($primary['asn'] ?? '') === '' || ! ($primary['security_data_present'] ?? false));
        if (! $primaryUnavailable && ! $primaryIncomplete) {
            return $primary;
        }

        $fallback = $this->ipApi->lookup($ip);
        if (($fallback['status'] ?? '') !== 'ok') {
            return $primary;
        }
        if ($primaryUnavailable) {
            return $fallback;
        }

        return $this->merge($primary, $fallback);
    }

    private function merge(array $primary, array $fallback): array
    {
        $result                          = $primary;
        $result['provider']              = 'ipinfo+ip-api';
        $result['security_data_present'] = true;

        foreach (['asn', 'name', 'domain', 'type', 'company_name', 'company_domain', 'company_type', 'privacy_name'] as $key) {
            if (trim((string) ($result[$key] ?? '')) === '') {
                $result[$key] = (string) ($fallback[$key] ?? '');
            }
        }
        foreach (['is_hosting', 'is_anonymous', 'is_proxy', 'is_res_proxy', 'is_vpn', 'is_tor', 'is_relay', 'is_anycast', 'is_bogon'] as $key) {
            $result[$key] = (bool) ($primary[$key] ?? false) || (bool) ($fallback[$key] ?? false);
        }

        return $result;
    }
}
