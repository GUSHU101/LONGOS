<?php

namespace Plugin\LongosBotShield\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Plugin\LongosBotShield\Support\CidrMatcher;
use Plugin\LongosBotShield\Support\IpAddress;

class BotRiskEngine
{
    public function __construct(
        private readonly BotShieldSettings $settings,
        private readonly NetworkIntelligenceClient $networkIntelligence,
        private readonly CrawlerVerifier $crawlerVerifier,
    ) {}

    public function assess(Request $request): RiskAssessment
    {
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

        $network = null;
        if ($this->settings->vendorAllowlistConfigured()) {
            $network         = $this->networkIntelligence->lookup($ip);
            $allowlistReason = $this->vendorAllowlistReason($network);
            if ($allowlistReason !== null) {
                return new RiskAssessment([$allowlistReason], true);
            }
        }

        if (CidrMatcher::contains($ip, $this->settings->denyCidrs())) {
            $add('ip_denylist');
        }

        $this->detectRequestRate($ip, $add);
        $this->detectRequestTarget($request, $add);

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

        $crawlerFamily = $this->crawlerFamily($userAgent);
        if ($crawlerFamily !== null && $this->settings->crawlerPolicy() === 'block_all') {
            $add('crawler_declared');
        } elseif ($crawlerFamily                                                                               === null
            && preg_match('/(?:bot|spider|crawler|scraper|scanner)(?:[\/\s;_\-]|$)/i', $userAgent)             === 1) {
            $add('ua_generic_crawler');
        }

        $this->detectBrowserCoherence($request, $userAgent, $add);

        // Deterministic local matches do not need a paid provider round trip.
        if ($this->hasDeterministicLocalMatch($reasons)) {
            return new RiskAssessment($reasons);
        }

        $network ??= $this->networkIntelligence->lookup($ip);
        $verified = false;
        if ($crawlerFamily !== null) {
            $verified = $this->crawlerVerifier->verify($crawlerFamily, $ip, $network);
            if (! $verified) {
                $add('crawler_spoofed');
            } else {
                $reasons[] = 'crawler_verified';
            }
        }

        if ($verified) {
            return new RiskAssessment(array_values(array_unique($reasons)), false, true);
        }

        $this->detectNetwork($network, $add);

        return new RiskAssessment($reasons);
    }

    /** @param callable(string): void $add */
    private function detectRequestRate(string $ip, callable $add): void
    {
        $key       = hash('sha256', $this->settings->tenantKey() . '|' . $this->settings->ipHash($ip));
        $minuteKey = 'longos:bot-shield:rate:minute:' . $key;
        if (RateLimiter::tooManyAttempts($minuteKey, $this->settings->requestsPerMinute())) {
            $add('rate_limit');
        } else {
            RateLimiter::hit($minuteKey, 60);
        }

        $burstKey = 'longos:bot-shield:rate:burst:' . $key;
        if (RateLimiter::tooManyAttempts($burstKey, $this->settings->burstPerTenSeconds())) {
            $add('rate_burst');
        } else {
            RateLimiter::hit($burstKey, 10);
        }
    }

    /** @param callable(string): void $add */
    private function detectRequestTarget(Request $request, callable $add): void
    {
        if (in_array(strtoupper($request->getMethod()), ['CONNECT', 'TRACE', 'TRACK'], true)) {
            $add('method_unsafe');
        }

        if (! $this->settings->suspiciousPathDetection()) {
            return;
        }

        $rawUri = (string) $request->getRequestUri();
        if (strlen($rawUri) > 8192) {
            $add('request_target_oversized');

            return;
        }

        $variants = [mb_strtolower($rawUri)];
        for ($pass = 0; $pass < 4; $pass++) {
            $decoded = rawurldecode($variants[array_key_last($variants)]);
            if ($decoded === $variants[array_key_last($variants)]) {
                break;
            }
            $variants[] = $decoded;
        }
        $target = implode(' ', $variants);
        if ($this->containsAny($target, ThreatCatalog::SUSPICIOUS_PATH_FRAGMENTS)
            || preg_match('~(?:\.\.[/\\\\]|%2e%2e|%00|%5c|(?:/|%2f)%2e(?:env|git|svn|aws|docker))~i', $target) === 1) {
            $add('suspicious_path');
        }
    }

