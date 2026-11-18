<?php

use Illuminate\Validation\Rule;
use Plugin\LongosBotShield\Services\ThreatCatalog;
use Plugin\LongosBotShield\Support\CidrMatcher;

$networkIntelligenceRequired = static fn (): bool => filter_var(request()->input('status', false), FILTER_VALIDATE_BOOLEAN)
    && ! filter_var(request()->input('block_all_traffic', false), FILTER_VALIDATE_BOOLEAN);

$tokenRules = static function (string $attribute, mixed $value, Closure $fail): void {
    if (! is_string($value)) {
        $fail('无效的 IPInfo Token。');

        return;
    }
    if ($value !== '' && preg_match('/^[\x21-\x7E]{6,2048}$/D', $value) !== 1) {
        $fail('无效的 IPInfo Token：只允许 6-2048 个可见 ASCII 字符，不能包含空格或换行。');
    }
};

$cidrRules = static function (string $attribute, mixed $value, Closure $fail): void {
    if (! is_string($value)) {
        $fail('无效的 IP/CIDR 列表。');

        return;
    }
    $invalid = CidrMatcher::invalidEntries($value);
    if ($invalid !== []) {
        $fail('无效的 IP/CIDR：' . implode('、', array_slice($invalid, 0, 5)));
    }
};

$asnRules = static function (string $attribute, mixed $value, Closure $fail): void {
    if (! is_string($value)) {
        $fail('无效的 ASN 列表。');

        return;
    }
    $entries = preg_split('/[\s,;]+/u', trim($value)) ?: [];
    $invalid = array_values(array_filter($entries, static function (string $entry): bool {
        if ($entry === '' || preg_match('/^(?:AS)?([1-9]\d{0,9})$/i', $entry, $matches) !== 1) {
            return $entry !== '';
        }

        return (int) $matches[1] > 4294967295;
    }));
    if ($invalid !== []) {
        $fail('无效的 ASN：' . implode('、', array_slice($invalid, 0, 5)));
    }
};

