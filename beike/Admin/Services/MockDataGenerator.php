<?php

namespace Beike\Admin\Services;

use Carbon\Carbon;

class MockDataGenerator
{
    private float $multiplier;

    public function __construct(float $multiplier = 1.0)
    {
        $this->multiplier = $multiplier;
    }

    /**
     * 生成基础统计数据
     */
    public function generateBasicStats(string $timeRange): array
    {
        $baseData = $this->getBaseDataForTimeRange($timeRange);

        // 访客统计
        $visitorStats = [
            'current'    => (int) ($baseData['visitors'] * $this->multiplier),
            'previous'   => (int) ($baseData['visitors'] * 0.85 * $this->multiplier),
            'percentage' => $this->calculatePercentage(
                (int) round($baseData['visitors'] * $this->multiplier),
                (int) round($baseData['visitors'] * 0.85 * $this->multiplier)
            ),
            'trend'      => 'up',
        ];

        // 加购统计
        $cartStats = [
            'current'    => (int) ($baseData['carts'] * $this->multiplier),
            'previous'   => (int) ($baseData['carts'] * 0.9 * $this->multiplier),
            'percentage' => $this->calculatePercentage(
                (int) round($baseData['carts'] * $this->multiplier),
                (int) round($baseData['carts'] * 0.9 * $this->multiplier)
            ),
            'trend'      => 'up',
        ];

        // 购买统计
        $purchaseStats = [
            'current'    => (int) ($baseData['purchases'] * $this->multiplier),
            'previous'   => (int) ($baseData['purchases'] * 0.95 * $this->multiplier),
            'percentage' => $this->calculatePercentage(
                (int) round($baseData['purchases'] * $this->multiplier),
                (int) round($baseData['purchases'] * 0.95 * $this->multiplier)
            ),
            'trend'      => 'up',
        ];

        // Demo-only counterpart of the real payment-information event.
        $paymentInformationStats = [
            'current'    => (int) round($cartStats['current'] * 0.7),
            'previous'   => (int) round($cartStats['previous'] * 0.65),
            'percentage' => 0,
            'trend'      => 'up',
        ];
        $paymentInformationStats['percentage'] = $this->calculatePercentage(
            $paymentInformationStats['current'],
            $paymentInformationStats['previous']
        );

        // 转化率统计
        $conversionStats = $this->generateConversionStats($visitorStats, $cartStats, $purchaseStats, $paymentInformationStats);
        $paidOrders      = $purchaseStats;
        $revenue         = [
            'current'    => round($purchaseStats['current'] * 80, 2),
            'previous'   => round($purchaseStats['previous'] * 78, 2),
            'percentage' => 0,
            'trend'      => 'up',
        ];
        $revenue['percentage'] = $this->calculatePercentage((int) $revenue['current'], (int) $revenue['previous']);
        $averageOrderValue     = [
            'current'    => $paidOrders['current']  > 0 ? round($revenue['current'] / $paidOrders['current'], 2) : 0,
            'previous'   => $paidOrders['previous'] > 0 ? round($revenue['previous'] / $paidOrders['previous'], 2) : 0,
            'percentage' => 0,
            'trend'      => 'up',
        ];
        $averageOrderValue['percentage'] = $this->calculatePercentage((int) $averageOrderValue['current'], (int) $averageOrderValue['previous']);

        return [
            'visitors'            => $visitorStats,
            'carts'               => $cartStats,
            'purchases'           => $purchaseStats,
            'paid_orders'         => $paidOrders,
            'revenue'             => $revenue,
            'average_order_value' => $averageOrderValue,
            'conversion'          => $conversionStats,
            'currency'            => 'USD',
            'time_range'          => $timeRange,
        ];
    }

    /**
     * 生成趋势数据
     */
    public function generateTrendData(string $timeRange): array
    {
        switch ($timeRange) {
            case 'month':
                return $this->generateRollingTrends(30);
            case 'week':
                return $this->generateRollingTrends(7);
            case 'yesterday':
                return $this->generateDailyTrends(1);
            case 'today':
            default:
                return $this->generateDailyTrends(0);
        }
    }

