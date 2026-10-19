<?php

namespace Beike\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudflareIpRangeService
{
    public const REQUEST_ATTRIBUTE = 'longos.cloudflare.verified';

    private const IPV4_ENDPOINT = 'https://www.cloudflare.com/ips-v4';

    private const IPV6_ENDPOINT = 'https://www.cloudflare.com/ips-v6';

    private const FALLBACK_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** @return string[] */
    public function ranges(): array
    {
        $snapshot = $this->cachedSnapshot();
        if ($snapshot !== null) {
            return $snapshot['ranges'];
        }

        return self::bundledRanges();
    }

    /** @return array{fetched_at: string, ranges: string[]}|null */
    public function cachedSnapshot(): ?array
    {
        try {
            $snapshot  = json_decode(File::get($this->rangesPath()), true, 512, JSON_THROW_ON_ERROR);
            $fetchedAt = trim((string) ($snapshot['fetched_at'] ?? ''));
            $ranges    = $this->validRanges($snapshot['ranges'] ?? []);
            if ($fetchedAt !== '' && $ranges !== []) {
                return ['fetched_at' => $fetchedAt, 'ranges' => $ranges];
            }
        } catch (\Throwable) {
            // A missing or damaged cache must not prevent the bundled safe
            // fallback list from validating known Cloudflare connections.
        }

        return null;
    }

    /**
     * The same safe fallback set is embedded in Caddy's trust and strict-origin
     * snippets. Deployment tests assert that the three copies cannot drift.
     *
     * @return string[]
     */
    public static function bundledRanges(): array
    {
        return self::FALLBACK_RANGES;
    }

    public function isCloudflareRequest(Request $request): bool
    {
        if ($request->attributes->getBoolean(self::REQUEST_ATTRIBUTE)) {
            return true;
        }

        $remoteAddress = $request->server->get('REMOTE_ADDR');

        return is_string($remoteAddress) && $this->contains($remoteAddress);
    }

    public function visitorIp(Request $request): ?string
    {
        $ip = trim((string) $request->header('CF-Connecting-IP'));

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    public function refresh(): bool
    {
        $lock = null;

        try {
            $lock = Cache::lock('cloudflare:ip-ranges-refresh', 120);
            if (! $lock->get()) {
                return false;
            }
        } catch (\Throwable) {
            // The scheduled task also has an overlap lock. A cache driver
            // without lock support can still safely refresh this small file.
        }

        try {
            $ipv4 = $this->download(self::IPV4_ENDPOINT);
            $ipv6 = $this->download(self::IPV6_ENDPOINT);
            if ($ipv4 === [] || $ipv6 === []) {
                return false;
            }

            $ranges = $this->validRanges(array_merge($ipv4, $ipv6));
            if (count($ipv4) < 10 || count($ipv6) < 5 || $ranges === []) {
                return false;
            }

            File::ensureDirectoryExists(dirname($this->rangesPath()));
            $payload = json_encode([
                'fetched_at' => now()->toIso8601String(),
                'ranges'     => $ranges,
            ], JSON_THROW_ON_ERROR);
            File::replace($this->rangesPath(), $payload);
            if (! is_file($this->rangesPath()) || File::get($this->rangesPath()) !== $payload) {
                throw new \RuntimeException('Unable to persist Cloudflare IP ranges.');
            }

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Cloudflare IP range refresh failed.', ['exception' => $exception::class]);

            return false;
        } finally {
            if ($lock) {
                try {
                    $lock->release();
                } catch (\Throwable) {
                    // The lock TTL is the final guard.
                }
            }
        }
    }

    public function contains(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach ($this->ranges() as $range) {
            if ($this->ipMatchesCidr($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    private function download(string $endpoint): array
    {
        $response = Http::accept('text/plain')
            ->connectTimeout(3)
            ->timeout(10)
            ->retry(1, 200, throw: false)
            ->get($endpoint);

        return $response->successful()
            ? $this->validRanges(preg_split('/\R/', $response->body()) ?: [])
            : [];
    }

    /** @param mixed $ranges @return string[] */
    private function validRanges(mixed $ranges): array
    {
        if (! is_array($ranges)) {
            return [];
        }

        $valid = [];
        foreach ($ranges as $range) {
            $range = trim((string) $range);
            if ($this->isValidCidr($range)) {
                $valid[] = $range;
            }
        }

        return array_values(array_unique($valid));
    }

    private function isValidCidr(string $cidr): bool
    {
        [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        if (! is_string($network) || ! is_string($prefix) || ! ctype_digit($prefix) || ! filter_var($network, FILTER_VALIDATE_IP)) {
            return false;
        }

        $maximumPrefix = str_contains($network, ':') ? 128 : 32;

        return (int) $prefix >= 0 && (int) $prefix <= $maximumPrefix;
    }

    private function ipMatchesCidr(string $ip, string $cidr): bool
    {
        [$network, $prefix] = explode('/', $cidr, 2);
        $ipBytes            = inet_pton($ip);
        $networkBytes       = inet_pton($network);
        if ($ipBytes === false || $networkBytes === false || strlen($ipBytes) !== strlen($networkBytes)) {
            return false;
        }

        $prefix     = (int) $prefix;
        $wholeBytes = intdiv($prefix, 8);
        if ($wholeBytes > 0 && substr($ipBytes, 0, $wholeBytes) !== substr($networkBytes, 0, $wholeBytes)) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
    }

    private function rangesPath(): string
    {
        return (string) config('longos.cloudflare.ip_ranges_path', storage_path('app/cloudflare-ip-ranges.json'));
    }
}