return [
    [
        'name'            => 'ipinfo_token',
        'label_key'       => 'shield.ipinfo_token',
        'description_key' => 'shield.ipinfo_token_help',
        'section_key'     => 'shield.section_provider',
        'type'            => 'password',
        // Required conditionally by the validator only when network
        // intelligence is active. Uniform all-traffic mode does not need one.
        'required'        => false,
        'rules'           => [Rule::requiredIf($networkIntelligenceRequired), 'nullable', 'string', 'max:2048', $tokenRules],
    ],
    [
        'name'               => 'ipinfo_health',
        'label_key'          => 'shield.ipinfo_health',
        'description_key'    => 'shield.ipinfo_health_help',
        'section_key'        => 'shield.section_provider',
        'type'               => 'action',
        'required'           => false,
        'action_route'       => 'plugins.bot_shield_health',
        'action_form'        => true,
        'action_label_key'   => 'shield.ipinfo_health_action',
        'action_feedback'    => 'longos-bot-shield-health-feedback',
    ],
    [
        'name'               => 'builtin_catalog',
        'label_key'          => 'shield.builtin_catalog',
        'description_key'    => 'shield.builtin_catalog_help',
        'description_params' => [
            'platforms' => count(ThreatCatalog::platformAsns()),
            'hosting'   => count(ThreatCatalog::HOSTING_ASNS),
            'names'     => count(ThreatCatalog::ORGANIZATION_KEYWORDS) + count(ThreatCatalog::PROXY_ORGANIZATION_KEYWORDS),
            'crawlers'  => count(ThreatCatalog::crawlerMarkers()),
            'headless'  => count(ThreatCatalog::AUTOMATION_MARKERS),
            'scripts'   => count(ThreatCatalog::SCRIPT_CLIENT_MARKERS),
        ],
        'section_key' => 'shield.section_lists',
        'type'        => 'guide',
        'tone'        => 'success',
        'required'    => false,
    ],
    [
        'name'            => 'block_all_traffic',
        'label_key'       => 'shield.block_all_traffic',
        'description_key' => 'shield.block_all_traffic_help',
        'section_key'     => 'shield.section_policy',
        'type'            => 'bool',
        'value'           => '0',
        'required'        => false,
        'rules'           => ['nullable', 'boolean'],
    ],
    [
        'name'            => 'block_china_regions',
        'label_key'       => 'shield.block_china_regions',
        'description_key' => 'shield.block_china_regions_help',
        'section_key'     => 'shield.section_policy',
        'type'            => 'bool',
        'value'           => '0',
        'required'        => false,
        'rules'           => ['nullable', 'boolean'],
    ],
    [
        'name'            => 'vendor_allowlist_enabled',
        'label_key'       => 'shield.vendor_allowlist_enabled',
        'description_key' => 'shield.vendor_allowlist_enabled_help',
        'section_key'     => 'shield.section_policy',
        'type'            => 'bool',
        'value'           => '0',
        'required'        => false,
        'rules'           => ['nullable', 'boolean'],
    ],
    [
        'name'            => 'cache_minutes',
        'label_key'       => 'shield.cache_minutes',
        'description_key' => 'shield.cache_minutes_help',
        'section_key'     => 'shield.section_provider',
        'type'            => 'number',
        'value'           => '1440',
        'min'             => 5,
        'max'             => 10080,
        'required'        => true,
        'rules'           => ['required', 'integer', 'min:5', 'max:10080'],
    ],
    [
        'name'            => 'provider_requests_per_minute',
        'label_key'       => 'shield.provider_requests_per_minute',
        'description_key' => 'shield.provider_requests_per_minute_help',
        'section_key'     => 'shield.section_provider',
        'type'            => 'number',
        'value'           => '300',
        'min'             => 10,
        'max'             => 5000,
        'required'        => true,
        'rules'           => ['required', 'integer', 'min:10', 'max:5000'],
    ],
    [
        'name'            => 'allowed_asns',
        'label_key'       => 'shield.allowed_asns',
        'description_key' => 'shield.allowed_asns_help',
        'section_key'     => 'shield.section_lists',
        'type'            => 'textarea',
        'value'           => '',
        'required'        => false,
        'rules'           => ['nullable', 'string', 'max:8000', $asnRules],
    ],
    [
        'name'            => 'allowed_organization_keywords',
        'label_key'       => 'shield.allowed_organization_keywords',
        'description_key' => 'shield.allowed_organization_keywords_help',
        'section_key'     => 'shield.section_lists',
        'type'            => 'textarea',
        'value'           => '',
        'required'        => false,
        'rules'           => ['nullable', 'string', 'max:12000'],
    ],
    [
        'name'            => 'allow_cidrs',
        'label_key'       => 'shield.allow_cidrs',
        'description_key' => 'shield.allow_cidrs_help',
        'section_key'     => 'shield.section_lists',
        'type'            => 'textarea',
        'value'           => '',
        'required'        => false,
        'rules'           => ['nullable', 'string', 'max:12000', $cidrRules],
    ],
    [
        'name'            => 'deny_cidrs',
        'label_key'       => 'shield.deny_cidrs',
        'description_key' => 'shield.deny_cidrs_help',
        'section_key'     => 'shield.section_lists',
        'type'            => 'textarea',
        'value'           => '',
        'required'        => false,
        'rules'           => ['nullable', 'string', 'max:12000', $cidrRules],
    ],
    [
        'name'            => 'blocked_asns',
        'label_key'       => 'shield.blocked_asns',
        'description_key' => 'shield.blocked_asns_help',
        'section_key'     => 'shield.section_lists',
        'type'            => 'textarea',
        'value'           => '',
        'required'        => false,
        'rules'           => ['nullable', 'string', 'max:8000', $asnRules],
    ],
    [
        'name'            => 'organization_keywords',
        'label_key'       => 'shield.organization_keywords',
        'description_key' => 'shield.organization_keywords_help',
        'section_key'     => 'shield.section_lists',
        'type'            => 'textarea',
        'value'           => 'hosting, data center, datacenter, microsoft azure, digitalocean, linode, akamai, ovh, hetzner, choopa, m2f',
        'required'        => false,
        'rules'           => ['nullable', 'string', 'max:12000'],
    ],
    [
        'name'            => 'diagnostic_log',
        'label_key'       => 'shield.diagnostic_log',
        'description_key' => 'shield.diagnostic_log_help',
        'section_key'     => 'shield.section_diagnostics',
        'type'            => 'bool',
        'value'           => '1',
        'required'        => false,
        'rules'           => ['nullable', 'boolean'],
    ],
];