    /**
     * 生成客户来源分析数据
     */
    public function generateSourceAnalysis(string $timeRange): array
    {
        $baseData   = $this->getBaseDataForTimeRange($timeRange);
        $totalViews = (int) ($baseData['visitors'] * 3 * $this->multiplier); // 假设每个访客平均浏览3个页面

        $sources = [
            ['name' => __('admin/dashboard.direct_access'), 'value' => (int) ($totalViews * 0.44), 'percentage' => 44.0], // 35% + 9% = 44%
            ['name' => __('admin/dashboard.google'), 'value' => (int) ($totalViews * 0.28), 'percentage' => 28.0],
            ['name' => __('admin/dashboard.baidu'), 'value' => (int) ($totalViews * 0.11), 'percentage' => 11.0],
            ['name' => 'GitHub', 'value' => (int) ($totalViews * 0.12), 'percentage' => 12.0],
            ['name' => __('admin/dashboard.facebook'), 'value' => (int) ($totalViews * 0.05), 'percentage' => 5.0],
        ];

        return $sources;
    }

    /** Demo-only country distribution used when dashboard mock data is enabled. */
    public function generateVisitorCountryRanking(string $timeRange): array
    {
        $visitors  = max(1, (int) ($this->getBaseDataForTimeRange($timeRange)['visitors'] * $this->multiplier));
        $countries = [
            ['code' => 'US', 'name' => 'United States', 'ratio' => 0.36],
            ['code' => 'CN', 'name' => 'China', 'ratio' => 0.24],
            ['code' => 'GB', 'name' => 'United Kingdom', 'ratio' => 0.17],
            ['code' => 'CA', 'name' => 'Canada', 'ratio' => 0.13],
            ['code' => 'AU', 'name' => 'Australia', 'ratio' => 0.10],
        ];

        return array_map(static function (array $country, int $index) use ($visitors): array {
            return [
                'rank'       => $index + 1,
                'code'       => $country['code'],
                'name'       => $country['name'],
                'visitors'   => (int) round($visitors * $country['ratio']),
                'percentage' => round($country['ratio'] * 100, 1),
            ];
        }, $countries, array_keys($countries));
    }

    /**
     * 生成小型趋势图数据
     */
    public function generateMiniChartData(string $timeRange): array
    {
        switch ($timeRange) {
            case 'month':
                return $this->generateRollingMiniCharts(30);
            case 'week':
                return $this->generateRollingMiniCharts(7);
            case 'yesterday':
                return $this->generateDailyMiniCharts(1);
            case 'today':
            default:
                return $this->generateDailyMiniCharts(0);
        }
    }

