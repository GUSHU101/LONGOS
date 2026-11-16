<?php

namespace Plugin\LongosBotShield\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Plugin\LongosBotShield\Support\CidrMatcher;
use Plugin\LongosBotShield\Support\IpAddress;
use Throwable;

class GoogleCrawlerRanges
{
    private const CACHE_KEY = 'longos:bot-shield:google-crawler-ranges:v1';

    private const FAILURE_KEY = self::CACHE_KEY . ':failure';

    private const URLS = [
        'common'      => 'https://developers.google.com/static/crawling/ipranges/common-crawlers.json',
        'special'     => 'https://developers.google.com/static/crawling/ipranges/special-crawlers.json',
        'user'        => 'https://developers.google.com/static/crawling/ipranges/user-triggered-fetchers.json',
        'user_google' => 'https://developers.google.com/static/crawling/ipranges/user-triggered-fetchers-google.json',
        'agents'      => 'https://developers.google.com/static/crawling/ipranges/user-triggered-agents.json',
    ];

    public function contains(string $ip): bool
    {
        $ip = IpAddress::normalize($ip);
        if ($ip === '') {
            return false;
        }

        return CidrMatcher::contains($ip, $this->ranges());
    }

    /** @return list<string> */
    private function ranges(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $stale = Cache::get(self::CACHE_KEY . ':stale');
        if (Cache::has(self::FAILURE_KEY)) {
            return is_array($stale) ? $stale : [];
        }
        if (! Cache::add(self::CACHE_KEY . ':refreshing', true, now()->addSeconds(10))) {
            return is_array($stale) ? $stale : [];
        }

        try {
            $responses = Http::pool(static function (Pool $pool): array {
                $requests = [];
                foreach (self::URLS as $name => $url) {
                    $requests[] = $pool->as($name)
                        ->acceptJson()
                        ->connectTimeout(1.0)
                        ->timeout(2.5)
                        ->get($url);
                }

                return $requests;
            });

            $ranges = [];
            foreach (array_keys(self::URLS) as $name) {
                $response = $responses[$name] ?? null;
                if ($response === null || ! $response->successful() || strlen((string) $response->body()) > 2 * 1024 * 1024) {
                    return $this->refreshFailed($stale);
                }

                $payload = $response->json();
                if (! is_array($payload) || ! is_array($payload['prefixes'] ?? null)) {
                    return $this->refreshFailed($stale);
                }
                foreach ($payload['prefixes'] as $prefix) {
                    if (! is_array($prefix)) {
                        continue;
                    }
                    $value = trim((string) ($prefix['ipv4Prefix'] ?? $prefix['ipv6Prefix'] ?? ''));
                    if ($value !== '') {
                        $ranges[] = $value;
                    }
                    if (count($ranges) > 20000) {
                        return $this->refreshFailed($stale);
                    }
                }
            }

            $ranges = array_values(array_unique($ranges));
            if ($ranges === []) {
                return $this->refreshFailed($stale);
            }

            Cache::forget(self::FAILURE_KEY);
            Cache::put(self::CACHE_KEY, $ranges, now()->addDay());
            Cache::put(self::CACHE_KEY . ':stale', $ranges, now()->addDays(7));

            return $ranges;
        } catch (Throwable) {
            return $this->refreshFailed($stale);
        } finally {
            Cache::forget(self::CACHE_KEY . ':refreshing');
        }
    }

    /** @return list<string> */
    private function refreshFailed(mixed $stale): array
    {
        Cache::put(self::FAILURE_KEY, true, now()->addMinutes(10));

        return is_array($stale) ? $stale : [];
    }
}
