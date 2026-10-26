<?php

namespace Beike\Services;

use Beike\Models\ProductView;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class StorefrontVisitService
{
    public const PENDING_SESSION_KEY = 'longos.dashboard.pending_visit';

    private const ATTRIBUTION_SESSION_KEY = 'longos.dashboard.attribution';

    private const PENDING_TTL_SECONDS = 1800;

    /** @var list<string> */
    private const HUMAN_ACTIVITIES = ['pointer', 'keyboard', 'touch', 'scroll'];

    /** @var array<string, bool> */
    private static array $tableAvailability = [];

    /** @var array<string, bool> */
    private static array $confirmationColumnsAvailability = [];

    public static function visitorKeyForSession(?string $sessionId): ?string
    {
        $sessionId = trim((string) $sessionId);
        if ($sessionId === '') {
            return null;
        }

        $secret = (string) config('app.key', '');

        return $secret !== ''
            ? hash_hmac('sha256', $sessionId, $secret)
            : hash('sha256', $sessionId);
    }

    public function stage(Request $request, mixed $response): void
    {
        if (! $this->shouldStage($request, $response)) {
            return;
        }

        $attribution = $request->session()->get(self::ATTRIBUTION_SESSION_KEY);
        if (! is_array($attribution) || ! isset($attribution['key'])) {
            $attribution = (new TrafficSourceService)->resolve($request);
            $request->session()->put(self::ATTRIBUTION_SESSION_KEY, $attribution);
        }

        $request->session()->put(self::PENDING_SESSION_KEY, [
            'attribution'    => $attribution,
            'landing_path'   => $this->text('/' . ltrim($request->path(), '/'), 1000),
            'product_id'     => $this->productId($request),
            'staged_at'      => now()->getTimestamp(),
            'user_agent_key' => hash('sha256', (string) $request->userAgent()),
        ]);
    }

    public function confirm(Request $request, string $activity): bool
    {
        if (! in_array($activity, self::HUMAN_ACTIVITIES, true)
            || ! $request->hasSession()
            || ! $this->isPlausibleBrowser($request)
            || ! $this->tableAvailable()) {
            return false;
        }

        $pending = $request->session()->pull(self::PENDING_SESSION_KEY);
        if (! is_array($pending)) {
            return false;
        }

        $stagedAt = filter_var($pending['staged_at'] ?? null, FILTER_VALIDATE_INT);
        if ($stagedAt === false
            || $stagedAt > now()->getTimestamp() + 60
            || $stagedAt < now()->getTimestamp() - self::PENDING_TTL_SECONDS) {
            return false;
        }

        $expectedAgent = $pending['user_agent_key'] ?? null;
        $actualAgent   = hash('sha256', (string) $request->userAgent());
        if (! is_string($expectedAgent) || ! hash_equals($expectedAgent, $actualAgent)) {
            return false;
        }

        $this->record(
            $request,
            is_array($pending['attribution'] ?? null) ? $pending['attribution'] : [],
            $this->text($pending['landing_path'] ?? null, 1000),
            $activity,
            $this->positiveInteger($pending['product_id'] ?? null),
        );

        return true;
    }

    public function reportFailure(Request $request, \Throwable $exception): void
    {
        $connection = DB::connection();
        $scope      = implode('|', [
            $connection->getName(),
            (string) $connection->getDatabaseName(),
            (string) $connection->getTablePrefix(),
            $exception::class,
            (string) $exception->getCode(),
        ]);
        $shouldLog = true;

        try {
            $shouldLog = Cache::add(
                'storefront-analytics:failure:' . hash('sha256', $scope),
                true,
                now()->addMinutes(5)
            );
        } catch (\Throwable) {
            // Logging must still work when the selected cache store failed.
        }

        if (! $shouldLog) {
            return;
        }

        Log::warning('Storefront analytics visit was not recorded', [
            'connection' => $connection->getName(),
            'database'   => (string) $connection->getDatabaseName(),
            'host'       => $request->getHost(),
            'exception'  => $exception::class,
            'code'       => (string) $exception->getCode(),
        ]);
    }

    private function shouldStage(Request $request, mixed $response): bool
    {
        if (! $request->isMethod('GET')
            || ! $response instanceof Response
            || ! $response->isSuccessful()
            || ! $request->hasSession()
            || ! $this->isPlausibleBrowser($request)) {
            return false;
        }

        $contentType = strtolower((string) $response->headers->get('content-type', ''));
        if (! str_contains($contentType, 'text/html')) {
            return false;
        }

        if ($request->headers->has('Purpose') || $request->headers->has('Sec-Purpose')) {
            $purpose = strtolower((string) ($request->header('Purpose') ?: $request->header('Sec-Purpose')));
            if (str_contains($purpose, 'prefetch') || str_contains($purpose, 'prerender')) {
                return false;
            }
        }

        return $this->tableAvailable();
    }

    private function isPlausibleBrowser(Request $request): bool
    {
        $agent = trim((string) $request->userAgent());
        if ($agent === '' || ! str_contains(strtolower($agent), 'mozilla/')) {
            return false;
        }

        return preg_match(
            '/(?:bot|crawl|spider|slurp|preview|headless|lighthouse|pagespeed|monitor|uptime|pingdom|statuscake|zgrab|masscan|nmap|censys|shodan|semrush|ahrefs|bytespider|petalbot|httpclient|curl|wget|python|aiohttp|okhttp|node-fetch|undici|facebookexternalhit|whatsapp|discordbot|telegrambot)/i',
            $agent,
        ) !== 1;
    }

    private function tableAvailable(): bool
    {
        $connection = DB::connection();
        $scope      = implode('|', [
            $connection->getName(),
            (string) $connection->getDatabaseName(),
            (string) $connection->getTablePrefix(),
        ]);

        if (! array_key_exists($scope, self::$tableAvailability)) {
            self::$tableAvailability[$scope] = Schema::hasTable('storefront_visits');
        }

        return self::$tableAvailability[$scope];
    }

    /** @param array<string, mixed> $attribution */
    private function record(
        Request $request,
        array $attribution,
        ?string $landingPath,
        string $activity,
        ?int $productId,
    ): void {
        $sessionId = $request->session()->getId();
        if ($sessionId === '') {
            return;
        }

        $visitorKey = self::visitorKeyForSession($sessionId);
        if ($visitorKey === null) {
            return;
        }
        $now      = now();
        $bucketAt = $now->copy()->startOfHour();

        $countryCode = (new VisitorCurrencyService)->countryCode($request);
        $customerId  = current_customer()?->getKey();
        $updates     = [
            'customer_id'  => $customerId ?: null,
            'last_seen_at' => $now,
            'updated_at'   => $now,
            'page_views'   => DB::raw('page_views + 1'),
        ];
        if ($this->confirmationColumnsAvailable()) {
            $updates['human_confirmed_at']  = $now;
            $updates['confirmation_method'] = $activity;
        }
        if ($countryCode !== null) {
            $updates['country_code'] = $countryCode;
        }

        $updated = DB::table('storefront_visits')
            ->where('bucket_at', $bucketAt)
            ->where('visitor_key', $visitorKey)
            ->update($updates);

        if ($updated === 0) {
            $payload = [
                'bucket_at'     => $bucketAt,
                'visitor_key'   => $visitorKey,
                'customer_id'   => $customerId ?: null,
                'country_code'  => $countryCode,
                'source_key'    => $this->sourceKey($attribution['key'] ?? null),
                'source_detail' => $this->text($attribution['detail'] ?? null, 190),
                'referer'       => $this->text($attribution['referer'] ?? null, 1000),
                'landing_path'  => $landingPath,
                'page_views'    => 1,
                'first_seen_at' => $now,
                'last_seen_at'  => $now,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
            if ($this->confirmationColumnsAvailable()) {
                $payload['human_confirmed_at']  = $now;
                $payload['confirmation_method'] = $activity;
            }

            try {
                DB::table('storefront_visits')->insert($payload);
            } catch (QueryException $exception) {
                // Concurrent tabs may confirm the same hourly bucket.
                $retry = DB::table('storefront_visits')
                    ->where('bucket_at', $bucketAt)
                    ->where('visitor_key', $visitorKey)
                    ->update($updates);
                if ($retry === 0) {
                    throw $exception;
                }
            }
        }

        $this->recordProductView(
            $request,
            $productId,
            $visitorKey,
            $countryCode,
            $customerId ? (int) $customerId : null,
            $this->text($attribution['referer'] ?? null, 1000),
            $activity,
            $now,
        );
    }

    private function recordProductView(
        Request $request,
        ?int $productId,
        string $visitorKey,
        ?string $countryCode,
        ?int $customerId,
        ?string $referer,
        string $activity,
        \DateTimeInterface $confirmedAt,
    ): void {
        if ($productId === null || ! ProductView::supportsHumanConfirmation()) {
            return;
        }

        $minutes = max(1, (int) system_setting('base.product_view_minutes', 1));
        $exists  = ProductView::humanConfirmed()
            ->where('product_id', $productId)
            ->where('visitor_key', $visitorKey)
            ->where('created_at', '>', now()->subMinutes($minutes))
            ->exists();

        if ($exists) {
            return;
        }

        ProductView::query()->create([
            'product_id'          => $productId,
            'customer_id'         => $customerId,
            'ip'                  => $this->text($request->getClientIp(), 255),
            'session_id'          => $this->text($request->session()->getId(), 255),
            'visitor_key'         => $visitorKey,
            'referer'             => $referer,
            'user_agent'          => $this->text($request->userAgent(), 255),
            'country_code'        => $countryCode,
            'human_confirmed_at'  => $confirmedAt,
            'confirmation_method' => $activity,
        ]);
    }

    private function productId(Request $request): ?int
    {
        if (! $request->routeIs('shop.products.show')) {
            return null;
        }

        $product = $request->route('product');
        if (is_object($product) && method_exists($product, 'getKey')) {
            $product = $product->getKey();
        }

        return $this->positiveInteger($product);
    }

    private function positiveInteger(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $value === false ? null : $value;
    }

    private function confirmationColumnsAvailable(): bool
    {
        $connection = DB::connection();
        $scope      = implode('|', [
            $connection->getName(),
            (string) $connection->getDatabaseName(),
            (string) $connection->getTablePrefix(),
        ]);

        if (! array_key_exists($scope, self::$confirmationColumnsAvailability)) {
            self::$confirmationColumnsAvailability[$scope] = Schema::hasColumn('storefront_visits', 'human_confirmed_at')
                && Schema::hasColumn('storefront_visits', 'confirmation_method');
        }

        return self::$confirmationColumnsAvailability[$scope];
    }

    private function sourceKey(mixed $source): string
    {
        $source = strtolower(trim((string) $source));
        $valid  = [
            'direct', 'google', 'baidu', 'bing', 'yahoo', 'duckduckgo', 'yandex',
            'facebook', 'instagram', 'twitter', 'youtube', 'linkedin', 'tiktok',
            'pinterest', 'reddit', 'email', 'affiliate', 'other',
        ];

        return in_array($source, $valid, true) ? $source : 'other';
    }

    private function text(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