    /**
     * 生成每日小型趋势图数据
     */
    private function generateDailyMiniCharts(int $daysAgo = 0): array
    {
        $hours          = range(0, 23);
        $visitorData    = [];
        $cartData       = [];
        $purchaseData   = [];
        $revenueData    = [];
        $conversionData = [];

        // 使用 daysAgo 作为随机数种子，确保不同天数的数据不同
        $seed = $daysAgo * 1000 + time() % 1000;
        srand($seed);

        foreach ($hours as $hour) {
            // 模拟一天中的访问模式
            $hourMultiplier = $this->getHourMultiplier($hour);
            $dayMultiplier  = $this->getDayMultiplierForTrend($daysAgo);

            // 基础数据
            $baseVisitor  = (int) (50 * $this->multiplier * $dayMultiplier);
            $baseCart     = (int) (15 * $this->multiplier * $dayMultiplier);
            $basePurchase = (int) (8 * $this->multiplier * $dayMultiplier);

            // 生成数据点
            $visitor    = max(0, (int) ($baseVisitor * $hourMultiplier + rand(-5, 5)));
            $cart       = max(0, (int) ($baseCart * $hourMultiplier + rand(-2, 2)));
            $purchase   = max(0, (int) ($basePurchase * $hourMultiplier + rand(-1, 1)));
            $conversion = $visitor > 0 ? round(($purchase / $visitor) * 100, 1) : 0;

            $visitorData[]    = $visitor;
            $cartData[]       = $cart;
            $purchaseData[]   = $purchase;
            $revenueData[]    = round($purchase * 80, 2);
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
     * 生成每周小型趋势图数据
     */
    private function generateRollingMiniCharts(int $daysCount): array
    {
        $days           = [];
        $visitorData    = [];
        $cartData       = [];
        $purchaseData   = [];
        $revenueData    = [];
        $conversionData = [];

        for ($i = $daysCount - 1; $i >= 0; $i--) {
            $date   = Carbon::now()->subDays($i);
            $days[] = $date->format('m/d');

            // 模拟一周中的访问模式
            $dayMultiplier = $this->getDayMultiplier($date->dayOfWeek);
            $baseVisitor   = (int) (200 * $this->multiplier * $dayMultiplier);
            $baseCart      = (int) (60 * $this->multiplier * $dayMultiplier);
            $basePurchase  = (int) (30 * $this->multiplier * $dayMultiplier);

            $visitor    = max(0, (int) ($baseVisitor + rand(-20, 20)));
            $cart       = max(0, (int) ($baseCart + rand(-10, 10)));
            $purchase   = max(0, (int) ($basePurchase + rand(-5, 5)));
            $conversion = $visitor > 0 ? round(($purchase / $visitor) * 100, 1) : 0;

            $visitorData[]    = $visitor;
            $cartData[]       = $cart;
            $purchaseData[]   = $purchase;
            $revenueData[]    = round($purchase * 80, 2);
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
     * 生成转化漏斗数据
     */
    public function generateFunnelData(string $timeRange): array
    {
        $baseData = $this->getBaseDataForTimeRange($timeRange);

        $visits        = (int) ($baseData['visitors'] * 1.2 * $this->multiplier);
        $productViews  = (int) ($visits * 0.8);
        $cartAdds      = (int) ($productViews * 0.35);
        $orders        = (int) ($cartAdds * 0.5);
        $payments      = (int) ($orders * 0.6);

        $maxValue = max($visits, $productViews, $cartAdds, $orders, $payments) ?: 1;

        return [
            [
                'key'        => 'visitors',
                'name'       => __('admin/dashboard.funnel_visitors'),
                'value'      => $visits,
                'percentage' => 100,
                'width'      => 100,
            ],
            [
                'key'        => 'product_viewers',
                'name'       => __('admin/dashboard.product_viewers'),
                'value'      => $productViews,
                'percentage' => $visits ? round(($productViews / $visits) * 100, 1) : 0,
                'width'      => round(($productViews / $maxValue) * 100, 1),
            ],
            [
                'key'        => 'cart_visitors',
                'name'       => __('admin/dashboard.cart_visitors'),
                'value'      => $cartAdds,
                'percentage' => $productViews ? round(($cartAdds / $productViews) * 100, 1) : 0,
                'width'      => round(($cartAdds / $maxValue) * 100, 1),
            ],
            [
                'key'        => 'order_creators',
                'name'       => __('admin/dashboard.order_creators'),
                'value'      => $orders,
                'percentage' => $cartAdds ? round(($orders / $cartAdds) * 100, 1) : 0,
                'width'      => round(($orders / $maxValue) * 100, 1),
            ],
            [
                'key'        => 'paid_purchasers',
                'name'       => __('admin/dashboard.paid_purchasers'),
                'value'      => $payments,
                'percentage' => $orders ? round(($payments / $orders) * 100, 1) : 0,
                'width'      => round(($payments / $maxValue) * 100, 1),
            ],
        ];
    }

    /**
     * 生成商品销售排行数据
     */
    public function generateProductRanking(string $type, string $timeRange): array
    {
        $baseData = $this->getBaseDataForTimeRange($timeRange);

        $products     = [];
        $productNames = [
            '智能手机 Pro Max', '无线蓝牙耳机', '智能手表 Series 5', '平板电脑 Air', '笔记本电脑 Pro',
            '游戏手柄', '无线充电器', '智能音箱', 'VR眼镜', '无人机',
        ];

        $prices = [5999, 399, 1599, 2999, 8999, 299, 199, 599, 2999, 3999];

        for ($i = 0; $i < 5; $i++) {
            // 热销商品：销量递减
            $totalSold = (int) round(($baseData['purchases'] * (5 - $i) * 0.2) * $this->multiplier);

            $products[] = [
                'id'                  => $i + 1,
                'name'                => $productNames[$i],
                'price'               => '¥' . number_format($prices[$i]),
                'total_sold'          => $totalSold,
                'sales_amount'        => $totalSold * $prices[$i],
                'sales_amount_format' => '$' . number_format($totalSold * $prices[$i], 2),
                'percentage'          => $i === 0 ? 100 : round((100 * (5 - $i)) / 5, 1),
                'progress_width'      => $i === 0 ? 100 : round((100 * (5 - $i)) / 5, 1),
                'type'                => $type,
            ];
        }

        return $products;
    }

    /**
     * 获取时间范围的基础数据
     */
    private function getBaseDataForTimeRange(string $timeRange): array
    {
        switch ($timeRange) {
            case 'month':
                return [
                    'visitors'  => 36000,
                    'carts'     => 9200,
                    'purchases' => 3600,
                ];
            case 'yesterday':
                return [
                    'visitors'  => 1200,
                    'carts'     => 320,
                    'purchases' => 120,
                ];
            case 'week':
                return [
                    'visitors'  => 8500,
                    'carts'     => 2200,
                    'purchases' => 850,
                ];
            case 'today':
            default:
                return [
                    'visitors'  => 1284,
                    'carts'     => 342,
                    'purchases' => 128,
                ];
        }
    }

    /**
     * 生成转化率统计
     */
    private function generateConversionStats(array $visitorStats, array $cartStats, array $purchaseStats, array $paymentInformationStats): array
    {
        $types = [
            'visitor-to-purchase' => [
                'rate'          => $visitorStats['current']  > 0 ? round(($purchaseStats['current'] / $visitorStats['current']) * 100, 1) : 0,
                'previous_rate' => $visitorStats['previous'] > 0 ? round(($purchaseStats['previous'] / $visitorStats['previous']) * 100, 1) : 0,
            ],
            'visitor-to-cart' => [
                'rate'          => $visitorStats['current']  > 0 ? round(($cartStats['current'] / $visitorStats['current']) * 100, 1) : 0,
                'previous_rate' => $visitorStats['previous'] > 0 ? round(($cartStats['previous'] / $visitorStats['previous']) * 100, 1) : 0,
            ],
            'visitor-to-payment-information' => [
                'rate'          => $visitorStats['current']  > 0 ? round(($paymentInformationStats['current'] / $visitorStats['current']) * 100, 1) : 0,
                'previous_rate' => $visitorStats['previous'] > 0 ? round(($paymentInformationStats['previous'] / $visitorStats['previous']) * 100, 1) : 0,
            ],
            'cart-to-purchase' => [
                'rate'          => $cartStats['current']  > 0 ? round(($purchaseStats['current'] / $cartStats['current']) * 100, 1) : 0,
                'previous_rate' => $cartStats['previous'] > 0 ? round(($purchaseStats['previous'] / $cartStats['previous']) * 100, 1) : 0,
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
     * 生成每日趋势数据
     */
    private function generateDailyTrends(int $daysAgo = 0): array
    {
        $hours       = range(0, 23);
        $pvData      = [];
        $uvData      = [];
        $orderData   = [];
        $revenueData = [];

        // 使用 daysAgo 作为随机数种子，确保不同天数的数据不同
        $seed = $daysAgo * 1000 + time() % 1000;
        srand($seed);

        foreach ($hours as $hour) {
            // 模拟一天中的访问模式：上午和晚上访问量较高
            $hourMultiplier = $this->getHourMultiplier($hour);

            // 根据天数调整基础数据，让不同天数的数据有明显差异
            $dayMultiplier = $this->getDayMultiplierForTrend($daysAgo);
            $basePv        = (int) (50 * $this->multiplier * $dayMultiplier);
            $baseUv        = (int) (20 * $this->multiplier * $dayMultiplier);

            $pv = (int) ($basePv * $hourMultiplier + rand(-10, 10));
            $uv = (int) ($baseUv * $hourMultiplier + rand(-5, 5));

            $pvData[]      = max(0, $pv);
            $uvData[]      = max(0, $uv);
            $orders        = max(0, (int) round($uv * .12));
            $orderData[]   = $orders;
            $revenueData[] = $orders * 80;
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
     * 生成周趋势数据
     */
    private function generateRollingTrends(int $daysCount): array
    {
        $days        = [];
        $pvData      = [];
        $uvData      = [];
        $orderData   = [];
        $revenueData = [];

        for ($i = $daysCount - 1; $i >= 0; $i--) {
            $date   = Carbon::now()->subDays($i);
            $days[] = $date->format('m/d');

            // 模拟一周中的访问模式：工作日和周末不同
            $dayMultiplier = $this->getDayMultiplier($date->dayOfWeek);
            $basePv        = 200 * $this->multiplier;
            $baseUv        = 80  * $this->multiplier;

            $pv = (int) ($basePv * $dayMultiplier + rand(-30, 30));
            $uv = (int) ($baseUv * $dayMultiplier + rand(-15, 15));

            $pvData[]      = max(0, $pv);
            $uvData[]      = max(0, $uv);
            $orders        = max(0, (int) round($uv * .12));
            $orderData[]   = $orders;
            $revenueData[] = $orders * 80;
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
        } elseif ($hour >= 0 && $hour <= 6) {
            return 0.3; // 深夜低谷
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
     * 计算百分比变化
     */
    private function calculatePercentage(int $current, int $previous): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
