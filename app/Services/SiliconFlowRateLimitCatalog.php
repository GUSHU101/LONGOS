<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SiliconFlowRateLimitCatalog
{
    private const MARKETPLACE_URL = 'https://cloud.siliconflow.cn/open/models?types=chat';

    private const CACHE_HOURS = 24;

    private const REFRESH_COOLDOWN_MINUTES = 15;

    private const FALLBACK_RPM = 60;

    private const FALLBACK_TPM = 20_000;

    private bool $marketplaceLoaded = false;

    private ?string $marketplaceHtml = null;

    /**
     * Last-known official marketplace values. The public marketplace is still
     * queried and cached, so model changes do not require a deployment. These
     * values keep rate protection available during a marketplace outage.
     *
     * @var array<string, array<int, array{rpm:int,tpm:int}>>
     */
    private const BUNDLED = [
        'tencent/Hunyuan-MT-7B' => [
            0 => ['rpm' => 1000, 'tpm' => 80_000],
            1 => ['rpm' => 1200, 'tpm' => 120_000],
            2 => ['rpm' => 2000, 'tpm' => 160_000],
            3 => ['rpm' => 4000, 'tpm' => 320_000],
            4 => ['rpm' => 8000, 'tpm' => 1_000_000],
            5 => ['rpm' => 10_000, 'tpm' => 5_000_000],
        ],
        'XingChenAGI/Xing4.0-29B' => [
            0 => ['rpm' => 1000, 'tpm' => 40_000],
            1 => ['rpm' => 1200, 'tpm' => 60_000],
            2 => ['rpm' => 2000, 'tpm' => 80_000],
            3 => ['rpm' => 4000, 'tpm' => 160_000],
            4 => ['rpm' => 8000, 'tpm' => 500_000],
            5 => ['rpm' => 10_000, 'tpm' => 2_000_000],
        ],
    ];

    /** @return array{model:string,level:int,rpm:int,tpm:int,source:string} */
    public function profile(string $model, int $level = 0, bool $refresh = false): array
    {
        $model = trim($model);
        $level = min(5, max(0, $level));
        $key   = 'longos:siliconflow:rate-profile:' . hash('sha256', $model . "\0" . $level);

        $cached = Cache::get($key);
        if (! $refresh) {
            if ($this->validProfile($cached, $model, $level)) {
                return $cached;
            }
        }

        $refreshAllowed = $refresh && $model !== ''
                                   && Cache::add($key . ':refresh-gate', true, now()->addMinutes(self::REFRESH_COOLDOWN_MINUTES));
        if ($refreshAllowed) {
            $html = $this->marketplaceHtml();
            if ($html !== null) {
                $discovered = self::parseModelProfile($html, $model, $level);
                if ($discovered !== null) {
                    $profile = ['model' => $model, 'level' => $level, ...$discovered, 'source' => 'marketplace'];
                    Cache::put($key, $profile, now()->addHours(self::CACHE_HOURS));

                    return $profile;
                }
            }
        }

        if ($this->validProfile($cached, $model, $level)) {
            return [...$cached, 'source' => $cached['source'] === 'marketplace' ? 'marketplace_stale' : $cached['source']];
        }

        $bundled = self::BUNDLED[$model][$level] ?? null;
        $profile = [
            'model'  => $model,
            'level'  => $level,
            'rpm'    => (int) ($bundled['rpm'] ?? self::FALLBACK_RPM),
            'tpm'    => (int) ($bundled['tpm'] ?? self::FALLBACK_TPM),
            'source' => $bundled ? 'bundled' : 'fallback',
        ];
        Cache::put($key, $profile, now()->addHours(self::CACHE_HOURS));

        return $profile;
    }

    /** @return array<string, array{rpm:int,tpm:int}> */
    public static function bundledLevelProfiles(int $level = 0): array
    {
        $level = min(5, max(0, $level));

        return array_map(
            static fn (array $levels): array => $levels[$level],
            self::BUNDLED,
        );
    }

    /** @return array{rpm:int,tpm:int}|null */
    public static function parseModelProfile(string $html, string $model, int $level = 0): ?array
    {
        $level = min(5, max(0, $level));
        foreach ([
            ['needle' => '\\"modelName\\":\\"' . $model . '\\"', 'marker' => '\\"modelInfo\\":[', 'escaped' => true],
            ['needle' => '"modelName":"' . $model . '"', 'marker' => '"modelInfo":[', 'escaped' => false],
        ] as $format) {
            $modelAt = strpos($html, $format['needle']);
            if ($modelAt === false) {
                continue;
            }
            $listAt = strpos($html, $format['marker'], $modelAt);
            if ($listAt === false) {
                continue;
            }
            $start = $listAt + strlen($format['marker']);
            $end   = strpos($html, ']', $start);
            if ($end === false || $end - $start > 10_000) {
                continue;
            }
            $json = substr($html, $start, $end - $start);
            if ($format['escaped']) {
                $json = str_replace('\\"', '"', $json);
            }
            $rows = json_decode('[' . $json . ']', true);
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (! is_array($row) || (int) ($row['Level'] ?? -1) !== $level) {
                    continue;
                }
                $rpm = (int) ($row['RPM'] ?? 0);
                $tpm = (int) ($row['TPM'] ?? 0);
                if ($rpm >= 1 && $rpm <= 100_000 && $tpm >= 1 && $tpm <= 100_000_000) {
                    return ['rpm' => $rpm, 'tpm' => $tpm];
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    public static function rateLimitCode(array $payload): string
    {
        $message = strtolower((string) ($payload['message'] ?? data_get($payload, 'error.message', '')));

        return match (true) {
            str_contains($message, 'tpm') => 'RATE_LIMIT_TPM',
            str_contains($message, 'rpm') => 'RATE_LIMIT_RPM',
            str_contains($message, 'tpd') => 'RATE_LIMIT_TPD',
            str_contains($message, 'rpd') => 'RATE_LIMIT_RPD',
            str_contains($message, 'rph') => 'RATE_LIMIT_RPH',
            default                       => 'RATE_LIMIT',
        };
    }

    private function validProfile(mixed $profile, string $model, int $level): bool
    {
        return is_array($profile)
            && ($profile['model'] ?? null)     === $model
            && (int) ($profile['level'] ?? -1) === $level
            && (int) ($profile['rpm'] ?? 0) > 0
            && (int) ($profile['tpm'] ?? 0) > 0;
    }

    private function marketplaceHtml(): ?string
    {
        if ($this->marketplaceLoaded) {
            return $this->marketplaceHtml;
        }
        $this->marketplaceLoaded = true;

        try {
            $response = Http::accept('text/html')
                ->connectTimeout(5)
                ->timeout(15)
                ->get(self::MARKETPLACE_URL);
            if ($response->successful() && strlen($response->body()) <= 3_000_000) {
                $this->marketplaceHtml = $response->body();
            }
        } catch (Throwable) {
            // A stale or bundled profile is safer than disabling outbound
            // work merely because the public marketplace is unavailable.
        }

        return $this->marketplaceHtml;
    }
}
