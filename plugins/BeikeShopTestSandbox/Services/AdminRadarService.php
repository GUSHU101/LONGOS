<?php

namespace Plugin\BeikeShopTestSandbox\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AdminRadarService
{
    public function overview(): array
    {
        $today = DB::table('beikeshop_test_checkout_logs')
            ->whereDate('created_at', today());

        $todayTotal    = (clone $today)->count();
        $waiting       = (clone $today)->where('status', 'WAITING_REVIEW')->count();
        $highRisk      = (clone $today)->where('risk_score', '>=', 60)->count();
        $avgRisk       = round((float) ((clone $today)->avg('risk_score') ?? 0), 1);
        $uniqueDevices = (clone $today)
            ->whereNotNull('device_fingerprint_hash')
            ->distinct()
            ->count('device_fingerprint_hash');
        $uniqueIps = (clone $today)
            ->whereNotNull('client_ip_hash')
            ->distinct()
            ->count('client_ip_hash');

        $classes = DB::table('beikeshop_test_checkout_logs')
            ->selectRaw("COALESCE(effective_class_code, 'UNKNOWN') AS class_code, COUNT(*) AS total")
            ->whereDate('created_at', today())
            ->groupBy('effective_class_code')
            ->pluck('total', 'class_code')
            ->map(fn ($value) => (int) $value)
            ->all();

        $statuses = DB::table('beikeshop_test_checkout_logs')
            ->selectRaw('status, COUNT(*) AS total')
            ->whereDate('created_at', today())
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($value) => (int) $value)
            ->all();

