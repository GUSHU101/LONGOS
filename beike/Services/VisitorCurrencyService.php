<?php

namespace Beike\Services;

use GeoIp2\Database\Reader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves only from the visitor IP: an explicitly trusted Cloudflare country
 * header, when enabled, then the optional local GeoLite2 database. No visitor data is
 * sent to an external GeoIP service and there is no manual currency override.
 */
class VisitorCurrencyService
{
    private const DEFAULT_CURRENCY = 'USD';

    private const EMPTY_COUNTRY = '__none__';

    public function isEnabled(): bool
    {
        // Serving an existing validated snapshot must not depend on the API
        // key still being readable on every request. The key is needed only
        // by the scheduled refresh; an empty/unseeded library falls back USD.
        return CurrencyService::getInstance()->applyStoredRates();
    }

    /** @return array{currency:string,country:?string,source:string} */
    public function resolve(Request $request, bool $persistSession = true): array
    {
        $rates = CurrencyService::getInstance();
        if (! $rates->applyStoredRates()) {
            return ['currency' => self::DEFAULT_CURRENCY, 'country' => null, 'source' => 'rates_unavailable'];
        }

        $cached        = $persistSession ? session('currency_auto_state') : null;
        $ipFingerprint = $this->ipFingerprint($request);
        if ($ipFingerprint !== null
            && is_array($cached)
            && (int) ($cached['expires_at'] ?? 0) > now()->getTimestamp()
            && is_string($cached['ip_fingerprint'] ?? null)
            && hash_equals($cached['ip_fingerprint'], $ipFingerprint)) {
            $currency = $this->currencyCode((string) ($cached['currency'] ?? ''), $rates);
            if ($currency) {
                return [
                    'currency' => $currency,
                    'country'  => $this->normalizeCountry($cached['country'] ?? null),
                    'source'   => 'session',
                ];
            }
        }

        $country  = $this->countryCode($request);
        $currency = $this->currencyCode($this->currencyForCountry($country), $rates) ?? self::DEFAULT_CURRENCY;
        $source   = $country ? 'ip' : 'usd_fallback';

        if ($persistSession) {
            session([
                'currency'            => $currency,
                'currency_auto_state' => [
                    'currency'       => $currency,
                    'country'        => $country,
                    // A currency choice is valid only for the same visitor
                    // address.  This avoids retaining a previous network's
                    // currency after roaming, VPN changes, or a new visitor
                    // receives an existing session cookie.
                    'ip_fingerprint' => $ipFingerprint,
                    'expires_at'     => now()->addDay()->getTimestamp(),
                ],
            ]);
        }

        return ['currency' => $currency, 'country' => $country, 'source' => $source];
    }

