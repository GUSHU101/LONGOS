<?php

namespace Plugin\BeikeShopTestSandbox\Controllers;

use Beike\Admin\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Plugin\BeikeShopTestSandbox\Services\AdminAuditService;
use Plugin\BeikeShopTestSandbox\Services\BinSearchLookupService;
use Plugin\BeikeShopTestSandbox\Support\TestCardCatalog;

class BinIntelligenceController extends Controller
{
    public function __construct(
        private BinSearchLookupService $binLookup,
        private AdminAuditService $audit
    ) {}

    public function status(): JsonResponse
    {
        return response()->json([
            'success'            => true,
            'health'             => $this->binLookup->health(),
            'quota'              => $this->binLookup->quota(),
            'circuit'            => $this->binLookup->circuitState(),
            'latestQuotaWebhook' => cache()->get('beikeshop_test:bsl:quota_webhook:last'),
        ]);
    }

    public function quota(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'quota'   => $this->binLookup->quota(true),
            'circuit' => $this->binLookup->circuitState(),
        ]);
    }

    public function warm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bins'   => ['nullable', 'array', 'max:50'],
            'bins.*' => ['string', 'regex:/^\d{6,8}$/'],
        ]);

        $bins = $validated['bins'] ?? config(
            'beikeshop_test.bin_lookup.warm_bins',
            TestCardCatalog::providerWarmBins()
        );
        $bins = array_values(array_unique(array_map(
            static fn (string $bin): string => (string) preg_replace('/\D+/', '', $bin),
            $bins
        )));

        $invalidBins = array_values(array_filter(
            $bins,
            static fn (string $bin): bool => ! TestCardCatalog::allowsProviderBin($bin)
        ));

        if ($invalidBins !== []) {
            return response()->json([
                'success' => false,
                'message' => '白名单模式仅允许预热内置 TEST 卡 BIN。',
            ], 422);
        }

        $result = $this->binLookup->batchWarm(
            $bins,
            ['orderId' => null]
        );

        $this->audit->record(
            'BIN_BATCH_WARM',
            null,
            current_user()?->id,
            [
                'requested' => count($bins),
                'status'    => $result['status'] ?? null,
                'results'   => is_array($result['results'] ?? null) ? count($result['results']) : 0,
            ]
        );

        return response()->json([
            'success' => ($result['status'] ?? null) === 'OK',
            'result'  => $result,
        ], ($result['status'] ?? null) === 'OK' ? 200 : 502);
    }

    public function refreshOrder(string $orderId): JsonResponse
    {
        if (! preg_match('/^DEMO_[A-Z0-9]{12}$/', $orderId)) {
            return response()->json([
                'success' => false,
                'message' => '订单号格式错误',
            ], 422);
        }

        $order = DB::table('beikeshop_test_checkout_logs')
            ->where('order_id', $orderId)
            ->first();

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => '沙盒会话不存在',
            ], 404);
        }

        $sensitive = $this->decryptSensitive($order->sensitive_payload);
        $pan       = TestCardCatalog::normalizePan((string) ($sensitive['cardNumber'] ?? ''));

        if (! preg_match('/^\d{12,19}$/', $pan)) {
            return response()->json([
                'success' => false,
                'message' => '该订单没有可用于刷新验证的 TEST PAN',
            ], 409);
        }

        if (! TestCardCatalog::allowsPan($pan)) {
            return response()->json([
                'success' => false,
                'message' => '白名单模式下，该订单不含允许调用外部 BIN 服务的内置 TEST PAN。',
            ], 409);
        }

        // Synthetic gift cards intentionally stay local-only.
        if (str_starts_with((string) $order->scenario, 'SANDBOX_GIFT')) {
            return response()->json([
                'success' => false,
                'message' => 'Sandbox Gift 为本地合成卡，不调用外部 BIN 服务。',
            ], 409);
        }

        $result = $this->binLookup->enrichTestPan(
            $pan,
            true,
            ['orderId' => $orderId]
        );

        $providerClass   = $result['resolvedClassCode'] ?? null;
        $providerFunding = $result['resolvedFunding']   ?? null;
        $providerUsable  = ($result['status'] ?? null) === 'OK' && $providerClass && $providerFunding;

        DB::table('beikeshop_test_checkout_logs')
            ->where('id', $order->id)
            ->update([
                'bin_prefix'             => substr($pan, 0, 8),
                'bin_lookup_status'      => $result['status'] ?? 'UNKNOWN',
                'bin_lookup_source'      => $result['source'] ?? 'FALLBACK',
                'bin_lookup_data'        => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'bin_lookup_at'          => now(),
                'bin_luhn_valid'         => array_key_exists('luhnValid', $result) ? $result['luhnValid'] : null,
                'bin_length_valid'       => array_key_exists('lengthValid', $result) ? $result['lengthValid'] : null,
                'bin_card_number_masked' => $result['cardNumberMasked'] ?? null,
                'bin_response_hash'      => $result['responseHash']     ?? null,
                'bin_response_ms'        => $result['responseTimeMs']   ?? null,
                'effective_class_code'   => $providerUsable
                    ? $providerClass
                    : $order->effective_class_code,
                'effective_funding_type' => $providerUsable
                    ? $providerFunding
                    : $order->effective_funding_type,
                'classification_authority' => $providerUsable
                    ? 'BIN_API'
                    : ($order->classification_authority ?: 'LOCAL_TEST_PROFILE'),
                'updated_at' => now(),
            ]);

        cache()->forever('beikeshop_test_stream_version', (string) \Illuminate\Support\Str::uuid());

        $this->audit->record(
            'BIN_FORCE_REFRESH',
            $orderId,
            current_user()?->id,
            [
                'status' => $result['status'] ?? null,
                'source' => $result['source'] ?? null,
            ]
        );

        return response()->json([
            'success' => ($result['status'] ?? null) === 'OK',
            'orderId' => $orderId,
            'result'  => $result,
        ], ($result['status'] ?? null) === 'OK' ? 200 : 502);
    }

    public function events(Request $request): JsonResponse
    {
        $limit = max(1, min(200, (int) $request->query('limit', 50)));

        $events = DB::table('beikeshop_test_bin_events')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $summary = DB::table('beikeshop_test_bin_events')
            ->selectRaw('status, source, COUNT(*) AS total')
            ->where('created_at', '>=', now()->subDay())
            ->groupBy('status', 'source')
            ->orderByDesc('total')
            ->get();

        return response()->json([
            'success'        => true,
            'events'         => $events,
            'last24hSummary' => $summary,
        ]);
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
}