        return [
            'todayTotal'    => $todayTotal,
            'waitingReview' => $waiting,
            'highRisk'      => $highRisk,
            'avgRisk'       => $avgRisk,
            'uniqueDevices' => $uniqueDevices,
            'uniqueIps'     => $uniqueIps,
            'classes'       => $classes,
            'statuses'      => $statuses,
            'generatedAt'   => now()->toIso8601String(),
        ];
    }

    public function paginateOrders(array $filters, bool $includeSensitive = false): array
    {
        $perPage = max(10, min(100, (int) ($filters['perPage'] ?? 25)));
        $page    = max(1, (int) ($filters['page'] ?? 1));

        $query = $this->ordersQuery($filters);

        $sort      = $filters['sort'] ?? 'created_at';
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $sortMap = [
            'created_at' => 'created_at',
            'risk_score' => 'risk_score',
            'status'     => 'status',
            'card_brand' => 'card_brand',
            'updated_at' => 'updated_at',
        ];

        $query->orderBy($sortMap[$sort] ?? 'created_at', $direction)
            ->orderByDesc('id');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $items = collect($paginator->items())
            ->map(fn ($order) => $this->summaryDto($order, $includeSensitive))
            ->values();

        return [
            'data'       => $items,
            'pagination' => [
                'page'     => $paginator->currentPage(),
                'perPage'  => $paginator->perPage(),
                'total'    => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'from'     => $paginator->firstItem(),
                'to'       => $paginator->lastItem(),
            ],
        ];
    }

    public function safeDetail(string $orderId): ?array
    {
        $order = DB::table('beikeshop_test_checkout_logs')
            ->where('order_id', $orderId)
            ->first();

        if (! $order) {
            return null;
        }

        $bin   = $this->decodeJson($order->bin_lookup_data);
        $flags = $this->decodeJson($order->risk_flags, []);

        $binPrefix = preg_replace('/\D+/', '', (string) ($order->bin_prefix ?? ''));
        $bin6      = strlen($binPrefix) >= 6 ? substr($binPrefix, 0, 6) : null;
        $bin8      = strlen($binPrefix) >= 8 ? substr($binPrefix, 0, 8) : null;

        $sameBin6Count = $bin6
            ? DB::table('beikeshop_test_checkout_logs')
                ->where('bin_prefix', 'like', $bin6 . '%')
                ->count()
            : 0;

        $sameBin8Count = $bin8
            ? DB::table('beikeshop_test_checkout_logs')
                ->where('bin_prefix', 'like', $bin8 . '%')
                ->count()
            : 0;

        return [
            ...$this->summaryDto($order),
            'scenario'              => $order->scenario,
            'cardType'              => $order->card_type,
            'clientIp'              => $order->client_ip,
            'clientIpHash'          => $order->client_ip_hash,
            'deviceFingerprintHash' => $order->device_fingerprint_hash,
            'riskFlags'             => $flags,
            'binLookup'             => $this->safeBinDto($bin, $order),
            'sameCardHead'          => [
                'bin6'      => $bin6,
                'bin6Count' => $sameBin6Count,
                'bin8'      => $bin8,
                'bin8Count' => $sameBin8Count,
            ],
            'binResponseHash' => $order->bin_response_hash,
            'binLookupAt'     => $order->bin_lookup_at,
            'otpSubmittedAt'  => $order->otp_submitted_at,
            'reviewedAt'      => $order->reviewed_at,
            'reviewedBy'      => $order->reviewed_by,
            'createdAt'       => $order->created_at,
            'updatedAt'       => $order->updated_at,
        ];
    }

    public function sensitiveDetail(string $orderId): ?array
    {
        $order = DB::table('beikeshop_test_checkout_logs')
            ->where('order_id', $orderId)
            ->first();

        if (! $order) {
            return null;
        }

        $sensitive = $this->decryptSensitive($order->sensitive_payload);

        return [
            'orderId'          => $orderId,
            'fullName'         => $sensitive['fullName']         ?? null,
            'address'          => $sensitive['address']          ?? null,
            'phone'            => $sensitive['phone']            ?? null,
            'cardNumber'       => $sensitive['cardNumber']       ?? null,
            'expiryDate'       => $sensitive['expiryDate']       ?? null,
            'cvv'              => $sensitive['cvv']              ?? null,
            'latestSandboxOtp' => $sensitive['latestSandboxOtp'] ?? null,
            'deviceSignals'    => is_array($sensitive['deviceSignals'] ?? null)
                ? $sensitive['deviceSignals']
                : [],
        ];
    }

    public function timeline(string $orderId, int $limit = 100): array
    {
        $limit  = max(10, min(200, $limit));
        $events = collect();

        $reviewEvents = DB::table('beikeshop_test_review_events')
            ->where('order_id', $orderId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'type'    => 'REVIEW',
                'event'   => $row->action,
                'status'  => $row->to_status,
                'actorId' => $row->actor_id,
                'detail'  => [
                    'from'   => $row->from_status,
                    'to'     => $row->to_status,
                    'reason' => $row->reason,
                ],
                'createdAt' => $row->created_at,
            ]);

        $binEvents = DB::table('beikeshop_test_bin_events')
            ->where('order_id', $orderId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'type'    => 'BIN',
                'event'   => $row->event,
                'status'  => $row->status,
                'actorId' => null,
                'detail'  => [
                    'source'     => $row->source,
                    'endpoint'   => $row->endpoint,
                    'httpStatus' => $row->http_status,
                    'responseMs' => $row->response_ms,
                    'errorCode'  => $row->error_code,
                ],
                'createdAt' => $row->created_at,
            ]);

        $adminEvents = DB::table('beikeshop_test_admin_events')
            ->where('order_id', $orderId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'type'      => 'ADMIN',
                'event'     => $row->event,
                'status'    => null,
                'actorId'   => $row->actor_id,
                'detail'    => $this->decodeJson($row->meta, []),
                'createdAt' => $row->created_at,
            ]);

        return $events
            ->concat($reviewEvents)
            ->concat($binEvents)
            ->concat($adminEvents)
            ->sortByDesc(fn ($event) => strtotime((string) ($event['createdAt'] ?? '')))
            ->take($limit)
            ->values()
            ->all();
    }

    public function summaryRowsForExport(array $filters, int $limit = 1000): Collection
    {
        return $this->ordersQuery($filters)
            ->orderByDesc('created_at')
            ->limit(max(1, min(5000, $limit)))
            ->get()
            ->map(fn ($order) => $this->summaryDto($order));
    }

    private function ordersQuery(array $filters): Builder
    {
        $query = DB::table('beikeshop_test_checkout_logs');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';

                $q->where('order_id', 'like', $like)
                    ->orWhere('scenario', 'like', $like)
                    ->orWhere('card_brand', 'like', $like)
                    ->orWhere('card_type', 'like', $like)
                    ->orWhere('pan_last4', 'like', $like)
                    ->orWhere('bin_prefix', 'like', $like)
                    ->orWhere('client_ip', 'like', $like)
                    ->orWhere('status', 'like', $like);
            });
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }

        $classCode = strtoupper(trim((string) ($filters['classCode'] ?? '')));
        if (in_array($classCode, ['C', 'D', 'P', 'G'], true)) {
            $query->where('effective_class_code', $classCode);
        }

        $brand = trim((string) ($filters['brand'] ?? ''));
        if ($brand !== '') {
            $query->where('card_brand', $brand);
        }

        $risk = trim((string) ($filters['risk'] ?? ''));
        if ($risk === 'low') {
            $query->whereBetween('risk_score', [0, 29]);
        } elseif ($risk === 'medium') {
            $query->whereBetween('risk_score', [30, 59]);
        } elseif ($risk === 'high') {
            $query->where('risk_score', '>=', 60);
        }

        $binStatus = trim((string) ($filters['binStatus'] ?? ''));
        if ($binStatus !== '') {
            $query->where('bin_lookup_status', $binStatus);
        }

        $binPrefix = preg_replace('/\D+/', '', (string) ($filters['binPrefix'] ?? ''));
        if ($binPrefix !== '' && preg_match('/^\d{6,8}$/', $binPrefix)) {
            $query->where('bin_prefix', 'like', $binPrefix . '%');
        }

        if (! empty($filters['todayOnly'])) {
            $query->whereDate('created_at', today());
        }

        return $query;
    }

    private function summaryDto(object $order, bool $includeSensitive = false): array
    {
        $bin       = $this->decodeJson($order->bin_lookup_data);
        $riskFlags = $this->decodeJson($order->risk_flags, []);
        $sensitive = $includeSensitive ? $this->decryptSensitive($order->sensitive_payload) : [];

        $visual = $this->cardVisualMeta($order);

        return [
            'orderId'                 => $order->order_id,
            'createdAt'               => $order->created_at,
            'updatedAt'               => $order->updated_at,
            'status'                  => $order->status,
            'cardBrand'               => $order->card_brand,
            'cardType'                => $order->card_type,
            'panLast4'                => $order->pan_last4,
            'cardNumber'              => $includeSensitive ? ($sensitive['cardNumber'] ?? null) : null,
            'expiryDate'              => $includeSensitive ? ($sensitive['expiryDate'] ?? null) : null,
            'cvv'                     => $includeSensitive ? ($sensitive['cvv'] ?? null) : null,
            'binPrefix'               => $order->bin_prefix,
            'effectiveClassCode'      => $order->effective_class_code,
            'effectiveFundingType'    => $order->effective_funding_type,
            'classificationAuthority' => $order->classification_authority,
            'cardDisplayName'         => $visual['displayName'],
            'cardBackground'          => $visual['background'],
            'cardLogo'                => $visual['logo'],
            'deviceType'              => $order->device_type,
            'clientIpMasked'          => $this->maskIp($order->client_ip),
            'clientIp'                => $includeSensitive ? $order->client_ip : null,
            'riskScore'               => (int) ($order->risk_score ?? 0),
            'riskFlagCount'           => count($riskFlags),
            'riskFlags'               => $riskFlags,
            'otpAttemptCount'         => (int) ($order->otp_attempt_count ?? 0),
            'latestSandboxOtp'        => $includeSensitive ? ($sensitive['latestSandboxOtp'] ?? null) : null,
            'otpSubmittedAt'          => $order->otp_submitted_at,
            'reviewedAt'              => $order->reviewed_at,
            'reviewedBy'              => $order->reviewed_by,
            'bin'                     => [
                'status'     => $order->bin_lookup_status,
                'source'     => $order->bin_lookup_source,
                'brand'      => $bin['brand']           ?? null,
                'funding'    => $bin['resolvedFunding'] ?? null,
                'issuer'     => $bin['issuer']          ?? null,
                'country'    => $bin['country']         ?? null,
                'luhnValid'  => array_key_exists('luhnValid', $bin) ? $bin['luhnValid'] : null,
                'responseMs' => $order->bin_response_ms,
            ],
        ];
    }

    private function safeBinDto(array $bin, object $order): array
    {
        return [
            'status'         => $order->bin_lookup_status,
            'source'         => $order->bin_lookup_source,
            'bin'            => $order->bin_prefix,
            'brand'          => $bin['brand']             ?? null,
            'type'           => $bin['type']              ?? null,
            'category'       => $bin['category']          ?? null,
            'prepaid'        => $bin['prepaid']           ?? null,
            'funding'        => $bin['resolvedFunding']   ?? null,
            'classCode'      => $bin['resolvedClassCode'] ?? null,
            'issuer'         => $bin['issuer']            ?? null,
            'issuerPhone'    => $bin['issuerPhone']       ?? null,
            'issuerUrl'      => $bin['issuerUrl']         ?? null,
            'country'        => $bin['country']           ?? null,
            'iso2'           => $bin['iso2']              ?? null,
            'iso3'           => $bin['iso3']              ?? null,
            'luhnValid'      => array_key_exists('luhnValid', $bin) ? $bin['luhnValid'] : null,
            'lengthValid'    => array_key_exists('lengthValid', $bin) ? $bin['lengthValid'] : null,
            'masked'         => $order->bin_card_number_masked,
            'providerCached' => $bin['providerCached'] ?? null,
            'responseMs'     => $order->bin_response_ms,
            'lookedUpAt'     => $order->bin_lookup_at,
        ];
    }

    private function decryptSensitive(?string $payload): array
    {
        if (! $payload) {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($payload), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function decodeJson(mixed $value, array $fallback = []): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return $fallback;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $fallback;
    }

    private function cardVisualMeta(object $order): array
    {
        $brand   = strtoupper((string) $order->card_brand);
        $funding = strtoupper((string) ($order->effective_funding_type ?: ''));

        if ($brand === 'VISA') {
            return $this->withPluginAssetUrls([
                'displayName' => $funding === 'DEBIT' ? 'Visa Debit TEST' : 'Visa Credit TEST',
                'background'  => $funding === 'DEBIT'
                    ? '/beikeshop-test/assets/cards/backgrounds/visa-debit.svg'
                    : '/beikeshop-test/assets/cards/backgrounds/visa-credit.svg',
                'logo' => '/beikeshop-test/assets/cards/logos/visa.svg',
            ]);
        }

        if ($brand === 'MASTERCARD') {
            $background = match ($funding) {
                'DEBIT'   => '/beikeshop-test/assets/cards/backgrounds/mastercard-debit.svg',
                'PREPAID' => '/beikeshop-test/assets/cards/backgrounds/mastercard-prepaid.svg',
                default   => '/beikeshop-test/assets/cards/backgrounds/mastercard-credit.svg',
            };

            return $this->withPluginAssetUrls([
                'displayName' => 'Mastercard ' . ucfirst(strtolower($funding ?: 'Credit')) . ' TEST',
                'background'  => $background,
                'logo'        => '/beikeshop-test/assets/cards/logos/mastercard.svg',
            ]);
        }

        if (in_array($brand, ['AMEX', 'AMERICAN EXPRESS'], true)) {
            return $this->withPluginAssetUrls([
                'displayName' => 'American Express Credit TEST',
                'background'  => '/beikeshop-test/assets/cards/backgrounds/amex-credit.svg',
                'logo'        => '/beikeshop-test/assets/cards/logos/amex.svg',
            ]);
        }

        if ($brand === 'JCB') {
            return $this->withPluginAssetUrls([
                'displayName' => 'JCB Credit TEST',
                'background'  => '/beikeshop-test/assets/cards/backgrounds/jcb-credit.svg',
                'logo'        => '/beikeshop-test/assets/cards/logos/jcb.svg',
            ]);
        }

        if (in_array($brand, ['UNIONPAY', 'CHINA UNIONPAY'], true)) {
            return $this->withPluginAssetUrls([
                'displayName' => $funding === 'DEBIT' ? 'UnionPay Debit TEST' : 'UnionPay Credit TEST',
                'background'  => $funding === 'DEBIT'
                    ? '/beikeshop-test/assets/cards/backgrounds/unionpay-debit.svg'
                    : '/beikeshop-test/assets/cards/backgrounds/unionpay-credit.svg',
                'logo' => '/beikeshop-test/assets/cards/logos/unionpay.svg',
            ]);
        }

        return $this->withPluginAssetUrls([
            'displayName' => 'L',
            'background'  => '/beikeshop-test/assets/cards/backgrounds/gift.svg',
            'logo'        => '/beikeshop-test/assets/cards/logos/gift.svg',
        ]);
    }

    private function withPluginAssetUrls(array $meta): array
    {
        foreach (['background', 'logo'] as $key) {
            $path = (string) ($meta[$key] ?? '');
            if (str_starts_with($path, '/beikeshop-test/assets/cards/')) {
                $meta[$key] = plugin_asset(
                    'beike_shop_test_sandbox',
                    'cards/' . substr($path, strlen('/beikeshop-test/assets/cards/'))
                );
            }
        }

        return $meta;
    }

    private function maskIp(?string $ip): ?string
    {
        if (! $ip) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts    = explode('.', $ip);
            $parts[3] = '***';

            return implode('.', $parts);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return substr($ip, 0, 12) . '…';
        }

        return '***';
    }
}
