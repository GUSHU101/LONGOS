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
        'ua_generic_crawler',
        'ua_automation',
        'ua_script_client',
        'client_hints_automation',
        'automation_header',
    ];

    /** @param list<string> $reasons */
    public function __construct(
        public readonly array $reasons,
        public readonly bool $allowlisted = false,
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
}
