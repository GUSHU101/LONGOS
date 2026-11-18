<?php

namespace Plugin\LongosBotShield\Services;

use App\Saas\Support\TenantDeferred;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MaxMind\Db\Reader;
use Plugin\LongosBotShield\Support\IpAddress;
use Throwable;

class IpInfoLocalDatabase
{
    private const DOWNLOAD_URL = 'https://ipinfo.io/data/ipinfo_lite.mmdb';

    private const REFRESH_SECONDS = 86400;

    private const MINIMUM_BYTES = 1048576;

    private const MAXIMUM_BYTES = 83886080;

    private const SYNC_LOCK_KEY = 'longos:bot-shield:ipinfo-local-database-sync';

    private const ABANDONED_TEMPORARY_SECONDS = 3600;

    private ?Reader $reader = null;

    public function __destruct()
    {
        $this->closeReader();
    }

    /** @return array<string, bool|string> */
    public function lookup(string $ip): array
    {
        $ip = IpAddress::normalize($ip);
        if (! IpAddress::isPublic($ip)) {
            return $this->emptyResult('local');
        }

        $path = $this->databasePath();
        if (! $this->validFileSize($path)) {
            return $this->emptyResult('unavailable');
        }

        try {
            $record = $this->reader($path)->get($ip);
            if (! is_array($record)) {
                return $this->emptyResult('unavailable');
            }

            $asn = strtoupper(trim((string) ($record['asn'] ?? '')));
            if (preg_match('/^AS[1-9]\d{0,9}$/D', $asn) !== 1) {
                return $this->emptyResult('unavailable');
            }
            $name     = trim((string) ($record['as_name'] ?? $record['name'] ?? ''));
            $domain   = trim((string) ($record['as_domain'] ?? $record['domain'] ?? ''));
            $location = $this->normalizeLocation($record);

            return [
                'status'                => 'ok',
                'provider'              => 'ipinfo-local',
                // Country is part of the enforcement identity. If a damaged
                // or older record lacks it, let NetworkIntelligenceClient use
                // the bounded live API fallback instead of silently allowing
                // a country policy match to disappear.
                'identity_incomplete'   => ($name === '' && $domain === '') || $location['country_code'] === '',
                'security_data_present' => false,
                'asn'                   => $asn,
                'name'                  => $name,
                'domain'                => $domain,
                'country_code'          => $location['country_code'],
                'country_name'          => $location['country_name'],
                'continent_code'        => $location['continent_code'],
                'continent_name'        => $location['continent_name'],
                'type'                  => '',
                'company_name'          => '',
                'company_domain'        => '',
                'company_type'          => '',
                'privacy_name'          => '',
                'is_hosting'            => false,
                'is_anonymous'          => false,
                'is_proxy'              => false,
                'is_res_proxy'          => false,
                'is_vpn'                => false,
                'is_tor'                => false,
                'is_relay'              => false,
                'is_anycast'            => false,
                'is_bogon'              => false,
            ];
        } catch (Throwable) {
            $this->closeReader();

            return $this->emptyResult('unavailable');
        }
    }

    /** @return array{ready: bool, status: string, age_hours?: int, size_mb?: float} */
    public function status(): array
    {
        $path = $this->databasePath();
        if (! $this->validFileSize($path)) {
            return ['ready' => false, 'status' => 'missing'];
        }

        $modified = @filemtime($path);

        return [
            'ready'     => true,
            'status'    => $this->needsRefresh() ? 'stale' : 'fresh',
            'age_hours' => $modified === false ? 0 : max(0, (int) floor((time() - $modified) / 3600)),
            'size_mb'   => round(((int) filesize($path)) / 1048576, 2),
        ];
    }

    /** @return array{ready: bool, status: string, age_hours?: int, size_mb?: float} */
    public function syncIfStale(string $token): array
    {
        if (! $this->needsRefresh()) {
            return $this->status();
        }

        return $this->sync($token);
    }

    public function scheduleSyncIfStale(string $token): void
    {
        if (! $this->validToken($token) || ! $this->needsRefresh()) {
            return;
        }
        if (! Cache::add(self::SYNC_LOCK_KEY, true, now()->addMinutes(10))) {
            return;
        }

        TenantDeferred::terminating(static function () use ($token): void {
            try {
                app(self::class)->sync($token);
            } finally {
                Cache::forget(self::SYNC_LOCK_KEY);
            }
        });
    }

    /** @return array{ready: bool, status: string, age_hours?: int, size_mb?: float} */
    public function sync(string $token): array
    {
        if (! $this->validToken($token)) {
            return ['ready' => false, 'status' => 'invalid_token'];
        }

        $directory = dirname($this->databasePath());
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return $this->fallbackStatus('storage_unavailable');
        }

        $lock = @fopen($directory . DIRECTORY_SEPARATOR . 'ipinfo_lite.lock', 'c');
        if ($lock === false || ! @flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                @fclose($lock);
            }

