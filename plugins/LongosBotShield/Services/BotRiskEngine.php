<?php

namespace Plugin\LongosBotShield\Services;

use Beike\Services\CloudflareIpRangeService;
use Illuminate\Http\Request;
use Plugin\LongosBotShield\Support\CidrMatcher;
use Plugin\LongosBotShield\Support\IpAddress;

class BotRiskEngine
{
    private const CHINA_REGION_COUNTRY_CODES = ['CN', 'HK', 'MO'];

    public function __construct(
        private readonly BotShieldSettings $settings,
        private readonly NetworkIntelligenceClient $networkIntelligence,
    ) {}

    public function assess(Request $request): RiskAssessment
    {
        return $this->assessRequest($request);
    }

    /**
     * Evaluate a request with provider data that has already been verified.
     *
     * The administration health check uses this path so it reports the exact
     * decision made from its live probe without issuing a second HTTP request.
     */
    public function assessWithNetwork(Request $request, array $network): RiskAssessment
    {
        return $this->assessRequest($request, $network);
    }

    private function assessRequest(Request $request, ?array $verifiedNetwork = null): RiskAssessment
    {
        if ($this->settings->blockAllTraffic()) {
            return new RiskAssessment(['global_deny']);
        }

        $ip = IpAddress::normalize((string) $request->ip());
        if ($ip !== '' && CidrMatcher::contains($ip, $this->settings->allowCidrs())) {
            return new RiskAssessment(['ip_allowlist'], true);
        }

        $reasons = [];
        $add     = static function (string $reason) use (&$reasons): void {
            if (! in_array($reason, $reasons, true)) {
                $reasons[] = $reason;
            }
        };

        if ($ip === '') {
            $add('ip_invalid');

            return new RiskAssessment($reasons);
        }

        $network = $verifiedNetwork;
        if ($this->settings->vendorAllowlistConfigured()) {
            $network         ??= $this->networkIntelligence->lookup($ip);
            $allowlistReason = $this->vendorAllowlistReason($network);
            if ($allowlistReason !== null) {
                return new RiskAssessment([$allowlistReason], true);
            }
        }

        $trustedCloudflareCountry = $this->trustedCloudflareCountryCode($request);
        if ($this->isChinaRegionCountry($trustedCloudflareCountry)) {
            $add('country_region_denylist');
        }

        if (CidrMatcher::contains($ip, $this->settings->denyCidrs())) {
            $add('ip_denylist');
        }

        $userAgent = mb_strtolower(trim((string) $request->userAgent()));
        if ($userAgent === '') {
            $add('ua_missing');
        }
        if (mb_strlen($userAgent) > 1024) {
            $add('ua_oversized');
        }
        if ($this->containsAny($userAgent, ThreatCatalog::AUTOMATION_MARKERS)) {
            $add('ua_automation');
        }
        if ($this->containsAny($userAgent, ThreatCatalog::SCRIPT_CLIENT_MARKERS)) {
            $add('ua_script_client');
        }

        if ($this->crawlerFamily($userAgent) !== null) {
            $add('crawler_declared');
        } elseif (preg_match('/(?:bot|spider|crawler|scraper|scanner)(?:[\/\s;_\-]|$)/i', $userAgent) === 1) {
            $add('ua_generic_crawler');
        }

        $this->detectAutomationHeaders($request, $userAgent, $add);

        // Deterministic local matches do not need an external provider round trip.
        if ($this->hasDeterministicLocalMatch($reasons)) {
            return new RiskAssessment($reasons);
        }

        $network ??= $this->networkIntelligence->lookup($ip);
        $this->detectNetwork($network, $add, ! $this->hasUsableCloudflareCountry($trustedCloudflareCountry));

        return new RiskAssessment($reasons);
    }

    /** @param callable(string): void $add */
    private function detectNetwork(array $network, callable $add, bool $useNetworkCountry = true): void
    {
        if (($network['status'] ?? '') !== 'ok') {
            return;
        }

        if ($useNetworkCountry && $this->isChinaRegionCountry((string) ($network['country_code'] ?? ''))) {
            $add('country_region_denylist');
        }

        if ($network['is_bogon'] ?? false) {
            $add('bogon_network');
        }

        $asn = strtoupper((string) ($network['asn'] ?? ''));
        if ($asn !== '' && in_array($asn, $this->settings->blockedAsns(), true)) {
            $add('asn_denylist');
        }

        // IPInfo Lite `as_domain` is useful context but is not ownership
        // proof. The dataset can associate an unrelated access ASN with a
        // platform domain, so hard shielding uses exact ASN or AS/company
        // names only and never a domain substring by itself.
        $organization = mb_strtolower(implode(' ', [
            (string) ($network['name'] ?? ''),
            (string) ($network['type'] ?? ''),
            (string) ($network['company_name'] ?? ''),
            (string) ($network['company_type'] ?? ''),
            (string) ($network['privacy_name'] ?? ''),
        ]));
        if ($this->matchesOrganizationKeyword($organization, $this->settings->organizationKeywords())) {
            $add('organization_keyword');
        }

        if (($network['is_hosting'] ?? false) || ($network['type'] ?? '') === 'hosting') {
            $add('hosting_network');
        }
        if ($network['is_res_proxy'] ?? false) {
            $add('residential_proxy');
        }
        if (($network['is_anonymous'] ?? false)
            || ($network['is_proxy'] ?? false)
            || ($network['is_vpn'] ?? false)
            || ($network['is_tor'] ?? false)
            || ($network['is_relay'] ?? false)) {
            $add('anonymous_network');
        }
    }