    /**
     * Read-only readiness information for the administration page. It avoids
     * claiming that country detection is operational when neither a trusted
     * Cloudflare header nor a local GeoLite2 database can be used.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(?Request $request = null): array
    {
        $cdnEnabled          = (bool) config('currency.trust_cdn_country_headers', false);
        $localInstalled      = @is_readable($this->localDatabasePath());
        $localUsable         = $this->localDatabaseUsable();
        $cloudflare          = new CloudflareIpRangeService;
        $cloudflareVerified  = $request ? $cloudflare->isCloudflareRequest($request) : false;
        $headerPresent       = $request ? trim((string) $request->header('CF-IPCountry')) !== '' : false;
        $headerCountry       = $request ? $this->normalizeCountry($request->header('CF-IPCountry')) : null;
        $trustedHeaderReady  = $cdnEnabled && $cloudflareVerified && $headerCountry !== null;
        $clientIpPublic      = $request ? $this->isPublicIp(getClientIp($request)) : false;
        $country             = $request ? $this->countryCode($request) : null;
        $source              = $trustedHeaderReady && $country === $headerCountry
            ? 'cloudflare'
            : ($country && $localUsable ? 'geolite2' : 'unavailable');
        $issue               = null;

        if (! $trustedHeaderReady && ! $localUsable) {
            $issue = match (true) {
                $headerPresent  && ! $cloudflareVerified                          => 'untrusted_country_header',
                $cdnEnabled     && $cloudflareVerified && $headerCountry === null => 'missing_country_header',
                $localInstalled && ! $localUsable                                 => 'invalid_geolite_database',
                ! $cdnEnabled                                                     => 'country_sources_disabled',
                default                                                           => 'no_country_source',
            };
        }

        return [
            'policy_revision'            => CurrencyService::POLICY_REVISION,
            'cdn_headers_enabled'        => $cdnEnabled,
            'cloudflare_verified'        => $cloudflareVerified,
            'country_header_present'     => $headerPresent,
            'country_header_valid'       => $headerCountry !== null,
            'local_database_installed'   => $localInstalled,
            'local_database_available'   => $localUsable,
            'client_ip_public'           => $clientIpPublic,
            'detection_ready'            => $trustedHeaderReady || $localUsable,
            'resolution_source'          => $source,
            'blocking_issue'             => $issue,
            'detected_country'           => $country,
            'detected_currency'          => $country ? ($this->currencyForCountry($country) ?: self::DEFAULT_CURRENCY) : self::DEFAULT_CURRENCY,
            'mapped_countries'           => count((array) config('currency.country_currency', [])),
        ];
    }

    public function countryCode(Request $request): ?string
    {
        if ((bool) config('currency.trust_cdn_country_headers', false)) {
            $cloudflare = new CloudflareIpRangeService;
            if ($cloudflare->isCloudflareRequest($request)) {
                $country = $this->normalizeCountry($request->header('CF-IPCountry'));
                if ($country) {
                    return $country;
                }
            }
        }

        $ip = getClientIp($request);

        return $this->isPublicIp($ip) ? $this->localDatabaseCountry($ip) : null;
    }

    private function ipFingerprint(Request $request): ?string
    {
        $ip = getClientIp($request);
        if (! $this->isPublicIp($ip)) {
            return null;
        }

        return hash('sha256', $ip);
    }

    public function currencyForCountry(?string $country): string
    {
        return strtoupper((string) (config('currency.country_currency', [])[$this->normalizeCountry($country) ?? ''] ?? ''));
    }

    private function currencyCode(string $currency, CurrencyService $rates): ?string
    {
        $currency = strtoupper(trim($currency));
        if (! CurrencyCatalog::isSupported($currency)) {
            return null;
        }

        return $rates->hasRate($currency) ? $currency : null;
    }

    private function localDatabaseCountry(string $ip): ?string
    {
        $path = $this->localDatabasePath();
        if (! @is_file($path)) {
            return null;
        }

        $cacheKey = 'currency:visitor-country:' . hash('sha256', $ip);

        try {
            $cached = Cache::get($cacheKey);
            if (is_string($cached)) {
                return $cached === self::EMPTY_COUNTRY ? null : $this->normalizeCountry($cached);
            }
        } catch (\Throwable) {
            // Continue with the local lookup if cache is unavailable.
        }

        $country = null;

        try {
            $reader  = new Reader($path);
            $country = $this->normalizeCountry($reader->country($ip)->country->isoCode);
            $reader->close();
        } catch (\Throwable) {
            $country = null;
        }

        try {
            Cache::put($cacheKey, $country ?? self::EMPTY_COUNTRY, $country ? now()->addDays(30) : now()->addHours(6));
        } catch (\Throwable) {
            // The current request still has its country result.
        }

        return $country;
    }

    private function localDatabaseUsable(): bool
    {
        $path = $this->localDatabasePath();
        if (! @is_readable($path)) {
            return false;
        }

        try {
            $reader = new Reader($path);
            $reader->close();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function normalizeCountry(?string $country): ?string
    {
        $country = strtoupper(trim((string) $country));

        // Cloudflare uses XX when no country can be determined. Reporting it
        // as a real country made the dashboard ranking misleading.
        return preg_match('/^[A-Z]{2}$/', $country) && $country !== 'XX' ? $country : null;
    }

    private function localDatabasePath(): string
    {
        $configured = trim((string) config('currency.geolite2_path', ''));

        return $configured !== '' ? $configured : storage_path('app/GeoLite2-Country.mmdb');
    }

    private function isPublicIp(?string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
