<?php

namespace Beike\Admin\Services;

use App\Services\PlatformPayment\PlatformPaymentOperations;
use Beike\Models\CartProduct;
use Beike\Models\Country;
use Beike\Models\DashboardEvent;
use Beike\Models\Order;
use Beike\Models\ProductView;
use Beike\Models\StorefrontVisit;
use Beike\Services\StateMachineService;
use Beike\Services\TrafficSourceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardService
{
    public const VALID_TIME_RANGES = ['today', 'yesterday', 'week', 'month'];

    private const CACHE_SCHEMA_VERSION = 'v7-payment-operations';

    private MockDataGenerator $mockDataGenerator;

    private bool $useMockData;

    private float $mockDataMultiplier;

    private bool $enableCache;

    private int $cacheDuration;

    private ?bool $dashboardEventsAvailable = null;

    private ?bool $productViewsAvailable = null;

    private ?bool $productViewRefererAvailable = null;

    private ?bool $productViewCountryAvailable = null;

    private ?bool $productViewVisitorKeyAvailable = null;

    private ?bool $storefrontVisitsAvailable = null;

    private ?bool $storefrontVisitConfirmationAvailable = null;

    private ?bool $cartProductsSessionAvailable = null;

    private ?bool $orderHistoriesAvailable = null;

    private bool $buildingOverview = false;

    private ?Carbon $snapshotAt = null;

    /** @var array<string, mixed> */
    private array $snapshotMemo = [];

    public function __construct()
    {
        // A stale DASHBOARD_USE_MOCK_DATA value must never make a production
        // tenant display invented traffic or sales figures.
        $this->useMockData        = ! app()->environment('production')
            && Config::get('app.dashboard.use_mock_data', false);
        $this->mockDataMultiplier = Config::get('app.dashboard.mock_data_multiplier', 1.0);
        // Operational dashboards should reflect new orders and carts immediately.
        // Caching is opt-in for very high traffic deployments.
        $this->enableCache        = Config::get('app.dashboard.enable_cache', false);
        $this->cacheDuration      = max(0, (int) Config::get('app.dashboard.cache_duration', 0));
        $this->mockDataGenerator  = new MockDataGenerator($this->mockDataMultiplier);
    }

    /**
     * Return one consistent dashboard snapshot. Keeping the selected period on
     * the server prevents cards and charts from showing data from different
     * requests when an administrator switches ranges quickly.
     */
    public function getOverview(string $timeRange = 'today'): array
    {
        $this->validateTimeRange($timeRange);

        return $this->remember($this->generateCacheKey('overview', $timeRange), function () use ($timeRange): array {
            // When caching is enabled, cache the response as one atomic
            // snapshot. Independently cached cards and charts can otherwise
            // have different ages and disagree with each other.
            $this->buildingOverview = true;
            $this->snapshotAt       = Carbon::now();
            $this->snapshotMemo     = [];

            try {
                return [
                    'stats'       => $this->getBasicStats($timeRange),
                    'trends'      => $this->getTrendData($timeRange),
                    'mini_charts' => $this->getMiniChartData($timeRange),
                    'sources'     => $this->getSourceAnalysis($timeRange),
                    'funnel'      => $this->getFunnelData($timeRange),
                    'products'    => $this->getProductRanking('hot', $timeRange),
                    'countries'   => $this->getVisitorCountryRanking($timeRange),
                    'payments'    => app(PlatformPaymentOperations::class)->snapshot($this->getDateRange($timeRange)),
                    'meta'        => $this->getAnalyticsMeta($timeRange),
                ];
            } finally {
                $this->buildingOverview = false;
                $this->snapshotAt       = null;
                $this->snapshotMemo     = [];
            }
        });
    }

    /**
     * Cache wrapper method
     */
    private function remember(string $cacheKey, callable $callback): mixed
    {
        if ($this->buildingOverview) {
            return $callback();
        }

        if ($this->enableCache && $this->cacheDuration > 0) {
            return Cache::remember($cacheKey, $this->cacheDuration, $callback);
        }

        return $callback();
    }

    private function validateTimeRange(string $timeRange): void
    {
        if (! in_array($timeRange, self::VALID_TIME_RANGES, true)) {
            throw new \InvalidArgumentException(__('admin/dashboard.invalid_time_range', [
                'timeRange'   => $timeRange,
                'validRanges' => implode(', ', self::VALID_TIME_RANGES),
            ]));
        }
    }

    /**
     * Get basic statistics data
     */
    public function getBasicStats(string $timeRange = 'today'): array
    {
        $this->validateTimeRange($timeRange);

        // 生成缓存键
        $cacheKey = $this->generateCacheKey('basic_stats', $timeRange);

        // 尝试从缓存获取数据
        return $this->remember($cacheKey, function () use ($timeRange) {
            // 如果启用模拟数据，使用模拟数据生成器
            if ($this->useMockData) {
                return $this->mockDataGenerator->generateBasicStats($timeRange);
            }

            // 使用真实数据
            $dateRange         = $this->getDateRange($timeRange);
            $previousDateRange = $this->getPreviousDateRange($timeRange);

            // 访客统计 (基于商品浏览记录)
            $visitorStats = $this->getVisitorStats($dateRange, $previousDateRange);

            // 加购统计
            $cartStats = $this->getCartStats($dateRange, $previousDateRange);

            // 购买统计
            $purchaseStats = $this->getPurchaseStats($dateRange, $previousDateRange);

            // 添加支付信息统计（在支付表单可输入时记录，不含任何支付字段）。
            $paymentInfoStats = $this->getPaymentInformationStats($dateRange, $previousDateRange);

            // 转化率统计
            $conversionStats = $this->getConversionStats($visitorStats, $cartStats, $purchaseStats, $paymentInfoStats);

            // Revenue is stored in the platform accounting currency (USD).
            // It is calculated from paid orders only, using the paid transition
            // time so late payments are attributed to the correct period.
            $commerceStats = $this->getCommerceStats($dateRange, $previousDateRange);

            return [
                'visitors'            => $visitorStats,
                'carts'               => $cartStats,
                'purchases'           => $purchaseStats,
                'paid_orders'         => $commerceStats['paid_orders'],
                'revenue'             => $commerceStats['revenue'],
                'average_order_value' => $commerceStats['average_order_value'],
                'conversion'          => $conversionStats,
                'currency'            => 'USD',
                'time_range'          => $timeRange,
            ];
        });
    }

    /**
     * 获取趋势数据
     */
    public function getTrendData(string $timeRange = 'today'): array
    {
        $this->validateTimeRange($timeRange);

        // 生成缓存键
        $cacheKey = $this->generateCacheKey('trend_data', $timeRange);

        // 尝试从缓存获取数据
        return $this->remember($cacheKey, function () use ($timeRange) {
            // 如果启用模拟数据，使用模拟数据生成器
            if ($this->useMockData) {
                return $this->mockDataGenerator->generateTrendData($timeRange);
            }

            // 使用真实数据
            switch ($timeRange) {
                case 'month':
                    return $this->getRollingTrends(30);
                case 'week':
                    return $this->getRollingTrends(7);
                case 'yesterday':
                    return $this->getDailyTrends(1);
                case 'today':
                default:
                    return $this->getDailyTrends(0);
            }
        });
    }

    /**
     * 获取小型趋势图数据
     */
    public function getMiniChartData(string $timeRange = 'today'): array
    {
        $this->validateTimeRange($timeRange);

        // 生成缓存键
        $cacheKey = $this->generateCacheKey('mini_chart_data', $timeRange);

        // 尝试从缓存获取数据
        return $this->remember($cacheKey, function () use ($timeRange) {
            // 如果启用模拟数据，使用模拟数据生成器
            if ($this->useMockData) {
                return $this->mockDataGenerator->generateMiniChartData($timeRange);
            }

            // 使用真实数据
            $dateRange = $this->getDateRange($timeRange);

            // 根据时间范围生成不同的小型趋势数据
            switch ($timeRange) {
                case 'month':
                    return $this->getRollingMiniChartData(30);
                case 'week':
                    return $this->getRollingMiniChartData(7);
                case 'yesterday':
                    return $this->getDailyMiniChartData(1);
                case 'today':
                default:
                    return $this->getDailyMiniChartData(0);
            }
        });
    }

    /**
     * 获取客户来源分析
     */
    public function getSourceAnalysis(string $timeRange = 'today'): array
    {
        $this->validateTimeRange($timeRange);

        return $this->remember($this->generateCacheKey('source_analysis', $timeRange), function () use ($timeRange) {
            if ($this->useMockData) {
                return $this->mockDataGenerator->generateSourceAnalysis($timeRange);
            }

            $dateRange = $this->getDateRange($timeRange);
            $sources   = $this->unifiedSourceCounts($dateRange);
            $total     = array_sum($sources);

            if ($total === 0) {
                return $this->noSourceData();
            }

            $result = [];
            foreach ($sources as $key => $count) {
                if ($count < 1) {
                    continue;
                }
                $result[] = [
                    'key'        => $key,
                    'name'       => $this->sourceName($key),
                    'value'      => $count,
                    'percentage' => round(($count / $total) * 100, 1),
                ];
            }

            usort($result, static fn (array $left, array $right): int => ($right['value'] <=> $left['value'])
                ?: ($left['key'] <=> $right['key']));

            return $result;
        });
    }

    /**
     * 获取转化漏斗数据
     */
    public function getFunnelData(string $timeRange = 'today'): array
    {
        $this->validateTimeRange($timeRange);

        // 生成缓存键
        $cacheKey = $this->generateCacheKey('funnel_data', $timeRange);

        // 尝试从缓存获取数据
        return $this->remember($cacheKey, function () use ($timeRange) {
            // 如果启用模拟数据，使用模拟数据生成器
            if ($this->useMockData) {
                return $this->mockDataGenerator->generateFunnelData($timeRange);
            }

            // 使用真实数据
            $dateRange = $this->getDateRange($timeRange);

            // Every funnel stage is a deduplicated participant count. Mixing
            // page-view totals with visitor and order totals made the ratios
            // incomparable and could suggest false conversion performance.
            $visits         = $this->countUniqueVisitors($dateRange);
            $productViewers = $this->countUniqueProductViewVisitors($dateRange);
            $cartVisitors   = $this->countUniqueCartVisitors($dateRange);
            $orderCreators  = $this->countOrderCreatorsInRange($dateRange);
            $paidPurchasers = $this->countPaidPurchasersInRange($dateRange);

            $maxValue = max($visits, $productViewers, $cartVisitors, $orderCreators, $paidPurchasers) ?: 1;

            return [
                [
                    'key'        => 'visitors',
                    'name'       => __('admin/dashboard.funnel_visitors'),
                    'value'      => $visits,
                    // A zero visitor baseline is not 100% conversion. The
                    // previous hard-coded values made an empty period look
                    // healthy and also drew a full-width bar next to zero.
                    'percentage' => $visits > 0 ? 100 : 0,
                    'width'      => round(($visits / $maxValue) * 100, 1),
                ],
                [
                    'key'        => 'product_viewers',
                    'name'       => __('admin/dashboard.product_viewers'),
                    'value'      => $productViewers,
                    'percentage' => $this->conversionRate($productViewers, $visits),
                    'width'      => round(($productViewers / $maxValue) * 100, 1),
                ],
                [
                    'key'        => 'cart_visitors',
                    'name'       => __('admin/dashboard.cart_visitors'),
                    'value'      => $cartVisitors,
                    'percentage' => $this->conversionRate($cartVisitors, $productViewers),
                    'width'      => round(($cartVisitors / $maxValue) * 100, 1),
                ],
                [
                    'key'        => 'order_creators',
                    'name'       => __('admin/dashboard.order_creators'),
                    'value'      => $orderCreators,
                    'percentage' => $this->conversionRate($orderCreators, $cartVisitors),
                    'width'      => round(($orderCreators / $maxValue) * 100, 1),
                ],
                [
                    'key'        => 'paid_purchasers',
                    'name'       => __('admin/dashboard.paid_purchasers'),
                    'value'      => $paidPurchasers,
                    'percentage' => $this->conversionRate($paidPurchasers, $orderCreators),
                    'width'      => round(($paidPurchasers / $maxValue) * 100, 1),
                ],
            ];
        });
    }

    /**
     * 获取商品销售排行
     */
    public function getProductRanking(string $type = 'hot', string $timeRange = 'today'): array
    {
        // 验证参数
        $validTypes = ['hot'];
        if (! in_array($type, $validTypes)) {
            throw new \InvalidArgumentException(__('admin/dashboard.invalid_product_type', ['type' => $type, 'validTypes' => implode(', ', $validTypes)]));
        }

        $this->validateTimeRange($timeRange);

        // 生成缓存键
        $cacheKey = $this->generateCacheKey('product_ranking', $type, $timeRange);

        // 尝试从缓存获取数据
        return $this->remember($cacheKey, function () use ($type, $timeRange) {
            // 如果启用模拟数据，使用模拟数据生成器
            if ($this->useMockData) {
                return $this->mockDataGenerator->generateProductRanking($type, $timeRange);
            }

            // 使用真实数据
            $dateRange = $this->getDateRange($timeRange);

            $paidOrders           = $this->paidOrdersSubquery($dateRange);
            $orderProductId       = $this->qualifiedColumn('order_products', 'product_id');
            $orderProductName     = $this->qualifiedColumn('order_products', 'name');
            $orderProductPrice    = $this->qualifiedColumn('order_products', 'price');
            $orderProductQuantity = $this->qualifiedColumn('order_products', 'quantity');
            $paidAt               = $this->qualifiedColumn('dashboard_paid_orders', 'paid_at');
            $query                = DB::table('order_products')
                ->joinSub($paidOrders, 'dashboard_paid_orders', function ($join) {
                    $join->on('order_products.order_id', '=', 'dashboard_paid_orders.order_id');
                })
                ->whereNull('order_products.deleted_at')
                ->select(
                    DB::raw("{$orderProductId} as id"),
                    DB::raw("MAX({$orderProductName}) as product_name"),
                    DB::raw("MAX({$orderProductPrice}) as price"),
                    DB::raw("SUM({$orderProductQuantity}) as total_sold"),
                    DB::raw("SUM({$orderProductPrice} * {$orderProductQuantity}) as sales_amount"),
                    DB::raw("MAX({$paidAt}) as last_order_at")
                )
                ->groupBy(DB::raw($orderProductId));

            // 热销：按销量降序，销量相同按最近下单时间降序，再按产品 id 作为最终次序。
            $products = $query
                ->orderBy('total_sold', 'desc')
                ->orderBy('last_order_at', 'desc')
                ->orderBy(DB::raw($orderProductId), 'desc')
                ->take(5)
                ->get();

            $maxSold = $products->max('total_sold') ?: 1;

            return $products->map(function ($product) use ($maxSold, $type) {
                return [
                    'id'                  => $product->id,
                    'name'                => (string) ($product->product_name ?: ('#' . $product->id)),
                    'price'               => currency_format($product->price),
                    'price_value'         => (float) $product->price,
                    'total_sold'          => $product->total_sold,
                    'sales_amount'        => (float) $product->sales_amount,
                    'sales_amount_format' => currency_format($product->sales_amount),
                    'percentage'          => round(($product->total_sold / $maxSold) * 100, 1),
                    'progress_width'      => round(($product->total_sold / $maxSold) * 100, 1),
                    'type'                => $type,
                ];
            })->toArray();
        });
    }

    /**
     * Return one country per distinct storefront visitor for the period.
     * Country is captured from a verified CDN header or local GeoIP lookup;
     * shipping and billing addresses are deliberately never used here.
     */
    public function getVisitorCountryRanking(string $timeRange = 'today'): array
    {
        $this->validateTimeRange($timeRange);

        return $this->remember($this->generateCacheKey('visitor_country_ranking', $timeRange), function () use ($timeRange) {
            if ($this->useMockData) {
                return $this->mockDataGenerator->generateVisitorCountryRanking($timeRange);
            }

            $dateRange = $this->getDateRange($timeRange);
            $rows      = $this->unifiedCountryRows($dateRange);

            if ($rows->isEmpty()) {
                return [];
            }

            $totalVisitors = max(1, (int) $rows->sum('visitors'));
            $rows          = $rows->take(8);
            $countryCodes  = $rows->pluck('country_code')
                ->map(static fn ($code) => strtoupper((string) $code))
                ->filter(static fn (string $code) => preg_match('/^[A-Z]{2}$/', $code) === 1)
                ->all();
            $countryNames  = Schema::hasTable('countries')
                ? Country::query()->whereIn('code', $countryCodes)->pluck('name', 'code')
                : collect();

            return $rows->values()->map(function ($row, int $index) use ($countryNames, $totalVisitors) {
                $code    = strtoupper((string) $row->country_code);
                $unknown = preg_match('/^[A-Z]{2}$/', $code) !== 1;

                return [
                    'rank'       => $index + 1,
                    'code'       => $unknown ? '—' : $code,
                    'name'       => $unknown ? __('admin/dashboard.unknown_country') : (string) ($countryNames->get($code) ?: $code),
                    'visitors'   => (int) $row->visitors,
                    'percentage' => round(((int) $row->visitors / $totalVisitors) * 100, 1),
                ];
            })->all();
        });
    }

    /**
     * 导出报表
     */
    public function exportReport(string $timeRange, string $format = 'excel')
    {
        $this->validateTimeRange($timeRange);

        $validFormats = ['excel', 'csv'];
        if (! in_array($format, $validFormats)) {
            throw new \InvalidArgumentException(__('admin/dashboard.invalid_export_format', ['format' => $format, 'validFormats' => implode(', ', $validFormats)]));
        }

        // Export the same atomic snapshot used by the dashboard UI. Running
        // each section as a separate request allowed visits or paid orders
        // committed mid-export to make sheets disagree with each other.
        $overview         = $this->getOverview($timeRange);
        $stats            = $overview['stats'];
        $trends           = $overview['trends'];
        $sourceAnalysis   = $overview['sources'];
        $funnel           = $overview['funnel'];
        $hotProducts      = $overview['products'];
        $visitorCountries = $overview['countries'];

        // 根据格式选择导出方式
        if ($format === 'excel') {
            return $this->exportExcelReport($timeRange, $stats, $trends, $sourceAnalysis, $funnel, $hotProducts, $visitorCountries);
        }

        return $this->exportCsvReport($timeRange, $stats, $trends, $sourceAnalysis, $funnel, $hotProducts, $visitorCountries);

    }

    /**
     * 导出Excel格式报表
     */
    private function exportExcelReport(string $timeRange, array $stats, array $trends, array $sourceAnalysis, array $funnel, array $hotProducts, array $visitorCountries)
    {
        // 生成文件名
        $filename = "dashboard_report_{$timeRange}_" . date('Y-m-d_H-i-s') . '.xlsx';

        // 创建临时文件
        $tempFile = tempnam(sys_get_temp_dir(), 'dashboard_export_');

        try {
            // 使用PhpSpreadsheet创建Excel文件
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;

            // 设置文档属性
            $spreadsheet->getProperties()
                ->setCreator('LONGOS Dashboard')
                ->setTitle(__('admin/dashboard.report_title'))
                ->setDescription(__('admin/dashboard.report_title'))
                ->setSubject('Dashboard Report');

            // 创建多个工作表
            $this->createStatsSheet($spreadsheet, $stats, $timeRange);
            $this->createTrendsSheet($spreadsheet, $trends, $timeRange);
            $this->createSourceAnalysisSheet($spreadsheet, $sourceAnalysis, $timeRange);
            $this->createFunnelSheet($spreadsheet, $funnel, $timeRange);
            $this->createProductsSheet($spreadsheet, $hotProducts, $visitorCountries, $timeRange);

            // 保存文件
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($tempFile);

            // 返回文件下载响应
            return response()->download($tempFile, $filename)->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            // 如果Excel导出失败，回退到CSV格式
            return $this->exportCsvReport($timeRange, $stats, $trends, $sourceAnalysis, $funnel, $hotProducts, $visitorCountries);
        }
    }

    /**
     * 导出CSV格式报表
     */
    private function exportCsvReport(string $timeRange, array $stats, array $trends, array $sourceAnalysis, array $funnel, array $hotProducts, array $visitorCountries)
    {
        $filename = "dashboard_report_{$timeRange}_" . date('Y-m-d_H-i-s') . '.csv';
        $headers  = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($stats, $trends, $sourceAnalysis, $funnel, $hotProducts, $visitorCountries, $timeRange) {
            $file = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // 报表标题和时间范围
            $this->putCsvRow($file, [__('admin/dashboard.report_title')]);
            $this->putCsvRow($file, [__('admin/dashboard.export_time'), date('Y-m-d H:i:s')]);
            $this->putCsvRow($file, [__('admin/dashboard.time_range'), $this->getTimeRangeLabel($timeRange)]);
            $this->putCsvRow($file, []);

            // 基础统计数据
            $this->putCsvRow($file, ['=== ' . __('admin/dashboard.basic_stats') . ' ===']);
            $this->putCsvRow($file, [__('admin/dashboard.visitor_count'), __('admin/dashboard.current_value'), __('admin/dashboard.previous_value'), __('admin/dashboard.change_percentage'), __('admin/dashboard.trend')]);
            $this->putCsvRow($file, [
                __('admin/dashboard.visitor_count'),
                $stats['visitors']['current'],
                $stats['visitors']['previous'],
                $stats['visitors']['percentage'] . '%',
                $stats['visitors']['trend'] === 'up' ? __('admin/dashboard.up') : __('admin/dashboard.down'),
            ]);
            $this->putCsvRow($file, [
                __('admin/dashboard.cart_count'),
                $stats['carts']['current'],
                $stats['carts']['previous'],
                $stats['carts']['percentage'] . '%',
                $stats['carts']['trend'] === 'up' ? __('admin/dashboard.up') : __('admin/dashboard.down'),
            ]);
            $this->putCsvRow($file, [
                __('admin/dashboard.purchase_count'),
                $stats['purchases']['current'],
                $stats['purchases']['previous'],
                $stats['purchases']['percentage'] . '%',
                $stats['purchases']['trend'] === 'up' ? __('admin/dashboard.up') : __('admin/dashboard.down'),
            ]);
            foreach ([
                'paid_orders'         => __('admin/dashboard.paid_orders'),
                'revenue'             => __('admin/dashboard.gross_sales'),
                'average_order_value' => __('admin/dashboard.average_order_value'),
            ] as $key => $label) {
                $this->putCsvRow($file, [
                    $label,
                    $stats[$key]['current'],
                    $stats[$key]['previous'],
                    $stats[$key]['percentage'] . '%',
                    $stats[$key]['trend'] === 'up' ? __('admin/dashboard.up') : __('admin/dashboard.down'),
                ]);
            }
            $this->putCsvRow($file, []);

            // 转化率数据
            $this->putCsvRow($file, ['=== ' . __('admin/dashboard.conversion_data') . ' ===']);
            $this->putCsvRow($file, [__('admin/dashboard.conversion_type'), __('admin/dashboard.current_conversion_rate'), __('admin/dashboard.previous_conversion_rate'), __('admin/dashboard.change')]);
            foreach ($stats['conversion'] as $type => $conversion) {
                $typeLabel = $this->getConversionTypeLabel($type);
                $this->putCsvRow($file, [
                    $typeLabel,
                    $conversion['rate'] . '%',
                    $conversion['previous_rate'] . '%',
                    ($conversion['change'] >= 0 ? '+' : '') . $conversion['change'] . '%',
                ]);
            }
            $this->putCsvRow($file, []);

            // 客户来源分析
            $this->putCsvRow($file, ['=== ' . __('admin/dashboard.customer_source_analysis') . ' ===']);
            $this->putCsvRow($file, [__('admin/dashboard.source'), __('admin/dashboard.visits'), __('admin/dashboard.percentage')]);
            foreach ($sourceAnalysis as $source) {
                $this->putCsvRow($file, [$source['name'], $source['value'], $source['percentage'] . '%']);
            }
            $this->putCsvRow($file, []);

            // 转化漏斗
            $this->putCsvRow($file, ['=== ' . __('admin/dashboard.conversion_funnel') . ' ===']);
            $this->putCsvRow($file, [__('admin/dashboard.stage'), __('admin/dashboard.quantity'), __('admin/dashboard.conversion_rate'), __('admin/dashboard.width')]);
            foreach ($funnel as $step) {
                $this->putCsvRow($file, [$step['name'], $step['value'], $step['percentage'] . '%', $step['width'] . '%']);
            }
            $this->putCsvRow($file, []);

            // 热销商品
            $this->putCsvRow($file, ['=== ' . __('admin/dashboard.hot_products_ranking') . ' ===']);
            $this->putCsvRow($file, [__('admin/dashboard.ranking'), __('admin/dashboard.product_name'), __('admin/dashboard.price'), __('admin/dashboard.sales'), __('admin/dashboard.sales_amount'), __('admin/dashboard.percentage')]);
            foreach ($hotProducts as $index => $product) {
                $this->putCsvRow($file, [
                    $index + 1,
                    $product['name'],
                    $product['price'],
                    $product['total_sold'],
                    $product['sales_amount'] ?? 0,
                    $product['percentage'] . '%',
                ]);
            }
            $this->putCsvRow($file, []);

            // 访问者国家排名
            $this->putCsvRow($file, ['=== ' . __('admin/dashboard.visitor_country_ranking') . ' ===']);
            $this->putCsvRow($file, [__('admin/dashboard.ranking'), __('admin/dashboard.country'), __('admin/dashboard.visits'), __('admin/dashboard.percentage')]);
            foreach ($visitorCountries as $country) {
                $this->putCsvRow($file, [
                    $country['rank'],
                    $country['name'] . ' (' . $country['code'] . ')',
                    $country['visitors'],
                    $country['percentage'] . '%',
                ]);
            }
            $this->putCsvRow($file, []);

            // 趋势数据
            $this->putCsvRow($file, ['=== ' . __('admin/dashboard.trend_data') . ' ===']);
            $this->putCsvRow($file, [__('admin/dashboard.time'), __('admin/dashboard.pv'), __('admin/dashboard.uv'), __('admin/dashboard.paid_orders'), __('admin/dashboard.gross_sales')]);
            for ($i = 0; $i < count($trends['labels']); $i++) {
                $this->putCsvRow($file, [
                    $trends['labels'][$i],
                    $trends['pv'][$i],
                    $trends['uv'][$i],
                    $trends['orders'][$i]  ?? 0,
                    $trends['revenue'][$i] ?? 0,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Prevent values imported from catalog data from becoming spreadsheet
     * formulas when an administrator opens a CSV export in Excel or Sheets.
     */
    private function putCsvRow($file, array $values): void
    {
        $values = array_map(static function ($value) {
            if (! is_string($value)) {
                return $value;
            }

            return preg_match('/^[=+\-@\t\r]/u', $value) === 1 ? "'{$value}" : $value;
        }, $values);

        fputcsv($file, $values);
    }

    /**
     * 获取时间范围标签
     */
    private function getTimeRangeLabel(string $timeRange): string
    {
        $labels = [
            'today'     => __('admin/dashboard.today'),
            'yesterday' => __('admin/dashboard.yesterday'),
            'week'      => __('admin/dashboard.near_7_days'),
            'month'     => __('admin/dashboard.near_30_days'),
        ];

        return $labels[$timeRange] ?? $timeRange;
    }

    /**
     * 获取转化类型标签
     */
    private function getConversionTypeLabel(string $type): string
    {
        $labels = [
            'visitor-to-purchase'            => __('admin/dashboard.visitor_to_purchase'),
            'visitor-to-cart'                => __('admin/dashboard.visitor_to_cart'),
            'visitor-to-payment-information' => __('admin/dashboard.visitor_to_payment_information'),
            'cart-to-purchase'               => __('admin/dashboard.cart_to_purchase'),
        ];

        return $labels[$type] ?? $type;
    }

    /**
     * 创建统计数据工作表
     */
    private function createStatsSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, array $stats, string $timeRange): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(__('admin/dashboard.report_title'));

        // 设置标题
        $sheet->setCellValue('A1', __('admin/dashboard.report_title'));
        $sheet->setCellValue('A2', __('admin/dashboard.time_range') . ': ' . $this->getTimeRangeLabel($timeRange));
        $sheet->setCellValue('A3', __('admin/dashboard.export_time') . ': ' . date('Y-m-d H:i:s'));

        // 基础统计数据
        $sheet->setCellValue('A5', __('admin/dashboard.basic_stats'));
        $sheet->setCellValue('A6', __('admin/dashboard.visitor_count'));
        $sheet->setCellValue('B6', __('admin/dashboard.current_value'));
        $sheet->setCellValue('C6', __('admin/dashboard.previous_value'));
        $sheet->setCellValue('D6', __('admin/dashboard.change_percentage'));
        $sheet->setCellValue('E6', __('admin/dashboard.trend'));

        $row = 7;
        $sheet->setCellValue("A{$row}", __('admin/dashboard.visitor_count'));
        $sheet->setCellValue("B{$row}", $stats['visitors']['current']);
        $sheet->setCellValue("C{$row}", $stats['visitors']['previous']);
        $sheet->setCellValue("D{$row}", $stats['visitors']['percentage'] . '%');
        $sheet->setCellValue("E{$row}", $stats['visitors']['trend'] === 'up' ? __('admin/dashboard.up') : __('admin/dashboard.down'));
        $row++;

        $sheet->setCellValue("A{$row}", __('admin/dashboard.cart_count'));
        $sheet->setCellValue("B{$row}", $stats['carts']['current']);
        $sheet->setCellValue("C{$row}", $stats['carts']['previous']);
        $sheet->setCellValue("D{$row}", $stats['carts']['percentage'] . '%');
        $sheet->setCellValue("E{$row}", $stats['carts']['trend'] === 'up' ? __('admin/dashboard.up') : __('admin/dashboard.down'));
        $row++;

        $sheet->setCellValue("A{$row}", __('admin/dashboard.purchase_count'));
        $sheet->setCellValue("B{$row}", $stats['purchases']['current']);
        $sheet->setCellValue("C{$row}", $stats['purchases']['previous']);
        $sheet->setCellValue("D{$row}", $stats['purchases']['percentage'] . '%');
        $sheet->setCellValue("E{$row}", $stats['purchases']['trend'] === 'up' ? __('admin/dashboard.up') : __('admin/dashboard.down'));
        $row++;

        foreach ([
            'paid_orders'         => __('admin/dashboard.paid_orders'),
            'revenue'             => __('admin/dashboard.gross_sales'),
            'average_order_value' => __('admin/dashboard.average_order_value'),
        ] as $key => $label) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $stats[$key]['current']);
            $sheet->setCellValue("C{$row}", $stats[$key]['previous']);
            $sheet->setCellValue("D{$row}", $stats[$key]['percentage'] . '%');
            $sheet->setCellValue("E{$row}", $stats[$key]['trend'] === 'up' ? __('admin/dashboard.up') : __('admin/dashboard.down'));
            $row++;
        }

        // 添加转化率数据
        $row += 2;
        $sheet->setCellValue("A{$row}", __('admin/dashboard.conversion_data'));
        $row++;
        $sheet->setCellValue("A{$row}", __('admin/dashboard.conversion_type'));
        $sheet->setCellValue("B{$row}", __('admin/dashboard.current_conversion_rate'));
        $sheet->setCellValue("C{$row}", __('admin/dashboard.previous_conversion_rate'));
        $sheet->setCellValue("D{$row}", __('admin/dashboard.change'));
        $row++;

        foreach ($stats['conversion'] as $type => $conversion) {
            $typeLabel = $this->getConversionTypeLabel($type);
            $sheet->setCellValue("A{$row}", $typeLabel);
            $sheet->setCellValue("B{$row}", $conversion['rate'] . '%');
            $sheet->setCellValue("C{$row}", $conversion['previous_rate'] . '%');
            $sheet->setCellValue("D{$row}", ($conversion['change'] >= 0 ? '+' : '') . $conversion['change'] . '%');
            $row++;
        }

        // 设置样式
        $this->applySheetStyles($sheet, 'A1:E' . ($row - 1));
    }

    /**
     * 创建趋势数据工作表
     */
    private function createTrendsSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, array $trends, string $timeRange): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(__('admin/dashboard.visitor_trend'));

        // 设置标题
        $sheet->setCellValue('A1', __('admin/dashboard.visitor_trend') . ' - ' . $this->getTimeRangeLabel($timeRange));
        $sheet->setCellValue('A2', __('admin/dashboard.time'));
        $sheet->setCellValue('B2', __('admin/dashboard.pv') . ' (' . __('admin/dashboard.page_views') . ')');
        $sheet->setCellValue('C2', __('admin/dashboard.uv') . ' (' . __('admin/dashboard.unique_visitors') . ')');
        $sheet->setCellValue('D2', __('admin/dashboard.pv_uv_ratio'));
        $sheet->setCellValue('E2', __('admin/dashboard.paid_orders'));
        $sheet->setCellValue('F2', __('admin/dashboard.gross_sales'));

        // 填充数据
        $row = 3;
        for ($i = 0; $i < count($trends['labels']); $i++) {
            $sheet->setCellValue("A{$row}", $trends['labels'][$i]);
            $sheet->setCellValue("B{$row}", $trends['pv'][$i]);
            $sheet->setCellValue("C{$row}", $trends['uv'][$i]);
            // 计算PV/UV比例
            $ratio = $trends['uv'][$i] > 0 ? round($trends['pv'][$i] / $trends['uv'][$i], 2) : 0;
            $sheet->setCellValue("D{$row}", $ratio);
            $sheet->setCellValue("E{$row}", $trends['orders'][$i] ?? 0);
            $sheet->setCellValue("F{$row}", $trends['revenue'][$i] ?? 0);
            $row++;
        }

        // 添加汇总数据
        $row++;
        $sheet->setCellValue("A{$row}", __('admin/dashboard.summary'));
        $sheet->setCellValue("B{$row}", array_sum($trends['pv']));
        $sheet->setCellValue("C{$row}", array_sum($trends['uv']));
        $totalRatio = array_sum($trends['uv']) > 0 ? round(array_sum($trends['pv']) / array_sum($trends['uv']), 2) : 0;
        $sheet->setCellValue("D{$row}", $totalRatio);

        // 设置样式
        $this->applySheetStyles($sheet, 'A1:F' . $row);
    }

    /**
     * 创建来源分析工作表
     */
    private function createSourceAnalysisSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, array $sourceAnalysis, string $timeRange): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(__('admin/dashboard.customer_source_analysis'));

        // 设置标题
        $sheet->setCellValue('A1', __('admin/dashboard.customer_source_analysis') . ' - ' . $this->getTimeRangeLabel($timeRange));
        $sheet->setCellValue('A2', __('admin/dashboard.source_type'));
        $sheet->setCellValue('B2', __('admin/dashboard.visits'));
        $sheet->setCellValue('C2', __('admin/dashboard.percentage'));
        $sheet->setCellValue('D2', __('admin/dashboard.rank'));

        // 填充数据
        $row         = 3;
        $totalVisits = array_sum(array_column($sourceAnalysis, 'value'));
        foreach ($sourceAnalysis as $index => $source) {
            $sheet->setCellValue("A{$row}", $source['name']);
            $sheet->setCellValue("B{$row}", $source['value']);
            $sheet->setCellValue("C{$row}", $source['percentage'] . '%');
            $sheet->setCellValue("D{$row}", $index + 1);
            $row++;
        }

        // 添加汇总数据
        $row++;
        $sheet->setCellValue("A{$row}", __('admin/dashboard.total'));
        $sheet->setCellValue("B{$row}", $totalVisits);
        $sheet->setCellValue("C{$row}", '100%');
        $sheet->setCellValue("D{$row}", '-');

        // 设置样式
        $this->applySheetStyles($sheet, 'A1:D' . $row);
    }

    /**
     * 创建漏斗数据工作表
     */
    private function createFunnelSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, array $funnel, string $timeRange): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(__('admin/dashboard.order_funnel'));

        // 设置标题
        $sheet->setCellValue('A1', __('admin/dashboard.order_funnel') . ' - ' . $this->getTimeRangeLabel($timeRange));
        $sheet->setCellValue('A2', __('admin/dashboard.conversion_stage'));
        $sheet->setCellValue('B2', __('admin/dashboard.user_count'));
        $sheet->setCellValue('C2', __('admin/dashboard.conversion_rate'));
        $sheet->setCellValue('D2', __('admin/dashboard.loss_rate'));
        $sheet->setCellValue('E2', __('admin/dashboard.funnel_width'));

        // 填充数据
        $row           = 3;
        $previousValue = null;
        foreach ($funnel as $index => $step) {
            $sheet->setCellValue("A{$row}", $step['name']);
            $sheet->setCellValue("B{$row}", $step['value']);
            $sheet->setCellValue("C{$row}", $step['percentage'] . '%');

            // 计算流失率
            if ($previousValue !== null && $previousValue > 0) {
                $lossRate = round((($previousValue - $step['value']) / $previousValue) * 100, 1);
                $sheet->setCellValue("D{$row}", $lossRate . '%');
            } else {
                $sheet->setCellValue("D{$row}", '-');
            }

            $sheet->setCellValue("E{$row}", $step['width'] . '%');
            $previousValue = $step['value'];
            $row++;
        }

        // 设置样式
        $this->applySheetStyles($sheet, 'A1:E' . ($row - 1));
    }

    /**
     * 创建商品排行工作表
     */
    private function createProductsSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, array $hotProducts, array $visitorCountries, string $timeRange): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(__('admin/dashboard.hot_products'));

        // 热销商品
        $sheet->setCellValue('A1', __('admin/dashboard.hot_products_ranking') . ' - ' . $this->getTimeRangeLabel($timeRange));
        $sheet->setCellValue('A2', __('admin/dashboard.ranking'));
        $sheet->setCellValue('B2', __('admin/dashboard.product_name'));
        $sheet->setCellValue('C2', __('admin/dashboard.price'));
        $sheet->setCellValue('D2', __('admin/dashboard.sales'));
        $sheet->setCellValue('E2', __('admin/dashboard.percentage'));
        $sheet->setCellValue('F2', __('admin/dashboard.sales_amount'));

        $row           = 3;
        $totalHotSales = 0;
        foreach ($hotProducts as $index => $product) {
            $sheet->setCellValue("A{$row}", $index + 1);
            $sheet->setCellValue("B{$row}", $product['name']);
            $sheet->setCellValue("C{$row}", $product['price']);
            $sheet->setCellValue("D{$row}", $product['total_sold']);
            $sheet->setCellValue("E{$row}", $product['percentage'] . '%');

            // Use the historical order-line amount, not a formatted current
            // catalogue price. Prices can change after an order is paid.
            $sales = (float) ($product['sales_amount'] ?? 0);
            $totalHotSales += $sales;
            $sheet->setCellValue("F{$row}", currency_format($sales));
            $row++;
        }

        // 热销商品汇总
        $row++;
        $sheet->setCellValue("A{$row}", __('admin/dashboard.hot_products_summary'));
        $sheet->setCellValue("D{$row}", array_sum(array_column($hotProducts, 'total_sold')));
        $sheet->setCellValue("F{$row}", currency_format($totalHotSales));

        // 访问者国家排名
        $row += 2;
        $sheet->setCellValue("A{$row}", __('admin/dashboard.visitor_country_ranking') . ' - ' . $this->getTimeRangeLabel($timeRange));
        $row++;
        $sheet->setCellValue("A{$row}", __('admin/dashboard.ranking'));
        $sheet->setCellValue("B{$row}", __('admin/dashboard.country'));
        $sheet->setCellValue("C{$row}", __('admin/dashboard.visits'));
        $sheet->setCellValue("D{$row}", __('admin/dashboard.percentage'));
        $row++;

        foreach ($visitorCountries as $country) {
            $sheet->setCellValue("A{$row}", $country['rank']);
            $sheet->setCellValue("B{$row}", $country['name'] . ' (' . $country['code'] . ')');
            $sheet->setCellValue("C{$row}", $country['visitors']);
            $sheet->setCellValue("D{$row}", $country['percentage'] . '%');
            $row++;
        }

        // 已识别国家/地区的访客汇总
        $row++;
        $sheet->setCellValue("A{$row}", __('admin/dashboard.total'));
        $sheet->setCellValue("C{$row}", array_sum(array_column($visitorCountries, 'visitors')));

        // 设置样式
        $this->applySheetStyles($sheet, 'A1:F' . ($row - 1));
    }

    /**
     * 应用工作表样式
     */
    private function applySheetStyles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $range): void
    {
        // 设置标题样式
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF4472C4');
        $sheet->getStyle('A1')->getFont()->getColor()->setARGB('FFFFFFFF');

        // 设置表头样式
        $headerRange = 'A2:F2';
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFD9E2F3');

        // 自动调整列宽
        $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        foreach ($columns as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        // 添加边框
        $sheet->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFD0D0D0'));

        // 设置数据区域样式
        $dataRange = 'A3:' . substr($range, 1);
        $sheet->getStyle($dataRange)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        // 设置数字格式
        $sheet->getStyle($dataRange)->getNumberFormat()->setFormatCode('#,##0');
    }

    /**
     * 获取每日小型趋势图数据
     */
    private function getDailyMiniChartData(int $daysAgo = 0): array
    {
        $hours          = range(0, 23);
        $visitorData    = [];
        $cartData       = [];
        $purchaseData   = [];
        $revenueData    = [];
        $conversionData = [];

        $date            = $this->clock()->subDays($daysAgo);
        $range           = [$date->copy()->startOfDay(), $date->copy()->endOfDay()];
        $visitorBuckets  = $this->productViewBuckets($range, 'hour');
        $cartBuckets     = $this->cartVisitorBuckets($range, 'hour');
        $purchaseBuckets = $this->paidOrderBuckets($range, 'hour');

        foreach ($hours as $hour) {
            $key        = $date->copy()->hour($hour)->format('Y-m-d H');
            $visitor    = (int) data_get($visitorBuckets, "{$key}.uv_count", 0);
            $cart       = (int) data_get($cartBuckets, "{$key}.visitor_count", 0);
            $purchase   = (int) data_get($purchaseBuckets, "{$key}.purchase_count", 0);
            $revenue    = round((float) data_get($purchaseBuckets, "{$key}.revenue", 0), 2);
            $conversion = $this->conversionRate($purchase, $visitor);

            $visitorData[]    = $visitor;
            $cartData[]       = $cart;
            $purchaseData[]   = $purchase;
            $revenueData[]    = $revenue;
            $conversionData[] = $conversion;
        }

        return [
            'visitor'    => $visitorData,
            'cart'       => $cartData,
            'purchase'   => $purchaseData,
            'revenue'    => $revenueData,
            'conversion' => $conversionData,
        ];
    }

    /**
     * 获取每周小型趋势图数据
     */
    private function getRollingMiniChartData(int $daysCount): array
    {
        $days           = [];
        $visitorData    = [];
        $cartData       = [];
        $purchaseData   = [];
        $revenueData    = [];
        $conversionData = [];

        $range           = [$this->clock()->subDays($daysCount - 1)->startOfDay(), $this->clock()->endOfDay()];
        $visitorBuckets  = $this->productViewBuckets($range, 'day');
        $cartBuckets     = $this->cartVisitorBuckets($range, 'day');
        $purchaseBuckets = $this->paidOrderBuckets($range, 'day');

        for ($i = $daysCount - 1; $i >= 0; $i--) {
            $date       = $this->clock()->subDays($i);
            $days[]     = $date->format('m/d');
            $key        = $date->format('Y-m-d');
            $visitor    = (int) data_get($visitorBuckets, "{$key}.uv_count", 0);
            $cart       = (int) data_get($cartBuckets, "{$key}.visitor_count", 0);
            $purchase   = (int) data_get($purchaseBuckets, "{$key}.purchase_count", 0);
            $revenue    = round((float) data_get($purchaseBuckets, "{$key}.revenue", 0), 2);
            $conversion = $this->conversionRate($purchase, $visitor);

            $visitorData[]    = $visitor;
            $cartData[]       = $cart;
            $purchaseData[]   = $purchase;
            $revenueData[]    = $revenue;
            $conversionData[] = $conversion;
        }

        return [
            'visitor'    => $visitorData,
            'cart'       => $cartData,
            'purchase'   => $purchaseData,
            'revenue'    => $revenueData,
            'conversion' => $conversionData,
        ];
    }

    /**
     * 获取小时访问量倍数
     */
    private function getHourMultiplier(int $hour): float
    {
        // 模拟一天中的访问模式
        if ($hour >= 9 && $hour <= 11) {
            return 1.5; // 上午高峰
        } elseif ($hour >= 14 && $hour <= 16) {
            return 1.3; // 下午高峰
        } elseif ($hour >= 19 && $hour <= 22) {
            return 1.8; // 晚上高峰
        }

        return 1.0; // 正常时间

    }

    /**
     * 获取星期访问量倍数
     */
    private function getDayMultiplier(int $dayOfWeek): float
    {
        // 模拟一周中的访问模式
        if ($dayOfWeek === 0 || $dayOfWeek === 6) {
            return 1.2; // 周末
        }

        return 1.0; // 工作日

    }

    /**
     * 获取趋势数据的日期倍数
     */
    private function getDayMultiplierForTrend(int $daysAgo): float
    {
        // 让不同天数的数据有明显差异
        switch ($daysAgo) {
            case 0: // 今日
                return 1.0;
            case 1: // 昨日
                return 0.8; // 昨日数据比今日少20%
            case 2: // 前天
                return 0.9;
            case 3: // 3天前
                return 0.85;
            case 4: // 4天前
                return 0.95;
            case 5: // 5天前
                return 0.88;
            case 6: // 6天前
                return 0.92;
            default:
                return 1.0;
        }
    }

    /**
     * 获取日期范围
     */
    private function getDateRange(string $timeRange): array
    {
        $this->validateTimeRange($timeRange);

        switch ($timeRange) {
            case 'month':
                return [
                    $this->clock()->subDays(29)->startOfDay(),
                    $this->clock(),
                ];
            case 'yesterday':
                return [
                    $this->clock()->subDay()->startOfDay(),
                    $this->clock()->subDay()->endOfDay(),
                ];
            case 'week':
                // 近7日：包含今天在内的过去7天
                return [
                    $this->clock()->subDays(6)->startOfDay(),
                    $this->clock(),
                ];
            case 'today':
            default:
                return [
                    $this->clock()->startOfDay(),
                    $this->clock(),
                ];
        }
    }

    /**
     * 获取上一周期的日期范围
     */
    private function getPreviousDateRange(string $timeRange): array
    {
        $this->validateTimeRange($timeRange);

        switch ($timeRange) {
            case 'month':
                return [
                    $this->clock()->subDays(59)->startOfDay(),
                    $this->clock()->subDays(30),
                ];
            case 'yesterday':
                // 前天的数据
                return [
                    $this->clock()->subDays(2)->startOfDay(),
                    $this->clock()->subDays(2)->endOfDay(),
                ];
            case 'week':
                // 前一周的7天数据（第8-14天前）
                return [
                    $this->clock()->subDays(13)->startOfDay(),
                    $this->clock()->subDays(7),
                ];
            case 'today':
            default:
                // 昨天的数据
                return [
                    $this->clock()->subDay()->startOfDay(),
                    $this->clock()->subDay(),
                ];
        }
    }

    /**
     * Count distinct visitor identities while supporting both members and guests.
     */
    private function countUniqueVisitors(array $dateRange): int
    {
        return $this->memoizeRange('unique_visitors', $dateRange, function () use ($dateRange): int {
            $visitors = $this->unifiedVisitorIdentityQuery($dateRange);
            if ($visitors === null) {
                return 0;
            }

            return DB::query()->fromSub($visitors, 'dashboard_unique_visitors')->count();
        });
    }

    private function countUniqueProductViewVisitors(array $dateRange): int
    {
        if (! $this->productViewsAvailable()) {
            return 0;
        }

        $visitorKey = $this->productViewVisitorKeyExpression();
        $visitors   = ProductView::humanConfirmed()
            ->whereBetween('created_at', $dateRange)
            ->selectRaw("{$visitorKey} AS visitor_key")
            ->groupByRaw($visitorKey);

        return DB::query()->fromSub($visitors, 'dashboard_product_viewers')->count();
    }

    /**
     * Count distinct visitors that created cart items in the selected period.
     */
    private function countUniqueCartVisitors(array $dateRange): int
    {
        return $this->memoizeRange('cart_visitors', $dateRange, function () use ($dateRange): int {
            $model = $this->dashboardEventsAvailable()
                ? DashboardEvent::query()->where('event_type', 'cart_added')
                : CartProduct::query();

            $visitorKey = $this->cartVisitorKeyExpression($this->dashboardEventsAvailable());

            $uniqueCartVisitors = $model
                ->whereBetween('created_at', $dateRange)
                ->selectRaw("{$visitorKey} AS visitor_key")
                ->groupByRaw($visitorKey);

            return DB::query()->fromSub($uniqueCartVisitors, 'dashboard_unique_cart_visitors')->count();
        });
    }

    /**
     * The event log preserves cart activity after checkout clears cart rows.
     * During a rolling upgrade the old cart table remains a safe read-only fallback.
     */
    private function dashboardEventsAvailable(): bool
    {
        if ($this->dashboardEventsAvailable === null) {
            $this->dashboardEventsAvailable = Schema::hasTable('dashboard_events');
        }

        return $this->dashboardEventsAvailable;
    }

    private function productViewsAvailable(): bool
    {
        if ($this->productViewsAvailable === null) {
            $this->productViewsAvailable = Schema::hasTable('product_views')
                && Schema::hasColumn('product_views', 'created_at');
        }

        return $this->productViewsAvailable;
    }

    private function productViewRefererAvailable(): bool
    {
        if ($this->productViewRefererAvailable === null) {
            $this->productViewRefererAvailable = $this->productViewsAvailable()
                && Schema::hasColumn('product_views', 'referer');
        }

        return $this->productViewRefererAvailable;
    }

    private function productViewCountryAvailable(): bool
    {
        if ($this->productViewCountryAvailable === null) {
            $this->productViewCountryAvailable = $this->productViewsAvailable()
                && Schema::hasColumn('product_views', 'country_code');
        }

        return $this->productViewCountryAvailable;
    }

    private function productViewVisitorKeyAvailable(): bool
    {
        if ($this->productViewVisitorKeyAvailable === null) {
            $this->productViewVisitorKeyAvailable = $this->productViewsAvailable()
                && Schema::hasColumn('product_views', 'visitor_key');
        }

        return $this->productViewVisitorKeyAvailable;
    }

    private function storefrontVisitsAvailable(): bool
    {
        if ($this->storefrontVisitsAvailable === null) {
            $this->storefrontVisitsAvailable = Schema::hasTable('storefront_visits')
                && Schema::hasColumn('storefront_visits', 'bucket_at')
                && Schema::hasColumn('storefront_visits', 'visitor_key');
        }

        return $this->storefrontVisitsAvailable;
    }

    private function storefrontVisitsInRange(array $dateRange): bool
    {
        // Schema availability only says that the new ledger can be queried;
        // it does not mean this period contains verified traffic. Confirmed
        // product views remain the rolling-upgrade fallback when the general
        // visit ledger has not started collecting the selected period yet.
        return $this->memoizeRange('visit_ledger_available', $dateRange, fn (): bool => $this->storefrontVisitsAvailable()
            && $this->storefrontVisitQuery()->whereBetween('bucket_at', $dateRange)->exists());
    }

    private function storefrontVisitConfirmationAvailable(): bool
    {
        if ($this->storefrontVisitConfirmationAvailable === null) {
            $this->storefrontVisitConfirmationAvailable = $this->storefrontVisitsAvailable()
                && Schema::hasColumn('storefront_visits', 'human_confirmed_at');
        }

        return $this->storefrontVisitConfirmationAvailable;
    }

    private function storefrontVisitQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = StorefrontVisit::query();

        if ($this->storefrontVisitConfirmationAvailable()) {
            $query->whereNotNull('human_confirmed_at');
        }

        return $query;
    }

    private function unifiedVisitorIdentityQuery(array $dateRange): \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder|null
    {
        $queries = [];

        if ($this->storefrontVisitsAvailable()) {
            $identity  = $this->storefrontVisitIdentityExpression();
            $queries[] = $this->storefrontVisitQuery()
                ->whereBetween('bucket_at', $dateRange)
                ->selectRaw("{$identity} AS visitor_identity")
                ->groupByRaw($identity);
        }

        if ($this->productViewsAvailable()) {
            $identity  = $this->productViewVisitorKeyExpression();
            $queries[] = ProductView::humanConfirmed()
                ->whereBetween('created_at', $dateRange)
                ->selectRaw("{$identity} AS visitor_identity")
                ->groupByRaw($identity);
        }

        $query = array_shift($queries);
        if ($query === null) {
            return null;
        }

        foreach ($queries as $additionalQuery) {
            $query->union($additionalQuery);
        }

        return $query;
    }

    private function confirmedStorefrontVisitorIdentities(array $dateRange): ?\Illuminate\Database\Eloquent\Builder
    {
        if (! $this->storefrontVisitsAvailable()) {
            return null;
        }

        $identity = $this->storefrontVisitIdentityExpression();

        return $this->storefrontVisitQuery()
            ->whereBetween('bucket_at', $dateRange)
            ->selectRaw("{$identity} AS visitor_identity")
            ->groupByRaw($identity);
    }

    private function productViewsWithoutConfirmedVisit(array $dateRange): \Illuminate\Database\Eloquent\Builder
    {
        $query  = ProductView::humanConfirmed()->whereBetween('created_at', $dateRange);
        $visits = $this->confirmedStorefrontVisitorIdentities($dateRange);

        if ($visits !== null) {
            $query->whereNotIn(DB::raw($this->productViewVisitorKeyExpression()), $visits);
        }

        return $query;
    }

    private function cartProductsSessionAvailable(): bool
    {
        if ($this->cartProductsSessionAvailable === null) {
            $this->cartProductsSessionAvailable = Schema::hasTable('cart_products')
                && Schema::hasColumn('cart_products', 'session_id');
        }

        return $this->cartProductsSessionAvailable;
    }

    private function productViewsInRange(array $dateRange): int
    {
        if (! $this->productViewsAvailable()) {
            return 0;
        }

        return ProductView::humanConfirmed()->whereBetween('created_at', $dateRange)->count();
    }

    private function noSourceData(): array
    {
        return [
            ['name' => __('admin/dashboard.no_data'), 'value' => 0, 'percentage' => 0.0],
        ];
    }

    /** @return array<string, int> */
    private function unifiedSourceCounts(array $dateRange): array
    {
        return $this->memoizeRange('source_counts', $dateRange, function () use ($dateRange): array {
            $sources = $this->storefrontVisitsInRange($dateRange)
                ? $this->storefrontSourceCounts($dateRange)
                : [];

            foreach ($this->legacyProductViewSourceCounts($dateRange, true) as $key => $count) {
                $sources[$key] = ($sources[$key] ?? 0) + $count;
            }

            return $sources;
        });
    }

    /** @return array<string, int> */
    private function storefrontSourceCounts(array $dateRange): array
    {
        // IDs are insertion order, not event order. Imports and delayed writes
        // can insert an older visit after a newer one, so first-touch
        // attribution must be selected by the captured timestamp.
        $visitorIdentity = $this->storefrontVisitIdentityExpression();
        $firstVisitTimes = $this->storefrontVisitQuery()
            ->whereBetween('bucket_at', $dateRange)
            ->selectRaw("{$visitorIdentity} AS visitor_identity")
            ->selectRaw('MIN(first_seen_at) AS first_seen_at')
            ->groupByRaw($visitorIdentity);

        // A deterministic ID tie-break prevents duplicate counting when an
        // import contains two rows with the same captured timestamp.
        $firstCandidateId       = $this->qualifiedColumn('first_visit_candidate', 'id');
        $firstCandidateIdentity = $this->storefrontVisitIdentityExpression('first_visit_candidate');
        $firstVisits            = DB::table('storefront_visits as first_visit_candidate')
            ->joinSub($firstVisitTimes, 'first_visit_time', function ($join) use ($firstCandidateIdentity): void {
                $join->on(DB::raw($firstCandidateIdentity), '=', 'first_visit_time.visitor_identity')
                    ->on('first_visit_candidate.first_seen_at', '=', 'first_visit_time.first_seen_at');
            })
            ->selectRaw("{$firstCandidateIdentity} AS visitor_identity")
            ->selectRaw("MIN({$firstCandidateId}) AS id")
            // The joined subquery also exposes a visitor_identity column.
            // MySQL resolves GROUP BY visitor_identity to that joined column
            // instead of this SELECT alias and rejects the candidate columns
            // under ONLY_FULL_GROUP_BY. Group by the complete expression so
            // the chosen candidate is deterministic on every SQL engine.
            ->groupByRaw($firstCandidateIdentity);
        if ($this->storefrontVisitConfirmationAvailable()) {
            $firstVisits->whereNotNull('first_visit_candidate.human_confirmed_at');
        }

        $sourceKey = $this->qualifiedColumn('source_visit', 'source_key');

        return DB::table('storefront_visits as source_visit')
            ->joinSub($firstVisits, 'first_source_visit', function ($join): void {
                $join->on('source_visit.id', '=', 'first_source_visit.id');
            })
            ->selectRaw("COALESCE(NULLIF({$sourceKey}, ''), 'direct') AS source_key, COUNT(*) AS visitor_count")
            ->groupBy('source_key')
            ->pluck('visitor_count', 'source_key')
            ->map(static fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * Rolling-upgrade fallback. Each visitor contributes once using their
     * first product view, instead of every refresh inflating the source chart.
     *
     * @return array<string, int>
     */
    private function legacyProductViewSourceCounts(array $dateRange, bool $excludeConfirmedVisits = false): array
    {
        if (! $this->productViewsAvailable()) {
            return [];
        }

        if (! $this->productViewRefererAvailable()) {
            $count = $this->countLegacyProductViewVisitors($dateRange, $excludeConfirmedVisits);

            return $count > 0 ? ['direct' => $count] : [];
        }

        $firstViews = ($excludeConfirmedVisits
            ? $this->productViewsWithoutConfirmedVisit($dateRange)
            : ProductView::humanConfirmed()->whereBetween('created_at', $dateRange))
            ->selectRaw('MIN(id) AS id')
            ->groupByRaw($this->productViewVisitorKeyExpression());
        $referer = $this->qualifiedColumn('source_view', 'referer');
        $rows    = DB::table('product_views as source_view')
            ->joinSub($firstViews, 'first_source_view', function ($join): void {
                $join->on('source_view.id', '=', 'first_source_view.id');
            })
            ->selectRaw("{$referer} AS referer, COUNT(*) AS visitor_count")
            ->groupBy(DB::raw($referer))
            ->get();

        $classifier      = new TrafficSourceService;
        $internalDomains = $this->internalStorefrontDomains();
        $sources         = [];
        foreach ($rows as $row) {
            $key           = $classifier->classifyReferer($row->referer, $internalDomains);
            $sources[$key] = ($sources[$key] ?? 0) + (int) $row->visitor_count;
        }

        return $sources;
    }

    private function countLegacyProductViewVisitors(array $dateRange, bool $excludeConfirmedVisits = false): int
    {
        $visitorKey = $this->productViewVisitorKeyExpression();
        $visitors   = ($excludeConfirmedVisits
            ? $this->productViewsWithoutConfirmedVisit($dateRange)
            : ProductView::humanConfirmed()->whereBetween('created_at', $dateRange))
            ->selectRaw("{$visitorKey} AS visitor_key")
            ->groupByRaw($visitorKey);

        return DB::query()->fromSub($visitors, 'legacy_source_visitors')->count();
    }

    private function sourceName(string $key): string
    {
        $translationKeys = [
            'direct'     => 'direct_access',
            'google'     => 'google',
            'baidu'      => 'baidu',
            'bing'       => 'bing',
            'yahoo'      => 'yahoo',
            'duckduckgo' => 'duckduckgo',
            'yandex'     => 'yandex',
            'facebook'   => 'facebook',
            'twitter'    => 'twitter',
            'youtube'    => 'youtube',
            'linkedin'   => 'linkedin',
            'instagram'  => 'instagram',
            'tiktok'     => 'tiktok',
            'pinterest'  => 'pinterest',
            'reddit'     => 'reddit',
            'email'      => 'email',
            'affiliate'  => 'affiliate',
            'other'      => 'other',
        ];

        return __('admin/dashboard.' . ($translationKeys[$key] ?? 'other'));
    }

    private function storefrontCountryRows(array $dateRange)
    {
        return $this->memoizeRange('country_rows', $dateRange, function () use ($dateRange) {
            // Country may be resolved after the first request. Use the actual last
            // seen timestamp rather than the greatest ID so delayed/backfilled
            // rows cannot overwrite a visitor's latest verified country.
            $visitorIdentity  = $this->storefrontVisitIdentityExpression();
            $latestVisitTimes = $this->storefrontVisitQuery()
                ->whereBetween('bucket_at', $dateRange)
                ->selectRaw("{$visitorIdentity} AS visitor_identity")
                ->selectRaw('MAX(last_seen_at) AS last_seen_at')
                ->groupByRaw($visitorIdentity);
            $latestCandidateId       = $this->qualifiedColumn('latest_visit_candidate', 'id');
            $latestCandidateIdentity = $this->storefrontVisitIdentityExpression('latest_visit_candidate');
            $latestVisits            = DB::table('storefront_visits as latest_visit_candidate')
                ->joinSub($latestVisitTimes, 'latest_visit_time', function ($join) use ($latestCandidateIdentity): void {
                    $join->on(DB::raw($latestCandidateIdentity), '=', 'latest_visit_time.visitor_identity')
                        ->on('latest_visit_candidate.last_seen_at', '=', 'latest_visit_time.last_seen_at');
                })
                ->selectRaw("{$latestCandidateIdentity} AS visitor_identity")
                ->selectRaw("MAX({$latestCandidateId}) AS id")
                // See storefrontSourceCounts(): the joined timestamp query
                // has the same alias, so grouping by the explicit identity
                // expression is required by MySQL's ONLY_FULL_GROUP_BY mode.
                ->groupByRaw($latestCandidateIdentity);
            if ($this->storefrontVisitConfirmationAvailable()) {
                $latestVisits->whereNotNull('latest_visit_candidate.human_confirmed_at');
            }
            $countryCode = $this->qualifiedColumn('country_visit', 'country_code');
            $country     = "COALESCE(NULLIF(UPPER({$countryCode}), ''), '')";

            return DB::table('storefront_visits as country_visit')
                ->joinSub($latestVisits, 'latest_country_visit', function ($join): void {
                    $join->on('country_visit.id', '=', 'latest_country_visit.id');
                })
                ->selectRaw("{$country} AS country_code, COUNT(*) AS visitors")
                ->groupByRaw($country)
                ->orderByDesc('visitors')
                ->orderBy('country_code')
                ->get();
        });
    }

    private function legacyProductViewCountryRows(array $dateRange, bool $excludeConfirmedVisits = false)
    {
        if (! $this->productViewCountryAvailable()) {
            return collect();
        }

        $latestViews = ($excludeConfirmedVisits
            ? $this->productViewsWithoutConfirmedVisit($dateRange)
            : ProductView::humanConfirmed()->whereBetween('created_at', $dateRange))
            ->selectRaw('MAX(id) AS id')
            ->groupByRaw($this->productViewVisitorKeyExpression());
        $countryCode = $this->qualifiedColumn('country_view', 'country_code');
        $country     = "COALESCE(NULLIF(UPPER({$countryCode}), ''), '')";

        return DB::table('product_views as country_view')
            ->joinSub($latestViews, 'latest_country_view', function ($join): void {
                $join->on('country_view.id', '=', 'latest_country_view.id');
            })
            ->selectRaw("{$country} AS country_code, COUNT(*) AS visitors")
            ->groupByRaw($country)
            ->orderByDesc('visitors')
            ->orderBy('country_code')
            ->get();
    }

    private function unifiedCountryRows(array $dateRange)
    {
        return $this->memoizeRange('unified_country_rows', $dateRange, function () use ($dateRange) {
            $counts = [];
            $rows   = $this->storefrontVisitsInRange($dateRange)
                ? $this->storefrontCountryRows($dateRange)
                : collect();

            foreach ($rows as $row) {
                $code          = strtoupper((string) $row->country_code);
                $counts[$code] = ($counts[$code] ?? 0) + (int) $row->visitors;
            }

            foreach ($this->legacyProductViewCountryRows($dateRange, true) as $row) {
                $code          = strtoupper((string) $row->country_code);
                $counts[$code] = ($counts[$code] ?? 0) + (int) $row->visitors;
            }

            return collect($counts)
                ->map(static fn (int $visitors, string $countryCode): object => (object) [
                    'country_code' => $countryCode,
                    'visitors'     => $visitors,
                ])
                ->sort(static fn (object $left, object $right): int => ($right->visitors <=> $left->visitors)
                    ?: ($left->country_code <=> $right->country_code))
                ->values();
        });
    }

    private function cartAddsInRange(array $dateRange): int
    {
        if ($this->dashboardEventsAvailable()) {
            return (int) DashboardEvent::query()
                ->where('event_type', 'cart_added')
                ->whereBetween('created_at', $dateRange)
                ->sum('quantity');
        }

        return (int) CartProduct::query()->whereBetween('created_at', $dateRange)->sum('quantity');
    }

    /**
     * Uses the same identity precedence as visitor reporting. The explicit
     * fallbacks preserve meaningful counts for stateless payment callbacks.
     */
    private function paymentInformationVisitorKeyExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql'  => "CASE WHEN customer_id IS NOT NULL AND customer_id > 0 THEN 'customer:' || customer_id::text ELSE COALESCE(NULLIF(session_id, ''), NULLIF(ip, ''), 'payment:' || id::text) END",
            'sqlite' => "CASE WHEN customer_id IS NOT NULL AND customer_id > 0 THEN 'customer:' || CAST(customer_id AS TEXT) ELSE COALESCE(NULLIF(session_id, ''), NULLIF(ip, ''), 'payment:' || CAST(id AS TEXT)) END",
            default  => "CASE WHEN customer_id IS NOT NULL AND customer_id > 0 THEN CONCAT('customer:', customer_id) ELSE COALESCE(NULLIF(session_id, ''), NULLIF(ip, ''), CONCAT('payment:', id)) END",
        };
    }

    private function cartVisitorKeyExpression(bool $isDashboardEvent): string
    {
        $ipFallback = $isDashboardEvent ? "NULLIF(ip, ''), " : '';

        return match (DB::connection()->getDriverName()) {
            'pgsql'  => "CASE WHEN customer_id IS NOT NULL AND customer_id > 0 THEN 'customer:' || customer_id::text ELSE COALESCE(NULLIF(session_id, ''), {$ipFallback}'cart:' || id::text) END",
            'sqlite' => "CASE WHEN customer_id IS NOT NULL AND customer_id > 0 THEN 'customer:' || CAST(customer_id AS TEXT) ELSE COALESCE(NULLIF(session_id, ''), {$ipFallback}'cart:' || CAST(id AS TEXT)) END",
            default  => "CASE WHEN customer_id IS NOT NULL AND customer_id > 0 THEN CONCAT('customer:', customer_id) ELSE COALESCE(NULLIF(session_id, ''), {$ipFallback}CONCAT('cart:', id)) END",
        };
    }

    private function storefrontVisitIdentityExpression(?string $table = null): string
    {
        $visitorKey = $table ? $this->qualifiedColumn($table, 'visitor_key') : 'visitor_key';

        // A login enriches the same browser visit; it must not turn one guest
        // into a second visitor. The one-way session key stays stable across
        // that transition and intentionally represents a visitor, not an
        // account shared by several devices.
        return match (DB::connection()->getDriverName()) {
            'pgsql', 'sqlite' => "'visitor:' || {$visitorKey}",
            default            => "CONCAT('visitor:', {$visitorKey})",
        };
    }

    private function productViewVisitorKeyExpression(?string $table = null): string
    {
        $customerId = $table ? $this->qualifiedColumn($table, 'customer_id') : 'customer_id';
        $sessionId  = $table ? $this->qualifiedColumn($table, 'session_id') : 'session_id';
        $ip         = $table ? $this->qualifiedColumn($table, 'ip') : 'ip';
        $id         = $table ? $this->qualifiedColumn($table, 'id') : 'id';
        $visitorKey = $this->productViewVisitorKeyAvailable()
            ? ($table ? $this->qualifiedColumn($table, 'visitor_key') : 'visitor_key')
            : null;

        return match (DB::connection()->getDriverName()) {
            'pgsql'  => 'COALESCE(' . ($visitorKey ? "'visitor:' || NULLIF({$visitorKey}, ''), " : '') . "CASE WHEN {$customerId} IS NOT NULL AND {$customerId} > 0 THEN 'customer:' || {$customerId}::text END, 'session:' || NULLIF({$sessionId}, ''), 'ip:' || NULLIF({$ip}, ''), 'view:' || {$id}::text)",
            'sqlite' => 'COALESCE(' . ($visitorKey ? "'visitor:' || NULLIF({$visitorKey}, ''), " : '') . "CASE WHEN {$customerId} IS NOT NULL AND {$customerId} > 0 THEN 'customer:' || CAST({$customerId} AS TEXT) END, 'session:' || NULLIF({$sessionId}, ''), 'ip:' || NULLIF({$ip}, ''), 'view:' || CAST({$id} AS TEXT))",
            default  => 'COALESCE(' . ($visitorKey ? "CONCAT('visitor:', NULLIF({$visitorKey}, '')), " : '') . "CASE WHEN {$customerId} IS NOT NULL AND {$customerId} > 0 THEN CONCAT('customer:', {$customerId}) END, CONCAT('session:', NULLIF({$sessionId}, '')), CONCAT('ip:', NULLIF({$ip}, '')), CONCAT('view:', {$id}))",
        };
    }

    private function countUniquePaymentInformationVisitors(array $dateRange): int
    {
        if (! $this->dashboardEventsAvailable()) {
            return 0;
        }

        $visitorKey = $this->paymentInformationVisitorKeyExpression();
        $visitors   = DashboardEvent::query()
            ->where('event_type', 'payment_information_added')
            ->whereBetween('created_at', $dateRange)
            ->selectRaw("{$visitorKey} AS visitor_key")
            ->groupByRaw($visitorKey);

        return DB::query()->fromSub($visitors, 'dashboard_payment_information_visitors')->count();
    }

    /**
     * 获取访客统计
     */
    private function getVisitorStats(array $dateRange, array $previousDateRange): array
    {
        $current  = $this->countUniqueVisitors($dateRange);
        $previous = $this->countUniqueVisitors($previousDateRange);

        $percentage = $this->calculatePercentage($current, $previous);

        return [
            'current'    => $current,
            'previous'   => $previous,
            'percentage' => $percentage,
            'trend'      => $percentage >= 0 ? 'up' : 'down',
        ];
    }

    /**
     * 获取加购统计 - 基于真实购物车商品创建记录计算
     */
    private function getCartStats(array $dateRange, array $previousDateRange): array
    {
        $current  = $this->countUniqueCartVisitors($dateRange);
        $previous = $this->countUniqueCartVisitors($previousDateRange);

        $percentage = $this->calculatePercentage($current, $previous);

        return [
            'current'    => $current,
            'previous'   => $previous,
            'percentage' => $percentage,
            'trend'      => $percentage >= 0 ? 'up' : 'down',
        ];
    }

    /**
     * 获取购买统计
     */
    private function getPurchaseStats(array $dateRange, array $previousDateRange): array
    {
        $current  = $this->countPaidPurchasersInRange($dateRange);
        $previous = $this->countPaidPurchasersInRange($previousDateRange);

        $percentage = $this->calculatePercentage($current, $previous);

        return [
            'current'    => $current,
            'previous'   => $previous,
            'percentage' => $percentage,
            'trend'      => $percentage >= 0 ? 'up' : 'down',
        ];
    }

    private function getPaymentInformationStats(array $dateRange, array $previousDateRange): array
    {
        $current  = $this->countUniquePaymentInformationVisitors($dateRange);
        $previous = $this->countUniquePaymentInformationVisitors($previousDateRange);

        $percentage = $this->calculatePercentage($current, $previous);

        return [
            'current'    => $current,
            'previous'   => $previous,
            'percentage' => $percentage,
            'trend'      => $percentage >= 0 ? 'up' : 'down',
        ];
    }

    private function getCommerceStats(array $dateRange, array $previousDateRange): array
    {
        $currentOrders   = $this->countPaidOrdersInRange($dateRange);
        $previousOrders  = $this->countPaidOrdersInRange($previousDateRange);
        $currentRevenue  = $this->paidRevenueInRange($dateRange);
        $previousRevenue = $this->paidRevenueInRange($previousDateRange);
        $currentAverage  = $currentOrders   > 0 ? round($currentRevenue / $currentOrders, 2) : 0.0;
        $previousAverage = $previousOrders  > 0 ? round($previousRevenue / $previousOrders, 2) : 0.0;

        return [
            'paid_orders'         => $this->metricComparison($currentOrders, $previousOrders),
            'revenue'             => $this->metricComparison($currentRevenue, $previousRevenue),
            'average_order_value' => $this->metricComparison($currentAverage, $previousAverage),
        ];
    }

    private function paidRevenueInRange(array $dateRange): float
    {
        $total = $this->qualifiedColumn('dashboard_paid_revenue', 'order_total');

        return round((float) DB::query()
            ->fromSub($this->paidOrdersSubquery($dateRange), 'dashboard_paid_revenue')
            ->sum(DB::raw($total)), 2);
    }

    private function metricComparison(float|int $current, float|int $previous): array
    {
        $percentage = $this->calculatePercentage((float) $current, (float) $previous);

        return [
            'current'    => $current,
            'previous'   => $previous,
            'percentage' => $percentage,
            'trend'      => $percentage >= 0 ? 'up' : 'down',
        ];
    }

    /**
     * 获取转化率统计
     */
    private function getConversionStats(array $visitorStats, array $cartStats, array $purchaseStats, array $paymentInfoStats): array
    {
        $types = [
            'visitor-to-purchase' => [
                'rate'          => $this->conversionRate($purchaseStats['current'], $visitorStats['current']),
                'previous_rate' => $this->conversionRate($purchaseStats['previous'], $visitorStats['previous']),
            ],
            'visitor-to-cart' => [
                'rate'          => $this->conversionRate($cartStats['current'], $visitorStats['current']),
                'previous_rate' => $this->conversionRate($cartStats['previous'], $visitorStats['previous']),
            ],
            'visitor-to-payment-information' => [
                'rate'          => $this->conversionRate($paymentInfoStats['current'], $visitorStats['current']),
                'previous_rate' => $this->conversionRate($paymentInfoStats['previous'], $visitorStats['previous']),
            ],
            'cart-to-purchase' => [
                'rate'          => $this->conversionRate($purchaseStats['current'], $cartStats['current']),
                'previous_rate' => $this->conversionRate($purchaseStats['previous'], $cartStats['previous']),
            ],
        ];

        foreach ($types as $key => &$type) {
            $change         = $type['rate'] - $type['previous_rate'];
            $type['change'] = round($change, 1);
            $type['trend']  = $change >= 0 ? 'up' : 'down';
        }

        return $types;
    }

    /**
     * 获取每日趋势数据
     */
    private function getDailyTrends(int $daysAgo = 0): array
    {
        $date  = $this->clock()->subDays($daysAgo);
        $hours = range(0, 23);

        $pvData      = [];
        $uvData      = [];
        $range       = [$date->copy()->startOfDay(), $date->copy()->endOfDay()];
        $buckets     = $this->productViewBuckets($range, 'hour');
        $orders      = $this->paidOrderBuckets($range, 'hour');
        $orderData   = [];
        $revenueData = [];

        foreach ($hours as $hour) {
            $key           = $date->copy()->hour($hour)->format('Y-m-d H');
            $pvData[]      = (int) data_get($buckets, "{$key}.pv_count", 0);
            $uvData[]      = (int) data_get($buckets, "{$key}.uv_count", 0);
            $orderData[]   = (int) data_get($orders, "{$key}.purchase_count", 0);
            $revenueData[] = round((float) data_get($orders, "{$key}.revenue", 0), 2);
        }

        return [
            'labels'  => array_map(fn ($h) => sprintf('%02d:00', $h), $hours),
            'pv'      => $pvData,
            'uv'      => $uvData,
            'orders'  => $orderData,
            'revenue' => $revenueData,
        ];
    }

    /**
     * 获取周趋势数据
     */
    private function getRollingTrends(int $daysCount): array
    {
        $days   = [];
        $pvData = [];
        $uvData = [];

        $range   = [$this->clock()->subDays($daysCount - 1)->startOfDay(), $this->clock()->endOfDay()];
        $buckets = $this->productViewBuckets($range, 'day');
        $orders  = $this->paidOrderBuckets($range, 'day');

        $orderData   = [];
        $revenueData = [];

        for ($i = $daysCount - 1; $i >= 0; $i--) {
            $date          = $this->clock()->subDays($i);
            $days[]        = $date->format('m/d');
            $key           = $date->format('Y-m-d');
            $pvData[]      = (int) data_get($buckets, "{$key}.pv_count", 0);
            $uvData[]      = (int) data_get($buckets, "{$key}.uv_count", 0);
            $orderData[]   = (int) data_get($orders, "{$key}.purchase_count", 0);
            $revenueData[] = round((float) data_get($orders, "{$key}.revenue", 0), 2);
        }

        return [
            'labels'  => $days,
            'pv'      => $pvData,
            'uv'      => $uvData,
            'orders'  => $orderData,
            'revenue' => $revenueData,
        ];
    }

    /**
     * Aggregate PV and UV in one query instead of issuing one query per chart
     * point. This keeps dashboard latency predictable as traffic grows.
     */
    private function productViewBuckets(array $dateRange, string $granularity): array
    {
        $buckets = [];

        if ($this->storefrontVisitsAvailable()) {
            $bucketAt = $this->qualifiedColumn('storefront_visits', 'bucket_at');
            $bucket   = $this->timeBucketExpression($bucketAt, $granularity);
            $rows     = $this->storefrontVisitQuery()
                ->whereBetween('bucket_at', $dateRange)
                ->selectRaw("{$bucket} AS bucket, SUM(page_views) AS pv_count")
                ->groupBy(DB::raw($bucket))
                ->get();

            foreach ($rows as $row) {
                $buckets[(string) $row->bucket] = [
                    'pv_count' => (int) $row->pv_count,
                    'uv_count' => 0,
                ];
            }
        }

        if ($this->productViewsAvailable()) {
            $createdAt = $this->qualifiedColumn('product_views', 'created_at');
            $bucket    = $this->timeBucketExpression($createdAt, $granularity);
            $rows      = ProductView::humanConfirmed()
                ->whereBetween('created_at', $dateRange)
                ->selectRaw("{$bucket} AS bucket, COUNT(*) AS pv_count")
                ->groupBy(DB::raw($bucket))
                ->get();

            foreach ($rows as $row) {
                $key = (string) $row->bucket;
                $buckets[$key] ??= ['pv_count' => 0, 'uv_count' => 0];
                // Product pages are also HTML page views. Taking the larger
                // ledger avoids both dropping product-only visitors and
                // counting the same confirmed page twice.
                $buckets[$key]['pv_count'] = max($buckets[$key]['pv_count'], (int) $row->pv_count);
            }
        }

        foreach ($this->unifiedVisitorBuckets($dateRange, $granularity) as $key => $visitorCount) {
            $buckets[$key] ??= ['pv_count' => 0, 'uv_count' => 0];
            $buckets[$key]['uv_count'] = $visitorCount;
            $buckets[$key]['pv_count'] = max($buckets[$key]['pv_count'], $visitorCount);
        }

        return $buckets;
    }

    /** @return array<string, int> */
    private function unifiedVisitorBuckets(array $dateRange, string $granularity): array
    {
        $queries = [];

        if ($this->storefrontVisitsAvailable()) {
            $bucketAt  = $this->qualifiedColumn('storefront_visits', 'bucket_at');
            $bucket    = $this->timeBucketExpression($bucketAt, $granularity);
            $identity  = $this->storefrontVisitIdentityExpression();
            $queries[] = $this->storefrontVisitQuery()
                ->whereBetween('bucket_at', $dateRange)
                ->selectRaw("{$bucket} AS bucket, {$identity} AS visitor_identity")
                ->groupByRaw("{$bucket}, {$identity}");
        }

        if ($this->productViewsAvailable()) {
            $createdAt = $this->qualifiedColumn('product_views', 'created_at');
            $bucket    = $this->timeBucketExpression($createdAt, $granularity);
            $identity  = $this->productViewVisitorKeyExpression();
            $queries[] = ProductView::humanConfirmed()
                ->whereBetween('created_at', $dateRange)
                ->selectRaw("{$bucket} AS bucket, {$identity} AS visitor_identity")
                ->groupByRaw("{$bucket}, {$identity}");
        }

        $union = array_shift($queries);
        if ($union === null) {
            return [];
        }

        foreach ($queries as $additionalQuery) {
            $union->union($additionalQuery);
        }

        $bucket = $this->qualifiedColumn('dashboard_visitor_buckets', 'bucket');

        return DB::query()
            ->fromSub($union, 'dashboard_visitor_buckets')
            ->selectRaw("{$bucket} AS bucket, COUNT(*) AS visitor_count")
            ->groupBy(DB::raw($bucket))
            ->pluck('visitor_count', 'bucket')
            ->map(static fn ($count): int => (int) $count)
            ->all();
    }

    private function cartVisitorBuckets(array $dateRange, string $granularity): array
    {
        $eventsAvailable = $this->dashboardEventsAvailable();
        $table           = $eventsAvailable ? 'dashboard_events' : 'cart_products';
        $model           = $eventsAvailable
            ? DashboardEvent::query()->where('event_type', 'cart_added')
            : CartProduct::query();
        $createdAt = $this->qualifiedColumn($table, 'created_at');
        $bucket    = $this->timeBucketExpression($createdAt, $granularity);
        $visitor   = $this->cartVisitorKeyExpression($eventsAvailable);
        $rows      = $model
            ->whereBetween('created_at', $dateRange)
            ->selectRaw("{$bucket} AS bucket, COUNT(DISTINCT {$visitor}) AS visitor_count")
            ->groupBy(DB::raw($bucket))
            ->get();

        return $rows->mapWithKeys(static fn ($row) => [(string) $row->bucket => [
            'visitor_count' => (int) $row->visitor_count,
        ]])->all();
    }

    private function paidOrderBuckets(array $dateRange, string $granularity): array
    {
        $paidOrders = $this->paidOrdersSubquery($dateRange);
        $paidAt     = $this->qualifiedColumn('dashboard_paid_bucket', 'paid_at');
        $total      = $this->qualifiedColumn('dashboard_paid_bucket', 'order_total');
        $bucket     = $this->timeBucketExpression($paidAt, $granularity);
        $rows       = DB::query()
            ->fromSub($paidOrders, 'dashboard_paid_bucket')
            ->selectRaw("{$bucket} AS bucket, COUNT(*) AS purchase_count, SUM({$total}) AS revenue")
            ->groupBy(DB::raw($bucket))
            ->get();

        return $rows->mapWithKeys(static fn ($row) => [(string) $row->bucket => [
            'purchase_count' => (int) $row->purchase_count,
            'revenue'        => (float) $row->revenue,
        ]])->all();
    }

    private function countPaidOrdersInRange(array $dateRange): int
    {
        return DB::query()->fromSub($this->paidOrdersSubquery($dateRange), 'dashboard_paid_count')->count();
    }

    private function countOrderCreatorsInRange(array $dateRange): int
    {
        $buyerKey = $this->orderBuyerKeyExpression();
        $buyers   = Order::query()
            ->whereBetween('created_at', $dateRange)
            ->whereNull('deleted_at')
            ->selectRaw("{$buyerKey} AS buyer_key")
            ->groupByRaw($buyerKey);

        return DB::query()->fromSub($buyers, 'dashboard_order_creators')->count();
    }

    private function countPaidPurchasersInRange(array $dateRange): int
    {
        return $this->memoizeRange('paid_purchasers', $dateRange, function () use ($dateRange): int {
            $buyerKey = $this->qualifiedColumn('dashboard_paid_buyers', 'buyer_key');

            return DB::query()
                ->fromSub($this->paidOrdersSubquery($dateRange), 'dashboard_paid_buyers')
                ->distinct()
                ->count(DB::raw($buyerKey));
        });
    }

    /**
     * Payment metrics belong to the moment an order entered the paid state,
     * not the moment an unpaid order was originally created.
     */
    private function paidOrdersSubquery(array $dateRange): \Illuminate\Database\Query\Builder
    {
        $orderId        = $this->qualifiedColumn('orders', 'id');
        $orderCreatedAt = $this->qualifiedColumn('orders', 'created_at');
        $orderTotal     = Schema::hasColumn('orders', 'total')
            ? $this->qualifiedColumn('orders', 'total')
            : '0';
        $buyerKey       = $this->orderBuyerKeyExpression();

        if (! $this->orderHistoriesAvailable()) {
            return DB::table('orders')
                ->whereBetween('orders.created_at', $dateRange)
                ->whereIn('orders.status', StateMachineService::getValidStatuses())
                ->whereNull('orders.deleted_at')
                ->selectRaw("{$orderId} AS order_id, {$orderCreatedAt} AS paid_at, {$buyerKey} AS buyer_key, {$orderTotal} AS order_total");
        }

        // Resolve the first paid transition across the complete history before
        // applying the requested date range. Filtering first would allow a
        // duplicated/replayed paid history row to move an old sale into today.
        $firstPaidTransitions = DB::table('order_histories')
            ->where('order_histories.status', StateMachineService::PAID)
            ->select('order_histories.order_id')
            ->selectRaw(
                'CASE WHEN COUNT(*) > COUNT(' . $this->qualifiedColumn('order_histories', 'created_at') . ')'
                . ' THEN NULL ELSE MIN(' . $this->qualifiedColumn('order_histories', 'created_at') . ') END AS paid_at'
            )
            ->groupBy('order_histories.order_id');

        $firstPaidAt     = $this->qualifiedColumn('first_paid_transition', 'paid_at');
        $effectivePaidAt = "COALESCE({$firstPaidAt}, {$orderCreatedAt})";

        $paidHistoryOrders = DB::table('orders')
            ->joinSub($firstPaidTransitions, 'first_paid_transition', function ($join): void {
                $join->on('orders.id', '=', 'first_paid_transition.order_id');
            })
            ->whereBetween(DB::raw($effectivePaidAt), $dateRange)
            ->whereNull('orders.deleted_at')
            ->selectRaw("{$orderId} AS order_id, {$effectivePaidAt} AS paid_at, {$buyerKey} AS buyer_key, {$orderTotal} AS order_total");

        // Imports and early LONGOS releases may have valid paid orders but no
        // paid history row. Include only those legacy orders by creation time;
        // where a paid history exists it remains the authoritative timestamp.
        $legacyPaidOrders = DB::table('orders')
            ->whereBetween('orders.created_at', $dateRange)
            ->whereIn('orders.status', StateMachineService::getValidStatuses())
            ->whereNull('orders.deleted_at')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('order_histories')
                    ->whereColumn('order_histories.order_id', 'orders.id')
                    ->where('order_histories.status', StateMachineService::PAID);
            })
            ->selectRaw("{$orderId} AS order_id, {$orderCreatedAt} AS paid_at, {$buyerKey} AS buyer_key, {$orderTotal} AS order_total");

        return $paidHistoryOrders->unionAll($legacyPaidOrders);
    }

    private function orderBuyerKeyExpression(): string
    {
        $customerId = $this->qualifiedColumn('orders', 'customer_id');
        $email      = $this->qualifiedColumn('orders', 'email');
        $ip         = $this->qualifiedColumn('orders', 'ip');
        $id         = $this->qualifiedColumn('orders', 'id');

        return match (DB::connection()->getDriverName()) {
            'pgsql'  => "CASE WHEN {$customerId} > 0 THEN 'customer:' || {$customerId}::text ELSE COALESCE('email:' || NULLIF(LOWER({$email}), ''), 'ip:' || NULLIF({$ip}, ''), 'order:' || {$id}::text) END",
            'sqlite' => "CASE WHEN {$customerId} > 0 THEN 'customer:' || CAST({$customerId} AS TEXT) ELSE COALESCE('email:' || NULLIF(LOWER({$email}), ''), 'ip:' || NULLIF({$ip}, ''), 'order:' || CAST({$id} AS TEXT)) END",
            default  => "CASE WHEN {$customerId} > 0 THEN CONCAT('customer:', {$customerId}) ELSE COALESCE(CONCAT('email:', NULLIF(LOWER({$email}), '')), CONCAT('ip:', NULLIF({$ip}, '')), CONCAT('order:', {$id})) END",
        };
    }

    private function orderHistoriesAvailable(): bool
    {
        if ($this->orderHistoriesAvailable === null) {
            $this->orderHistoriesAvailable = Schema::hasTable('order_histories')
                && Schema::hasColumn('order_histories', 'created_at');
        }

        return $this->orderHistoriesAvailable;
    }

    private function timeBucketExpression(string $column, string $granularity): string
    {
        $hourly = $granularity === 'hour';

        return match (DB::connection()->getDriverName()) {
            'pgsql'  => "TO_CHAR({$column}, 'YYYY-MM-DD" . ($hourly ? " HH24')" : "')"),
            'sqlite' => "strftime('" . ($hourly ? '%Y-%m-%d %H' : '%Y-%m-%d') . "', {$column})",
            default  => "DATE_FORMAT({$column}, '" . ($hourly ? '%Y-%m-%d %H' : '%Y-%m-%d') . "')",
        };
    }

    private function conversionRate(int $converted, int $base): float
    {
        if ($base <= 0) {
            return 0.0;
        }

        // Do not cap the result. A rate above 100% is meaningful evidence of a
        // partial tracking window or mixed period activity; replacing it with
        // 100% would hide a data-quality problem and make the figure false.
        return round(($converted / $base) * 100, 1);
    }

    /**
     * 计算百分比变化
     */
    private function calculatePercentage(float $current, float $previous): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * 从URL中提取域名
     */
    private function extractDomain(string $url): string
    {
        if (empty($url)) {
            return '';
        }

        // 如果URL不包含协议，添加http://
        if (! preg_match('/^https?:\/\//', $url)) {
            $url = 'http://' . $url;
        }

        $parsedUrl = parse_url($url);
        $host      = $parsedUrl['host'] ?? '';

        // 移除www前缀进行统一比较
        return preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * 检查域名是否匹配指定的域名列表
     */
    private function isDomainMatch(string $domain, array $targetDomains): bool
    {
        if (empty($domain)) {
            return false;
        }

        foreach ($targetDomains as $targetDomain) {
            // 移除www前缀进行比较
            $normalizedTarget = preg_replace('/^www\./', '', strtolower($targetDomain));

            if ($domain === $normalizedTarget || str_ends_with($domain, '.' . $normalizedTarget)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 生成缓存键
     */
    private function generateCacheKey(string ...$parts): string
    {
        return $this->generateLocalizedCacheKey(app()->getLocale(), ...$parts);
    }

    private function generateLocalizedCacheKey(string $locale, string ...$parts): string
    {
        $site = current_site();
        if ($site) {
            $scope = 'site:' . $site->getKey();
        } else {
            $connection = DB::connection();
            $scope      = implode(':', [
                'database',
                $connection->getName(),
                $connection->getDatabaseName(),
                (string) $connection->getTablePrefix(),
            ]);
        }

        // Source, funnel and country labels are translated before caching.
        // Locale isolation prevents one administrator's language from leaking
        // into another administrator's cached dashboard snapshot.
        return 'dashboard:' . self::CACHE_SCHEMA_VERSION . ':' . hash('sha256', $scope) . ':' . $locale . ':' . implode(':', $parts);
    }

    /**
     * A storefront can be reached through its assigned subdomain, www alias,
     * and verified custom domains. Navigation among those hosts is internal
     * traffic and must not be reported as an acquisition source.
     *
     * @return string[]
     */
    private function internalStorefrontDomains(): array
    {
        $domains = [$this->extractDomain((string) request()->getHost())];
        $site    = current_site();
        if ($site) {
            try {
                $siteDomains = $site->relationLoaded('domains')
                    ? $site->domains->pluck('host')->all()
                    : $site->domains()->pluck('host')->all();
                foreach ($siteDomains as $host) {
                    $domains[] = $this->extractDomain((string) $host);
                }
            } catch (\Throwable) {
                // The request host remains a correct fallback during a
                // temporary control-database outage.
            }
        }

        return array_values(array_unique(array_filter($domains)));
    }

    /**
     * Quote a qualified column together with the active tenant table prefix.
     */
    private function qualifiedColumn(string $table, string $column): string
    {
        $grammar = DB::connection()->getQueryGrammar();

        return $grammar->wrapTable($table) . '.' . $grammar->wrap($column);
    }

    /**
     * Explain where the numbers came from and whether collection is current.
     * This prevents an empty chart from being mistaken for zero demand when a
     * tenant has not yet deployed the storefront tracking migration.
     */
    private function getAnalyticsMeta(string $timeRange): array
    {
        $dateRange       = $this->getDateRange($timeRange);
        $previousRange   = $this->getPreviousDateRange($timeRange);
        $usesVisitLedger = $this->storefrontVisitsInRange($dateRange);
        $trackedVisitors = $this->countUniqueVisitors($dateRange);
        $visitVisitors   = $usesVisitLedger
            ? DB::query()->fromSub($this->confirmedStorefrontVisitorIdentities($dateRange), 'dashboard_visit_visitors')->count()
            : 0;
        $hasProductViews = $this->productViewsInRange($dateRange) > 0;
        $knownCountries  = 0;
        $filteredEvents  = 0;

        if ($this->storefrontVisitConfirmationAvailable()) {
            $filteredEvents += (int) StorefrontVisit::query()
                ->whereBetween('bucket_at', $dateRange)
                ->whereNull('human_confirmed_at')
                ->sum('page_views');
        }
        if ($this->productViewsAvailable() && ProductView::supportsHumanConfirmation()) {
            $filteredEvents += ProductView::query()
                ->whereBetween('created_at', $dateRange)
                ->whereNull('human_confirmed_at')
                ->count();
        }

        if ($trackedVisitors > 0) {
            $countryRows    = $this->unifiedCountryRows($dateRange);
            $knownCountries = (int) $countryRows
                ->filter(static fn ($row): bool => preg_match('/^[A-Z]{2}$/', strtoupper((string) $row->country_code)) === 1)
                ->sum('visitors');
        }

        $lastSeenAt = null;
        $startedAt  = null;
        if ($usesVisitLedger) {
            $lastSeenAt = $this->storefrontVisitQuery()->max('last_seen_at');
            $startedAt  = $this->storefrontVisitQuery()->min('first_seen_at');
        }
        if ($this->productViewsAvailable()) {
            $lastProductView  = ProductView::humanConfirmed()->max('created_at');
            $firstProductView = ProductView::humanConfirmed()->min('created_at');
            if ($lastProductView && (! $lastSeenAt || Carbon::parse($lastProductView)->greaterThan(Carbon::parse($lastSeenAt)))) {
                $lastSeenAt = $lastProductView;
            }
            if ($firstProductView && (! $startedAt || Carbon::parse($firstProductView)->lessThan(Carbon::parse($startedAt)))) {
                $startedAt = $firstProductView;
            }
        }

        $lastSeen  = $lastSeenAt ? Carbon::parse($lastSeenAt) : null;
        $snapshot  = $this->clock();
        $freshness = match (true) {
            $lastSeen === null                                                 => 'empty',
            $lastSeen->greaterThanOrEqualTo($snapshot->copy()->subMinutes(15)) => 'live',
            $lastSeen->greaterThanOrEqualTo($snapshot->copy()->subDay())       => 'delayed',
            default                                                            => 'stale',
        };

        return [
            'generated_at'             => $snapshot->toIso8601String(),
            'period_start'             => $dateRange[0]->toIso8601String(),
            'period_end'               => $dateRange[1]->toIso8601String(),
            'comparison_start'         => $previousRange[0]->toIso8601String(),
            'comparison_end'           => $previousRange[1]->toIso8601String(),
            'tracking_started_at'      => $startedAt ? Carbon::parse($startedAt)->toIso8601String() : null,
            'last_event_at'            => $lastSeen?->toIso8601String(),
            'freshness'                => $freshness,
            'traffic_source'           => match (true) {
                $usesVisitLedger && $trackedVisitors > $visitVisitors => 'combined',
                $usesVisitLedger                                      => 'storefront_visits',
                $hasProductViews                                      => 'product_views',
                default                                               => 'unavailable',
            },
            'tracked_visitors'         => $trackedVisitors,
            'filtered_unverified'      => $filteredEvents,
            'country_coverage_percent' => $trackedVisitors > 0 ? round(($knownCountries / $trackedVisitors) * 100, 1) : 0.0,
        ];
    }

    /**
     * Use one immutable reference instant while an overview is assembled.
     * Standalone legacy endpoints still receive a fresh current time.
     */
    private function clock(): Carbon
    {
        return ($this->snapshotAt ?? Carbon::now())->copy();
    }

    private function memoizeRange(string $metric, array $dateRange, callable $callback): mixed
    {
        if (! $this->buildingOverview) {
            return $callback();
        }

        $key = implode('|', [
            $metric,
            $dateRange[0]->format('Y-m-d H:i:s.uP'),
            $dateRange[1]->format('Y-m-d H:i:s.uP'),
        ]);

        if (! array_key_exists($key, $this->snapshotMemo)) {
            $this->snapshotMemo[$key] = $callback();
        }

        return $this->snapshotMemo[$key];
    }

    /**
     * 获取缓存状态
     */
    public function getCacheStatus(): array
    {
        return [
            'enabled'              => $this->enableCache,
            'duration'             => $this->cacheDuration,
            'use_mock_data'        => $this->useMockData,
            'mock_data_multiplier' => $this->mockDataMultiplier,
        ];
    }

    /**
     * 清理Dashboard缓存
     */
    public function clearDashboardCache(): void
    {
        if (! $this->enableCache || $this->cacheDuration <= 0) {
            return; // 如果缓存已禁用，无需清理
        }

        $locales = array_values(array_unique(array_merge(
            [app()->getLocale()],
            function_exists('language_packages') ? language_packages() : []
        )));

        foreach ($locales as $locale) {
            foreach (self::VALID_TIME_RANGES as $timeRange) {
                foreach (['overview', 'basic_stats', 'trend_data', 'mini_chart_data', 'source_analysis', 'funnel_data', 'visitor_country_ranking'] as $type) {
                    Cache::forget($this->generateLocalizedCacheKey($locale, $type, $timeRange));
                }

                Cache::forget($this->generateLocalizedCacheKey($locale, 'product_ranking', 'hot', $timeRange));
            }
        }
    }
}
