<?php

namespace Plugin\BeikeShopTestSandbox\Controllers;

use Beike\Admin\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Plugin\BeikeShopTestSandbox\Services\AdminAuditService;
use Plugin\BeikeShopTestSandbox\Services\AdminRadarService;
use Plugin\BeikeShopTestSandbox\Services\FullExportService;
use Plugin\BeikeShopTestSandbox\Services\OrderRetentionService;
use Plugin\BeikeShopTestSandbox\Services\SandboxReviewService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminRadarController extends Controller
{
    public function __construct(
        private AdminRadarService $radar,
        private SandboxReviewService $review,
        private AdminAuditService $audit,
        private OrderRetentionService $retention,
        private FullExportService $fullExport
    ) {}

    public function index()
    {
        return view('BeikeShopTestSandbox::admin_live_screen');
    }

    public function pulse(): JsonResponse
    {
        return response()->json([
            'success'       => true,
            'version'       => (string) Cache::get('beikeshop_test_stream_version', '0'),
            'waitingReview' => DB::table('beikeshop_test_checkout_logs')
                ->where('status', 'WAITING_REVIEW')
                ->count(),
            'latestUpdatedAt' => DB::table('beikeshop_test_checkout_logs')
                ->max('updated_at'),
            'serverTime' => now()->toIso8601String(),
        ]);
    }

    public function overview(): JsonResponse
    {
        return response()->json([
            'success'  => true,
            'overview' => $this->radar->overview(),
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search'    => ['nullable', 'string', 'max:120'],
            'status'    => ['nullable', 'string', 'max:32'],
            'classCode' => ['nullable', 'string', 'max:8'],
            'brand'     => ['nullable', 'string', 'max:30'],
            'risk'      => ['nullable', 'in:low,medium,high'],
            'binStatus' => ['nullable', 'string', 'max:32'],
            'binPrefix' => ['nullable', 'regex:/^\d{6,8}$/'],
            'todayOnly' => ['nullable', 'boolean'],
            'page'      => ['nullable', 'integer', 'min:1'],
            'perPage'   => ['nullable', 'integer', 'min:10', 'max:100'],
            'sort'      => ['nullable', 'in:created_at,risk_score,status,card_brand,updated_at'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);

        $result = $this->radar->paginateOrders($validated, true);

        $this->audit->record(
            'VIEW_SENSITIVE_ORDER_LIST',
            null,
            current_user()?->id,
            ['orders' => count($result['data'] ?? []), 'page' => $result['pagination']['page'] ?? 1]
        );

        return response()->json([
            'success' => true,
            ...$result,
        ]);
    }

    public function show(string $orderId): JsonResponse
    {
        $this->assertOrderId($orderId);

        $detail = $this->radar->safeDetail($orderId);
        if (! $detail) {
            return response()->json([
                'success' => false,
                'message' => '订单不存在',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'order'   => $detail,
        ]);
    }

    public function sensitive(Request $request, string $orderId): JsonResponse
    {
        $this->assertOrderId($orderId);

        $detail = $this->radar->sensitiveDetail($orderId);
        if (! $detail) {
            return response()->json([
                'success' => false,
                'message' => '订单不存在',
            ], 404);
        }

        $actorId = current_user()?->id;

        $surface = strtoupper((string) $request->input('surface', 'DETAIL'));

        $this->audit->record(
            $surface === 'CARD_VISUAL' ? 'VIEW_CARD_VISUAL' : 'VIEW_SENSITIVE_TEST_DATA',
            $orderId,
            $actorId,
            ['surface' => $surface]
        );

        return response()->json([
            'success'   => true,
            'sensitive' => $detail,
        ]);
    }

    public function timeline(Request $request, string $orderId): JsonResponse
    {
        $this->assertOrderId($orderId);

        if (! $this->radar->safeDetail($orderId)) {
            return response()->json([
                'success' => false,
                'message' => '订单不存在',
            ], 404);
        }

        return response()->json([
            'success'  => true,
            'timeline' => $this->radar->timeline(
                $orderId,
                (int) $request->query('limit', 100)
            ),
        ]);
    }

    public function decision(Request $request, string $orderId): JsonResponse
    {
        $this->assertOrderId($orderId);

        $validated = $request->validate([
            'action' => ['required', 'in:APPROVE,RETRY_OTP'],
            'reason' => ['nullable', 'string', 'max:250'],
        ]);

        $result = $this->review->decide(
            $orderId,
            $validated['action'],
            current_user()?->id,
            $validated['reason'] ?? null
        );

        return response()->json($result['body'], $result['status']);
    }

    public function destroy(Request $request, string $orderId): JsonResponse
    {
        $this->assertOrderId($orderId);

        abort_unless(
            (bool) config('beikeshop_test.records.manual_delete_only', true),
            403,
            '当前环境不允许通过后台删除订单。'
        );

        $validated = $request->validate([
            'confirmation' => ['required', 'string', 'max:32'],
        ]);

        $result = $this->retention->deleteOrder(
            $orderId,
            $validated['confirmation'],
            current_user()?->id
        );

        return response()->json($result['body'], $result['status']);
    }

    public function exportFull(Request $request): BinaryFileResponse|JsonResponse
    {
        $validated = $request->validate([
            'scope'             => ['required', 'in:selected,filtered,all'],
            'orderIds'          => ['nullable', 'array', 'max:5000'],
            'orderIds.*'        => ['string', 'regex:/^DEMO_[A-Z0-9]{12}$/'],
            'includeSensitive'  => ['accepted'],
            'filters'           => ['nullable', 'array'],
            'filters.search'    => ['nullable', 'string', 'max:120'],
            'filters.status'    => ['nullable', 'string', 'max:32'],
            'filters.classCode' => ['nullable', 'string', 'max:8'],
            'filters.risk'      => ['nullable', 'in:low,medium,high'],
            'filters.binStatus' => ['nullable', 'string', 'max:32'],
            'filters.binPrefix' => ['nullable', 'regex:/^\d{6,8}$/'],
            'filters.todayOnly' => ['nullable', 'boolean'],
        ]);

        $scope    = $validated['scope'];
        $orderIds = $validated['orderIds'] ?? [];
        $filters  = $validated['filters']  ?? [];

        try {
            $result = $this->fullExport->build(
                $scope,
                $orderIds,
                $filters,
                current_user()?->id
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $this->audit->record(
            'EXPORT_FULL_XLSX',
            null,
            current_user()?->id,
            [
                'scope'                => $scope,
                'orders'               => $result['orders'],
                'worksheet'            => $result['worksheet'] ?? 'All Records',
                'columns'              => $result['columns']   ?? 39,
                'content'              => 'card_identity_device_ip_full_bin',
                'sensitiveTestContent' => true,
            ]
        );

        return response()
            ->download(
                $result['path'],
                $result['filename'],
                [
                    'Content-Type'           => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Cache-Control'          => 'no-store, private',
                    'X-Content-Type-Options' => 'nosniff',
                ]
            )
            ->deleteFileAfterSend(true);
    }

    public function exportTemplate(Request $request): BinaryFileResponse
    {
        $path = dirname(__DIR__) . '/resources/export_templates/beikeshop_test_full_export_template.xlsx';
        abort_unless(is_file($path), 404, '导出模板不存在。');

        $this->audit->record(
            'DOWNLOAD_EXPORT_TEMPLATE',
            null,
            current_user()?->id,
            ['template' => basename($path)]
        );

        return response()->download(
            $path,
            'beikeshop_test_full_export_template.xlsx',
            [
                'Content-Type'           => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control'          => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    private function assertOrderId(string $orderId): void
    {
        abort_unless(
            (bool) preg_match('/^DEMO_[A-Z0-9]{12}$/', $orderId),
            422,
            '订单号格式错误'
        );
    }
}
