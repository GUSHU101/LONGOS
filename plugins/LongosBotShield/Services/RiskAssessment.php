<?php

namespace Plugin\LongosBotShield\Services;

class RiskAssessment
{
    private const SHIELDING_SCOPE_REASONS = [
        'ip_denylist',
        'asn_denylist',
        'organization_keyword',
        'hosting_network',
        'residential_proxy',
        'anonymous_network',
        'crawler_declared',
        'crawler_spoofed',
        'crawler_verified',
        'ua_generic_crawler',
        'ua_automation',
        'ua_script_client',
        'client_hints_automation',
        'automation_header',
    ];

    private const ADDITIONAL_ENFORCEMENT_REASONS = [
        'ip_invalid',
        'bogon_network',
        'method_unsafe',
        'request_target_oversized',
        'suspicious_path',
        'rate_limit',
        'rate_burst',
    ];

    private const IMMEDIATE_BLOCK_REASONS = [
        'ip_invalid',
        'ip_denylist',
        'bogon_network',
        'method_unsafe',
        'request_target_oversized',
        'suspicious_path',
        'crawler_declared',
        'crawler_spoofed',
        'ua_generic_crawler',
        'ua_automation',
        'ua_script_client',
        'client_hints_automation',
        'automation_header',
        'rate_limit',
        'rate_burst',
    ];

    /** @param list<string> $reasons */
    public function __construct(
        public readonly array $reasons,
        public readonly bool $allowlisted = false,
        public readonly bool $verifiedCrawler = false,
    ) {}

    public function hasReason(string $reason): bool
    {
        return in_array($reason, $this->reasons, true);
    }

    public function matchesConfiguredShieldingScope(): bool
    {
        foreach (self::SHIELDING_SCOPE_REASONS as $reason) {
            if ($this->hasReason($reason)) {
                return true;
            }
        }

        return false;
    }

    public function matchesEnforcementScope(): bool
    {
        if ($this->matchesConfiguredShieldingScope()) {
            return true;
        }

        foreach (self::ADDITIONAL_ENFORCEMENT_REASONS as $reason) {
            if ($this->hasReason($reason)) {
                return true;
            }
        }

        return false;
    }

    public function requiresImmediateBlock(): bool
    {
        foreach (self::IMMEDIATE_BLOCK_REASONS as $reason) {
            if ($this->hasReason($reason)) {
                return true;
            }
        }

        return false;
    }
}