            return $this->fallbackStatus('syncing');
        }

        $this->pruneAbandonedTemporaryFiles($directory);
        $temporary = $directory . DIRECTORY_SEPARATOR . 'ipinfo_lite.' . bin2hex(random_bytes(8)) . '.tmp';

        try {
            $response = Http::accept('application/octet-stream')
                ->connectTimeout(5)
                ->timeout(120)
                ->withOptions([
                    'sink'            => $temporary,
                    'allow_redirects' => [
                        'max'       => 3,
                        'strict'    => true,
                        'referer'   => false,
                        'protocols' => ['https'],
                    ],
                ])
                ->get(self::DOWNLOAD_URL, ['token' => $token]);
            if (! $response->successful() || ! $this->validFileSize($temporary)) {
                return $this->fallbackStatus('download_failed');
            }

            $reader = $this->newReader($temporary);

            try {
                $sample = $reader->get('8.8.8.8');
                if (! is_array($sample)
                    || trim((string) ($sample['asn'] ?? ''))             === ''
                    || $this->normalizeLocation($sample)['country_code'] === '') {
                    return $this->fallbackStatus('invalid_database');
                }
            } finally {
                $reader->close();
            }

            $this->closeReader();
            // On the Linux production image this atomic rename replaces and
            // unlinks the previous MMDB. Only the validated new file remains;
            // there is no accumulating backup generation.
            if (! @rename($temporary, $this->databasePath())) {
                return $this->fallbackStatus('storage_unavailable');
            }

            clearstatcache(true, $this->databasePath());
            $status           = $this->status();
            $status['status'] = 'downloaded';

            return $status;
        } catch (Throwable) {
            return $this->fallbackStatus('download_failed');
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    public function needsRefresh(): bool
    {
        $path = $this->databasePath();
        if (! $this->validFileSize($path)) {
            return true;
        }

        $modified = @filemtime($path);

        return $modified === false || $modified < time() - self::REFRESH_SECONDS;
    }

    protected function databasePath(): string
    {
        return storage_path('app/private/longos-bot-shield/ipinfo_lite.mmdb');
    }

    protected function newReader(string $path): Reader
    {
        return new Reader($path);
    }

    private function reader(string $path): Reader
    {
        return $this->reader ??= $this->newReader($path);
    }

    private function closeReader(): void
    {
        if ($this->reader !== null) {
            $this->reader->close();
            $this->reader = null;
        }
    }

    private function validToken(string $token): bool
    {
        return preg_match('/^[\x21-\x7E]{6,2048}$/D', trim($token)) === 1;
    }

    private function validFileSize(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }
        $size = @filesize($path);

        return is_int($size) && $size >= self::MINIMUM_BYTES && $size <= self::MAXIMUM_BYTES;
    }

    /**
     * Normalize both the current IPinfo Lite schema and the former
     * country_asn field names so an already-downloaded compatible database
     * does not lose country enforcement during an upgrade.
     *
     * @return array{country_code: string, country_name: string, continent_code: string, continent_name: string}
     */
    private function normalizeLocation(array $record): array
    {
        $currentCountryCode = $this->twoLetterCode($record['country_code'] ?? '');
        $countryValue       = trim((string) ($record['country'] ?? ''));
        $legacyCountryCode  = $this->twoLetterCode($countryValue);
        $countryCode        = $currentCountryCode !== '' ? $currentCountryCode : $legacyCountryCode;
        $legacyCountryName  = trim((string) ($record['country_name'] ?? ''));
        $countryName        = $currentCountryCode !== ''
            ? ($countryValue !== '' ? $countryValue : $legacyCountryName)
            : ($legacyCountryCode !== '' ? $legacyCountryName : ($legacyCountryName !== '' ? $legacyCountryName : $countryValue));

        $currentContinentCode = $this->twoLetterCode($record['continent_code'] ?? '');
        $continentValue       = trim((string) ($record['continent'] ?? ''));
        $legacyContinentCode  = $this->twoLetterCode($continentValue);
        $continentCode        = $currentContinentCode !== '' ? $currentContinentCode : $legacyContinentCode;
        $legacyContinentName  = trim((string) ($record['continent_name'] ?? ''));
        $continentName        = $currentContinentCode !== ''
            ? ($continentValue !== '' ? $continentValue : $legacyContinentName)
            : ($legacyContinentCode !== '' ? $legacyContinentName : ($legacyContinentName !== '' ? $legacyContinentName : $continentValue));

        return [
            'country_code'   => $countryCode,
            'country_name'   => $countryName,
            'continent_code' => $continentCode,
            'continent_name' => $continentName,
        ];
    }

    private function twoLetterCode(mixed $value): string
    {
        $value = strtoupper(trim((string) $value));

        return preg_match('/^[A-Z]{2}$/D', $value) === 1 ? $value : '';
    }

    private function pruneAbandonedTemporaryFiles(string $directory): void
    {
        $cutoff = time() - self::ABANDONED_TEMPORARY_SECONDS;
        foreach (glob($directory . DIRECTORY_SEPARATOR . 'ipinfo_lite.*.tmp', GLOB_NOSORT) ?: [] as $path) {
            $modified = @filemtime($path);
            if (is_file($path) && is_int($modified) && $modified < $cutoff) {
                @unlink($path);
            }
        }
    }

    /** @return array{ready: bool, status: string, age_hours?: int, size_mb?: float} */
    private function fallbackStatus(string $failure): array
    {
        $status = $this->status();
        if ($status['ready']) {
            $status['status'] = 'stale';

            return $status;
        }

        return ['ready' => false, 'status' => $failure];
    }

    /** @return array<string, bool|string> */
    private function emptyResult(string $status): array
    {
        return [
            'status'                => $status,
            'provider'              => 'ipinfo-local',
            'security_data_present' => false,
            'asn'                   => '',
            'name'                  => '',
            'domain'                => '',
            'country_code'          => '',
            'country_name'          => '',
            'continent_code'        => '',
            'continent_name'        => '',
            'type'                  => '',
            'company_name'          => '',
            'company_domain'        => '',
            'company_type'          => '',
            'privacy_name'          => '',
            'is_hosting'            => false,
            'is_anonymous'          => false,
            'is_proxy'              => false,
            'is_res_proxy'          => false,
            'is_vpn'                => false,
            'is_tor'                => false,
            'is_relay'              => false,
            'is_anycast'            => false,
            'is_bogon'              => false,
        ];
    }
}
