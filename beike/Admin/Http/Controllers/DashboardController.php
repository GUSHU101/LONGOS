<?php

namespace Beike\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Saas\Support\SensitiveValueRedactor;
use Beike\Admin\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class DashboardController extends Controller
{
    protected DashboardService $dashboardService;

    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    public function getOverview(Request $request): JsonResponse
    {
        $timeRange = (string) $request->get('time_range', 'today');
        if (! $this->isValidTimeRange($timeRange)) {
            return $this->invalidTimeRangeResponse($timeRange);
        }

        try {
            return response()->json([
                'success' => true,
                'data'    => $this->dashboardService->getOverview($timeRange),
            ])->header('Cache-Control', 'no-store, private');
        } catch (\Throwable $e) {
            return $this->serverErrorResponse('overview', 'admin/dashboard.get_overview_failed', $e, [
                'time_range' => $timeRange,
            ]);
        }
    }

    /**
     * 获取基础统计数据
     */
    public function getStats(Request $request): JsonResponse
    {
        $timeRange = $request->get('time_range', 'today');

        // 验证参数
        $validTimeRanges = DashboardService::VALID_TIME_RANGES;
        if (! in_array($timeRange, $validTimeRanges, true)) {
            return response()->json([
                'success' => false,
                'message' => __('admin/dashboard.invalid_time_range', ['timeRange' => $timeRange, 'validRanges' => implode(', ', $validTimeRanges)]),
            ], 400);
        }

        try {
            $stats = $this->dashboardService->getBasicStats($timeRange);

            return response()->json([
                'success' => true,
                'data'    => $stats,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('stats', 'admin/dashboard.get_stats_failed', $e, [
                'time_range' => (string) $timeRange,
            ]);
        }
    }

    /**
     * 获取访客趋势数据
     */
    public function getTrends(Request $request): JsonResponse
    {
        $timeRange = $request->get('time_range', 'today');

        // 验证参数
        $validTimeRanges = DashboardService::VALID_TIME_RANGES;
        if (! in_array($timeRange, $validTimeRanges, true)) {
            return response()->json([
                'success' => false,
                'message' => __('admin/dashboard.invalid_time_range', ['timeRange' => $timeRange, 'validRanges' => implode(', ', $validTimeRanges)]),
            ], 400);
        }

        try {
            $trends = $this->dashboardService->getTrendData($timeRange);

            return response()->json([
                'success' => true,
                'data'    => $trends,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('trends', 'admin/dashboard.get_trends_failed', $e, [
                'time_range' => (string) $timeRange,
            ]);
        }
    }

    /**
     * 获取小型趋势图数据
     */
    public function getMiniChartData(Request $request): JsonResponse
    {
        $timeRange = $request->get('time_range', 'today');

        // 验证参数
        $validTimeRanges = DashboardService::VALID_TIME_RANGES;
        if (! in_array($timeRange, $validTimeRanges, true)) {
            return response()->json([
                'success' => false,
                'message' => __('admin/dashboard.invalid_time_range', ['timeRange' => $timeRange, 'validRanges' => implode(', ', $validTimeRanges)]),
            ], 400);
        }

        try {
            $miniChartData = $this->dashboardService->getMiniChartData($timeRange);

            return response()->json([
                'success' => true,
                'data'    => $miniChartData,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('mini_charts', 'admin/dashboard.get_mini_chart_failed', $e, [
                'time_range' => (string) $timeRange,
            ]);
        }
    }

    /**
     * 获取客户来源分析
     */
    public function getSourceAnalysis(Request $request): JsonResponse
    {
        $timeRange = $request->get('time_range', 'today');

        // 验证参数
        $validTimeRanges = DashboardService::VALID_TIME_RANGES;
        if (! in_array($timeRange, $validTimeRanges, true)) {
            return response()->json([
                'success' => false,
                'message' => __('admin/dashboard.invalid_time_range', ['timeRange' => $timeRange, 'validRanges' => implode(', ', $validTimeRanges)]),
            ], 400);
        }

        try {
            $sourceData = $this->dashboardService->getSourceAnalysis($timeRange);

            return response()->json([
                'success' => true,
                'data'    => $sourceData,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('source_analysis', 'admin/dashboard.get_source_analysis_failed', $e, [
                'time_range' => (string) $timeRange,
            ]);
        }
    }

    /**
     * 获取转化漏斗数据
     */
    public function getFunnelData(Request $request): JsonResponse
    {
        $timeRange = $request->get('time_range', 'today');

        // 验证参数
        $validTimeRanges = DashboardService::VALID_TIME_RANGES;
        if (! in_array($timeRange, $validTimeRanges, true)) {
            return response()->json([
                'success' => false,
                'message' => __('admin/dashboard.invalid_time_range', ['timeRange' => $timeRange, 'validRanges' => implode(', ', $validTimeRanges)]),
            ], 400);
        }

        try {
            $funnelData = $this->dashboardService->getFunnelData($timeRange);

            return response()->json([
                'success' => true,
                'data'    => $funnelData,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('funnel', 'admin/dashboard.get_funnel_failed', $e, [
                'time_range' => (string) $timeRange,
            ]);
        }
    }

    /**
     * 获取商品销售排行
     */
    public function getProductRanking(Request $request, $type): JsonResponse
    {
        $timeRange = $request->get('time_range', 'today');

        // 验证参数
        $validTypes = ['hot'];
        if (! in_array($type, $validTypes)) {
            return response()->json([
                'success' => false,
                'message' => __('admin/dashboard.invalid_product_type', ['type' => $type, 'validTypes' => implode(', ', $validTypes)]),
            ], 400);
        }

        $validTimeRanges = DashboardService::VALID_TIME_RANGES;
        if (! in_array($timeRange, $validTimeRanges, true)) {
            return response()->json([
                'success' => false,
                'message' => __('admin/dashboard.invalid_time_range', ['timeRange' => $timeRange, 'validRanges' => implode(', ', $validTimeRanges)]),
            ], 400);
        }

        try {
            $productData = $this->dashboardService->getProductRanking($type, $timeRange);

            return response()->json([
                'success' => true,
                'data'    => $productData,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('product_ranking', 'admin/dashboard.get_product_ranking_failed', $e, [
                'time_range' => (string) $timeRange,
                'type'       => (string) $type,
            ]);
        }
    }

    /**
     * 获取访问者国家排名
     */
    public function getVisitorCountryRanking(Request $request): JsonResponse
    {
        $timeRange       = $request->get('time_range', 'today');
        $validTimeRanges = DashboardService::VALID_TIME_RANGES;
        if (! in_array($timeRange, $validTimeRanges, true)) {
            return response()->json([
                'success' => false,
                'message' => __('admin/dashboard.invalid_time_range', ['timeRange' => $timeRange, 'validRanges' => implode(', ', $validTimeRanges)]),
            ], 400);
        }

        try {
            return response()->json([
                'success' => true,
                'data'    => $this->dashboardService->getVisitorCountryRanking($timeRange),
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('visitor_countries', 'admin/dashboard.get_visitor_country_ranking_failed', $e, [
                'time_range' => (string) $timeRange,
            ]);
        }
    }

    /**
     * 导出数据报表
     */
    public function exportReport(Request $request)
    {
        // 权限检查
        $user = current_user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => __('admin/dashboard.please_login_first'),
            ], 401);
        }

        // 检查导出权限（可以根据实际需求调整权限检查逻辑）
        if (! $this->hasExportPermission($user)) {
            Log::warning('用户尝试导出报表但无权限', [
                'user_id'    => $user->id,
                'user_email' => $user->email ?? 'unknown',
                'ip'         => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('admin/dashboard.no_export_permission'),
            ], 403);
        }

        $timeRange = $request->get('time_range', 'today');
        $format    = $request->get('format', 'excel');

        // 验证参数
        $validTimeRanges = DashboardService::VALID_TIME_RANGES;
        if (! in_array($timeRange, $validTimeRanges, true)) {
            return response()->json([
                'success' => false,
                'message' => __('admin/dashboard.invalid_time_range', ['timeRange' => $timeRange, 'validRanges' => implode(', ', $validTimeRanges)]),
            ], 400);
        }

        $validFormats = ['excel', 'csv'];
        if (! in_array($format, $validFormats)) {
            return response()->json([
                'success' => false,
                'message' => __('admin/dashboard.invalid_export_format', ['format' => $format, 'validFormats' => implode(', ', $validFormats)]),
            ], 400);
        }

        if (! $this->checkExportRateLimit($user)) {
            return response()->json([
                'success' => false,
                'message' => __('admin/dashboard.export_rate_limit'),
            ], 429)->header('Retry-After', (string) max(1, RateLimiter::availableIn($this->exportRateLimitKey($user))));
        }

        try {
            // 记录导出日志
            Log::info('用户导出数据看板报表', [
                'user_id'    => $user->id,
                'user_email' => $user->email ?? 'unknown',
                'time_range' => $timeRange,
                'format'     => $format,
                'ip'         => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp'  => now()->toDateTimeString(),
            ]);

            return $this->dashboardService->exportReport($timeRange, $format);
        } catch (\InvalidArgumentException $e) {
            Log::error('导出报表参数错误', [
                'user_id'    => $user->id,
                'error'      => $e->getMessage(),
                'time_range' => $timeRange,
                'format'     => $format,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('export', 'admin/dashboard.export_report_failed', $e, [
                'user_id'    => (int) $user->id,
                'time_range' => (string) $timeRange,
                'format'     => (string) $format,
            ]);
        }
    }

    /**
     * 检查用户是否有导出权限
     */
    private function hasExportPermission($user): bool
    {
        // 移除权限验证，允许所有已登录用户导出报表
        return true;
    }

    /**
     * 检查导出频率限制
     */
    private function checkExportRateLimit($user): bool
    {
        return RateLimiter::attempt(
            $this->exportRateLimitKey($user),
            10,
            static fn (): bool => true,
            3600,
        );
    }

    private function exportRateLimitKey($user): string
    {
        $siteId = (string) (current_site()?->getKey() ?? 'single');

        return 'dashboard:export:' . $siteId . ':' . (string) $user->getAuthIdentifier();
    }

    /**
     * 获取缓存状态
     */
    public function getCacheStatus(): JsonResponse
    {
        try {
            $status = $this->dashboardService->getCacheStatus();

            return response()->json([
                'success' => true,
                'data'    => $status,
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('cache_status', 'admin/dashboard.get_cache_status_failed', $e);
        }
    }

    /**
     * 清理缓存
     */
    public function clearCache(): JsonResponse
    {
        try {
            $this->dashboardService->clearDashboardCache();

            return response()->json([
                'success' => true,
                'message' => __('admin/dashboard.cache_clear_success'),
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('cache_clear', 'admin/dashboard.cache_clear_failed', $e);
        }
    }

    private function isValidTimeRange(string $timeRange): bool
    {
        return in_array($timeRange, DashboardService::VALID_TIME_RANGES, true);
    }

    private function invalidTimeRangeResponse(string $timeRange): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => __('admin/dashboard.invalid_time_range', [
                'timeRange'   => $timeRange,
                'validRanges' => implode(', ', DashboardService::VALID_TIME_RANGES),
            ]),
        ], 400);
    }

    /**
     * Keep operational detail in a redacted structured log while returning a
     * stable message to the browser. Database DSNs, provider responses and
     * credentials must never become part of an admin JSON payload.
     *
     * @param array<string, scalar|null> $context
     */
    private function serverErrorResponse(string $operation, string $translation, Throwable $exception, array $context = []): JsonResponse
    {
        Log::error('Dashboard operation failed.', array_merge($context, [
            'operation' => $operation,
            'exception' => $exception::class,
            'message'   => SensitiveValueRedactor::redact($exception->getMessage()),
        ]));

        return response()->json([
            'success' => false,
            'message' => __($translation),
        ], 500)->header('Cache-Control', 'no-store, private');
    }
}