    /** @param callable(string): void $add */
    private function detectAutomationHeaders(Request $request, string $userAgent, callable $add): void
    {
        if ($userAgent === '') {
            return;
        }

        $clientHints = mb_strtolower(implode(' ', [
            (string) $request->header('sec-ch-ua'),
            (string) $request->header('sec-ch-ua-platform'),
            (string) $request->header('sec-ch-ua-model'),
        ]));
        if ($this->containsAny($clientHints, ThreatCatalog::AUTOMATION_MARKERS)) {
            $add('client_hints_automation');
        }
        foreach (['x-playwright', 'x-puppeteer', 'x-selenium', 'x-webdriver'] as $header) {
            if (trim((string) $request->header($header)) !== '') {
                $add('automation_header');

                break;
            }
        }
    }

    /** @param list<string> $reasons */
    private function hasDeterministicLocalMatch(array $reasons): bool
    {
        return array_intersect($reasons, [
            'country_region_denylist',
            'ip_denylist',
            'ua_automation',
            'ua_script_client',
            'crawler_declared',
            'ua_generic_crawler',
            'client_hints_automation',
            'automation_header',
        ]) !== [];
    }

    private function trustedCloudflareCountryCode(Request $request): string
    {
        if (! $request->attributes->getBoolean(CloudflareIpRangeService::REQUEST_ATTRIBUTE)) {
            return '';
        }

        $context = $request->attributes->get(CloudflareIpRangeService::VISITOR_CONTEXT_ATTRIBUTE);
        if (! is_array($context)) {
            return '';
        }

        return strtoupper(trim((string) ($context['country_code'] ?? '')));
    }

    private function isChinaRegionCountry(string $countryCode): bool
    {
        return $this->settings->blockChinaRegions()
            && in_array(strtoupper(trim($countryCode)), self::CHINA_REGION_COUNTRY_CODES, true);
    }

    private function hasUsableCloudflareCountry(string $countryCode): bool
    {
        $countryCode = strtoupper(trim($countryCode));

        return $countryCode !== 'XX' && preg_match('/^[A-Z]{2}$/D', $countryCode) === 1;
    }

    private function crawlerFamily(string $userAgent): ?string
    {
        foreach (ThreatCatalog::CRAWLER_MARKERS as $family => $markers) {
            if ($this->containsAny($userAgent, $markers)) {
                return $family;
            }
        }

        return null;
    }

    private function vendorAllowlistReason(array $network): ?string
    {
        if (($network['status'] ?? '') !== 'ok') {
            return null;
        }

        $asn = strtoupper(trim((string) ($network['asn'] ?? '')));
        if ($asn !== '' && in_array($asn, $this->settings->allowedAsns(), true)) {
            return 'vendor_allowlist_asn';
        }

        $names = mb_strtolower(implode(' ', [
            (string) ($network['name'] ?? ''),
            (string) ($network['company_name'] ?? ''),
        ]));
        $domains = array_values(array_filter(array_map(
            $this->normalizeDomain(...),
            [
                (string) ($network['domain'] ?? ''),
                (string) ($network['company_domain'] ?? ''),
            ],
        )));
        foreach ($this->settings->allowedOrganizationKeywords() as $keyword) {
            $allowedDomain = $this->normalizeDomain($keyword);
            if ($allowedDomain !== '') {
                foreach ($domains as $domain) {
                    if ($domain === $allowedDomain || str_ends_with($domain, '.' . $allowedDomain)) {
                        return 'vendor_allowlist_organization';
                    }
                }

                continue;
            }

            if ($this->matchesOrganizationKeyword(
                trim($names . ' ' . implode(' ', $domains)),
                [$keyword],
            )) {
                return 'vendor_allowlist_organization';
            }
        }

        return null;
    }

    private function normalizeDomain(string $value): string
    {
        $value = mb_strtolower(rtrim(trim($value), '.'));
        if (strlen($value) > 253
            || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $value) !== 1) {
            return '';
        }

        return $value;
    }

    /** @param list<string> $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $keywords */
    private function matchesOrganizationKeyword(string $organization, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            $keyword = mb_strtolower(trim($keyword));
            if ($keyword === '') {
                continue;
            }
            if (mb_strlen($keyword) <= 4 && preg_match('/^[a-z0-9]+$/', $keyword) === 1) {
                if (preg_match('/(?<![a-z0-9])' . preg_quote($keyword, '/') . '(?![a-z0-9])/i', $organization) === 1) {
                    return true;
                }

                continue;
            }
            if (str_contains($organization, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