    /** @param callable(string): void $add */
    private function detectNetwork(array $network, callable $add): void
    {
        if (($network['status'] ?? '') !== 'ok') {
            return;
        }

        if ($network['is_bogon'] ?? false) {
            $add('bogon_network');
        }

        $asn = strtoupper((string) ($network['asn'] ?? ''));
        if ($asn !== '' && in_array($asn, $this->settings->blockedAsns(), true)) {
            $add('asn_denylist');
        }

        $organization = mb_strtolower(implode(' ', [
            (string) ($network['name'] ?? ''),
            (string) ($network['domain'] ?? ''),
            (string) ($network['type'] ?? ''),
            (string) ($network['company_name'] ?? ''),
            (string) ($network['company_domain'] ?? ''),
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
    private function detectBrowserCoherence(Request $request, string $userAgent, callable $add): void
    {
        if ($userAgent === '' || ! $this->settings->headerAnomalyDetection()) {
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

        $looksLikeBrowser = $this->containsAny($userAgent, [
            'mozilla/', 'chrome/', 'crios/', 'safari/', 'firefox/', 'fxios/', 'edg/',
        ]);
        if (! $looksLikeBrowser) {
            $add('ua_non_browser');

            return;
        }

        $accept       = mb_strtolower(trim((string) $request->header('accept')));
        $fetchMode    = mb_strtolower(trim((string) $request->header('sec-fetch-mode')));
        $fetchDest    = mb_strtolower(trim((string) $request->header('sec-fetch-dest')));
        $fetchSite    = mb_strtolower(trim((string) $request->header('sec-fetch-site')));
        $isNavigation = $request->isMethod('GET')
            && (str_contains($accept, 'text/html') || $fetchDest === 'document');

        $modernDesktopChromium = preg_match('/(?:chrome|edg)\/(?:[8-9][0-9]|[1-9][0-9]{2,})/i', $userAgent) === 1
            && ! $this->containsAny($userAgent, ['android', 'crios/']);
        if ($modernDesktopChromium && trim((string) $request->header('sec-ch-ua')) === '') {
            $add('client_hints_missing');
        }
        if ($accept === '') {
            $add('accept_missing');
        } elseif ($isNavigation && ! str_contains($accept, 'text/html')) {
            $add('accept_navigation_mismatch');
        }
        if (trim((string) $request->header('accept-language')) === '') {
            $add('language_missing');
        }

        if ($isNavigation && $fetchMode !== '' && $fetchMode !== 'navigate') {
            $add('fetch_mode_mismatch');
        }
        if ($isNavigation && $fetchDest !== '' && $fetchDest !== 'document') {
            $add('fetch_destination_mismatch');
        }
        if ($fetchSite !== '' && ! in_array($fetchSite, ['none', 'same-origin', 'same-site', 'cross-site'], true)) {
            $add('fetch_site_invalid');
        }

        $platform = mb_strtolower(trim((string) $request->header('sec-ch-ua-platform'), " \t\n\r\0\x0B\""));
        if (($platform === 'windows' && $this->containsAny($userAgent, ['android', 'iphone', 'ipad', 'macintosh']))
            || ($platform === 'android' && ! str_contains($userAgent, 'android'))
            || ($platform === 'macos' && ! $this->containsAny($userAgent, ['macintosh', 'mac os x']))) {
            $add('platform_mismatch');
        }
    }

    /** @param list<string> $reasons */
    private function hasDeterministicLocalMatch(array $reasons): bool
    {
        return array_intersect($reasons, [
            'ip_denylist',
            'rate_limit',
            'rate_burst',
            'method_unsafe',
            'request_target_oversized',
            'suspicious_path',
            'ua_automation',
            'ua_script_client',
            'crawler_declared',
            'ua_generic_crawler',
            'client_hints_automation',
            'automation_header',
        ]) !== [];
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
