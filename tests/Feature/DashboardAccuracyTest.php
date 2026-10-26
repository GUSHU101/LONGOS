<?php

namespace Tests\Feature;

use App\Saas\Models\Site;
use Beike\Admin\Services\DashboardService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class DashboardAccuracyTest extends TestCase
{
    private string $databasePath;

    private string $previousConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $this->previousConnection = (string) Config::get('database.default');
        $this->databasePath       = tempnam(sys_get_temp_dir(), 'longos-dashboard-');
        Config::set('database.connections.dashboard_accuracy', [
            'driver'                  => 'sqlite',
            'database'                => $this->databasePath,
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);
        Config::set('database.default', 'dashboard_accuracy');
        Config::set('app.dashboard.enable_cache', false);
        DB::purge('dashboard_accuracy');
        DB::setDefaultConnection('dashboard_accuracy');
        Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', config('app.timezone')));

        $this->createSchema();
        $this->seedMetrics();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::disconnect('dashboard_accuracy');
        DB::setDefaultConnection($this->previousConnection);
        Config::set('database.default', $this->previousConnection);
        @unlink($this->databasePath);
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_dashboard_uses_unique_customer_identity_and_paid_transition_time(): void
    {
        $service = new DashboardService;
        $stats   = $service->getBasicStats('today');

        self::assertSame(2, $stats['visitors']['current']);
        self::assertSame(1, $stats['carts']['current']);
        self::assertSame(2, $stats['purchases']['current']);
        self::assertSame(100.0, $stats['conversion']['visitor-to-purchase']['rate']);
        self::assertSame(2, $stats['paid_orders']['current']);
        self::assertSame(65.0, $stats['revenue']['current']);
        self::assertSame(32.5, $stats['average_order_value']['current']);

        $funnel = $service->getFunnelData('today');
        self::assertSame(['visitors', 'product_viewers', 'cart_visitors', 'order_creators', 'paid_purchasers'], array_column($funnel, 'key'));
        self::assertSame([2, 2, 1, 2, 2], array_column($funnel, 'value'));
        self::assertSame([100, 100.0, 50.0, 200.0, 100.0], array_column($funnel, 'percentage'));
        self::assertSame(1, $funnel[2]['value']);
        self::assertSame(2, $funnel[3]['value']);
        self::assertSame(2, $funnel[4]['value']);

        $ranking = $service->getProductRanking('hot', 'today');
        self::assertSame(1, $ranking[0]['id']);
        self::assertSame(2, (int) $ranking[0]['total_sold']);
        self::assertSame(20.0, $ranking[0]['sales_amount']);
    }

    public function test_chart_points_are_aggregated_in_bulk_without_hourly_query_loops(): void
    {
        $service = new DashboardService;
        $service->getBasicStats('today'); // Warm schema capability checks.

        DB::flushQueryLog();
        DB::enableQueryLog();
        $trend        = $service->getTrendData('today');
        $trendQueries = DB::getQueryLog();

        self::assertSame(3, $trend['pv'][9]);
        self::assertSame(2, $trend['uv'][9]);
        // Traffic and paid-sales series are each aggregated in one grouped
        // query (plus capability/union checks), never one query per point.
        self::assertLessThanOrEqual(6, count($trendQueries));

        DB::flushQueryLog();
        $mini        = $service->getMiniChartData('today');
        $miniQueries = DB::getQueryLog();

        self::assertSame(2, $mini['visitor'][9]);
        self::assertSame(1, $mini['cart'][9]);
        self::assertSame(1, $mini['purchase'][10]);
        self::assertSame(1, $mini['purchase'][11]);
        self::assertLessThanOrEqual(8, count($miniQueries));
    }

    public function test_source_and_country_percentages_use_the_complete_dataset(): void
    {
        $service   = new DashboardService;
        $sources   = $service->getSourceAnalysis('today');
        $countries = $service->getVisitorCountryRanking('today');

        // Source analysis counts distinct visitors, not repeated product-page
        // refreshes from the same session.
        self::assertSame(2, array_sum(array_column($sources, 'value')));
        self::assertSame(100.0, round(array_sum(array_column($sources, 'percentage')), 1));
        self::assertSame(['direct', 'google'], array_column($sources, 'key'));
        self::assertSame(2, array_sum(array_column($countries, 'visitors')));
        self::assertSame(100.0, round(array_sum(array_column($countries, 'percentage')), 1));
        self::assertSame(['CN', 'US'], array_column($countries, 'code'));
    }

    public function test_source_and_country_use_event_time_instead_of_insertion_id(): void
    {
        // This row is inserted last but happened before the other rows for the
        // same visitor. Source must use it; country must keep the actual latest
        // US observation at 10:01 instead of trusting this greater ID.
        DB::table('storefront_visits')->insert([
            'bucket_at'           => '2026-10-08 08:00:00',
            'visitor_key'         => str_repeat('a', 64),
            'customer_id'         => 7,
            'country_code'        => 'DE',
            'source_key'          => 'email',
            'source_detail'       => 'newsletter',
            'referer'             => null,
            'landing_path'        => '/sale',
            'page_views'          => 1,
            'first_seen_at'       => '2026-10-08 08:01:00',
            'last_seen_at'        => '2026-10-08 08:02:00',
            'human_confirmed_at'  => '2026-10-08 08:02:00',
            'confirmation_method' => 'pointer',
            'created_at'          => '2026-10-08 11:50:00',
            'updated_at'          => '2026-10-08 11:50:00',
        ]);

        $service   = new DashboardService;
        $sources   = $service->getSourceAnalysis('today');
        $countries = $service->getVisitorCountryRanking('today');

        self::assertSame(2, array_sum(array_column($sources, 'value')));
        self::assertSame(['CN', 'US'], array_column($countries, 'code'));
        self::assertNotContains('DE', array_column($countries, 'code'));
        self::assertSame(1, collect($sources)->firstWhere('name', __('admin/dashboard.email'))['value']);
    }

    public function test_guest_to_customer_transition_keeps_one_stable_visitor_identity(): void
    {
        DB::table('storefront_visits')->insert([
            'bucket_at'           => '2026-10-08 08:00:00',
            'visitor_key'         => str_repeat('a', 64),
            'customer_id'         => null,
            'country_code'        => 'DE',
            'source_key'          => 'email',
            'source_detail'       => 'newsletter',
            'referer'             => null,
            'landing_path'        => '/',
            'page_views'          => 1,
            'first_seen_at'       => '2026-10-08 08:00:00',
            'last_seen_at'        => '2026-10-08 08:00:00',
            'human_confirmed_at'  => '2026-10-08 08:00:00',
            'confirmation_method' => 'pointer',
            'created_at'          => '2026-10-08 08:00:00',
            'updated_at'          => '2026-10-08 08:00:00',
        ]);
        DB::table('product_views')->insert([
            'product_id'          => 1,
            'customer_id'         => null,
            'session_id'          => 'guest-before-login',
            'visitor_key'         => str_repeat('a', 64),
            'ip'                  => '10.0.0.40',
            'referer'             => null,
            'country_code'        => 'DE',
            'human_confirmed_at'  => '2026-10-08 08:00:00',
            'confirmation_method' => 'pointer',
            'created_at'          => '2026-10-08 08:00:00',
            'updated_at'          => '2026-10-08 08:00:00',
        ]);

        $service = new DashboardService;

        self::assertSame(2, $service->getBasicStats('today')['visitors']['current']);
        self::assertSame(2, $service->getFunnelData('today')[1]['value']);
        self::assertSame(2, array_sum(array_column($service->getSourceAnalysis('today'), 'value')));
        self::assertNotContains('DE', array_column($service->getVisitorCountryRanking('today'), 'code'));
    }

    public function test_legacy_unconfirmed_requests_are_not_reported_as_visitors_or_countries(): void
    {
        DB::table('storefront_visits')->insert([
            'bucket_at'           => '2026-10-08 11:00:00',
            'visitor_key'         => str_repeat('z', 64),
            'customer_id'         => null,
            'country_code'        => 'DE',
            'source_key'          => 'direct',
            'source_detail'       => null,
            'referer'             => null,
            'landing_path'        => '/',
            'page_views'          => 1,
            'first_seen_at'       => '2026-10-08 11:20:00',
            'last_seen_at'        => '2026-10-08 11:20:00',
            'human_confirmed_at'  => null,
            'confirmation_method' => null,
            'created_at'          => '2026-10-08 11:20:00',
            'updated_at'          => '2026-10-08 11:20:00',
        ]);
        DB::table('product_views')->insert([
            'product_id'          => 3,
            'customer_id'         => null,
            'session_id'          => 'unconfirmed-product-view',
            'visitor_key'         => str_repeat('z', 64),
            'ip'                  => '10.0.0.99',
            'referer'             => null,
            'country_code'        => 'DE',
            'human_confirmed_at'  => null,
            'confirmation_method' => null,
            'created_at'          => '2026-10-08 11:20:00',
            'updated_at'          => '2026-10-08 11:20:00',
        ]);

        $service = new DashboardService;

        self::assertSame(2, $service->getBasicStats('today')['visitors']['current']);
        self::assertSame(2, $service->getFunnelData('today')[1]['value']);
        self::assertNotContains('DE', array_column($service->getVisitorCountryRanking('today'), 'code'));
        self::assertSame(2, $service->getOverview('today')['meta']['filtered_unverified']);
    }

    public function test_empty_confirmed_visit_ledger_falls_back_to_product_view_metrics_for_the_period(): void
    {
        // The confirmation column exists, but no staged visit in the selected
        // period has become a confirmed human visit. Product views still hold
        // two distinct visitors and must keep every traffic panel consistent.
        DB::table('storefront_visits')->update([
            'human_confirmed_at'  => null,
            'confirmation_method' => null,
        ]);

        $overview = (new DashboardService)->getOverview('today');

        self::assertSame('product_views', $overview['meta']['traffic_source']);
        self::assertSame(2, $overview['meta']['tracked_visitors']);
        self::assertSame(2, $overview['stats']['visitors']['current']);
        self::assertSame([2, 2], array_slice(array_column($overview['funnel'], 'value'), 0, 2));
        self::assertSame([100, 100.0], array_slice(array_column($overview['funnel'], 'percentage'), 0, 2));
        self::assertSame(2, array_sum(array_column($overview['sources'], 'value')));
        self::assertSame(2, array_sum(array_column($overview['countries'], 'visitors')));
        self::assertSame(3, array_sum($overview['trends']['pv']));
        self::assertSame(2, array_sum($overview['trends']['uv']));
    }

    public function test_partial_visit_ledger_merges_product_only_visitors_across_every_traffic_panel(): void
    {
        DB::table('product_views')->insert([
            'product_id'          => 3,
            'customer_id'         => 0,
            'session_id'          => 'product-only-guest',
            'visitor_key'         => str_repeat('c', 64),
            'ip'                  => '10.0.0.30',
            'referer'             => 'https://www.bing.com/search?q=longos',
            'country_code'        => 'GB',
            'human_confirmed_at'  => '2026-10-08 11:30:00',
            'confirmation_method' => 'pointer',
            'created_at'          => '2026-10-08 11:30:00',
            'updated_at'          => '2026-10-08 11:30:00',
        ]);

        $overview = (new DashboardService)->getOverview('today');

        self::assertSame('combined', $overview['meta']['traffic_source']);
        self::assertSame(3, $overview['meta']['tracked_visitors']);
        self::assertSame(3, $overview['stats']['visitors']['current']);
        self::assertSame([3, 3], array_slice(array_column($overview['funnel'], 'value'), 0, 2));
        self::assertSame([100, 100.0], array_slice(array_column($overview['funnel'], 'percentage'), 0, 2));
        self::assertSame(3, array_sum(array_column($overview['sources'], 'value')));
        self::assertSame(1, collect($overview['sources'])->firstWhere('key', 'bing')['value']);
        self::assertSame(3, array_sum(array_column($overview['countries'], 'visitors')));
        self::assertSame(1, collect($overview['countries'])->firstWhere('code', 'GB')['visitors']);
        // A visitor can appear in more than one hourly bucket; the period KPI
        // above remains de-duplicated while the time series stays per bucket.
        self::assertSame(4, array_sum($overview['trends']['uv']));
        self::assertGreaterThanOrEqual(3, array_sum($overview['trends']['pv']));
    }

    public function test_empty_funnel_does_not_present_zero_visitors_as_one_hundred_percent(): void
    {
        DB::table('storefront_visits')->delete();
        DB::table('product_views')->delete();
        DB::table('dashboard_events')->delete();
        DB::table('order_products')->delete();
        DB::table('order_histories')->delete();
        DB::table('orders')->delete();

        $funnel = (new DashboardService)->getFunnelData('today');

        self::assertSame(0, $funnel[0]['value']);
        self::assertSame(0, $funnel[0]['percentage']);
        self::assertSame(0.0, $funnel[0]['width']);
        self::assertSame([0, 0, 0, 0, 0], array_column($funnel, 'value'));
    }

    public function test_replayed_paid_history_does_not_move_old_revenue_into_today(): void
    {
        DB::table('orders')->insert([
            'id'         => 4, 'customer_id' => 8, 'email' => 'old@example.com', 'ip' => '10.0.0.4',
            'status'     => 'paid', 'total' => 100, 'created_at' => '2026-10-07 08:00:00',
            'updated_at' => '2026-10-08 11:30:00', 'deleted_at' => null,
        ]);
        DB::table('order_histories')->insert([
            ['order_id' => 4, 'status' => 'paid', 'created_at' => '2026-10-07 09:00:00', 'updated_at' => '2026-10-07 09:00:00'],
            ['order_id' => 4, 'status' => 'paid', 'created_at' => '2026-10-08 11:30:00', 'updated_at' => '2026-10-08 11:30:00'],
        ]);

        $stats = (new DashboardService)->getBasicStats('today');

        self::assertSame(2, $stats['paid_orders']['current']);
        self::assertSame(65.0, $stats['revenue']['current']);
        self::assertSame(32.5, $stats['average_order_value']['current']);
    }

    public function test_gross_sales_preserve_orders_that_were_later_refunded(): void
    {
        DB::table('orders')->insert([
            'id'         => 6, 'customer_id' => 10, 'email' => 'refunded@example.com', 'ip' => '10.0.0.6',
            'status'     => 'refunding', 'total' => 50, 'created_at' => '2026-10-08 08:30:00',
            'updated_at' => '2026-10-08 11:55:00', 'deleted_at' => null,
        ]);
        DB::table('order_histories')->insert([
            ['order_id' => 6, 'status' => 'paid', 'created_at' => '2026-10-08 09:30:00', 'updated_at' => '2026-10-08 09:30:00'],
            ['order_id' => 6, 'status' => 'refunding', 'created_at' => '2026-10-08 11:55:00', 'updated_at' => '2026-10-08 11:55:00'],
        ]);

        $stats = (new DashboardService)->getBasicStats('today');

        self::assertSame(3, $stats['paid_orders']['current']);
        self::assertSame(115.0, $stats['revenue']['current']);
        self::assertSame(38.33, $stats['average_order_value']['current']);
    }

    public function test_legacy_paid_history_without_timestamp_falls_back_to_order_creation_time(): void
    {
        DB::table('orders')->insert([
            'id'         => 7, 'customer_id' => 11, 'email' => 'legacy-time@example.com', 'ip' => '10.0.0.7',
            'status'     => 'paid', 'total' => 70, 'created_at' => '2026-10-07 07:00:00',
            'updated_at' => '2026-10-08 11:58:00', 'deleted_at' => null,
        ]);
        DB::table('order_histories')->insert([
            ['order_id' => 7, 'status' => 'paid', 'created_at' => null, 'updated_at' => null],
            ['order_id' => 7, 'status' => 'paid', 'created_at' => '2026-10-08 11:58:00', 'updated_at' => '2026-10-08 11:58:00'],
        ]);

        $stats = (new DashboardService)->getBasicStats('today');

        self::assertSame(2, $stats['paid_orders']['current']);
        self::assertSame(65.0, $stats['revenue']['current']);
    }

    public function test_conversion_rate_exposes_incomplete_tracking_instead_of_being_capped(): void
    {
        DB::table('orders')->insert([
            'id'         => 5, 'customer_id' => 9, 'email' => 'untracked@example.com', 'ip' => '10.0.0.5',
            'status'     => 'paid', 'total' => 30, 'created_at' => '2026-10-08 11:45:00',
            'updated_at' => '2026-10-08 11:45:00', 'deleted_at' => null,
        ]);

        $stats = (new DashboardService)->getBasicStats('today');

        self::assertSame(3, $stats['purchases']['current']);
        self::assertSame(150.0, $stats['conversion']['visitor-to-purchase']['rate']);
    }

    public function test_cache_keys_are_explicitly_scoped_to_the_current_tenant(): void
    {
        $method = new \ReflectionMethod(DashboardService::class, 'generateCacheKey');
        $key    = (string) config('multitenancy.current_tenant_container_key');

        $first     = new Site;
        $first->id = 101;
        app()->instance($key, $first);
        $firstKey = $method->invoke(new DashboardService, 'basic_stats', 'today');

        $second     = new Site;
        $second->id = 202;
        app()->instance($key, $second);
        $secondKey = $method->invoke(new DashboardService, 'basic_stats', 'today');
        app()->forgetInstance($key);

        self::assertNotSame($firstKey, $secondKey);
        self::assertStringEndsWith(':basic_stats:today', $firstKey);
        self::assertStringEndsWith(':basic_stats:today', $secondKey);
    }

    public function test_cache_keys_are_isolated_by_interface_locale(): void
    {
        $method   = new \ReflectionMethod(DashboardService::class, 'generateCacheKey');
        $original = app()->getLocale();

        try {
            app()->setLocale('zh_cn');
            $chinese = $method->invoke(new DashboardService, 'overview', 'today');
            app()->setLocale('en');
            $english = $method->invoke(new DashboardService, 'overview', 'today');
        } finally {
            app()->setLocale($original);
        }

        self::assertNotSame($chinese, $english);
        self::assertStringContainsString(':zh_cn:overview:today', $chinese);
        self::assertStringContainsString(':en:overview:today', $english);
    }

    public function test_cache_clear_removes_the_atomic_overview_snapshot(): void
    {
        $cacheManager  = app('cache');
        $previousStore = $cacheManager->getDefaultDriver();
        $cacheManager->setDefaultDriver('array');
        Config::set('app.dashboard.enable_cache', true);
        Config::set('app.dashboard.cache_duration', 60);

        try {
            $service = new DashboardService;
            $method  = new \ReflectionMethod(DashboardService::class, 'generateCacheKey');
            $key     = $method->invoke($service, 'overview', 'today');
            Cache::put($key, ['stale' => true], 60);

            self::assertTrue(Cache::has($key));
            $service->clearDashboardCache();
            self::assertFalse(Cache::has($key));
        } finally {
            Cache::flush();
            $cacheManager->setDefaultDriver($previousStore);
            Config::set('app.dashboard.enable_cache', false);
            Config::set('app.dashboard.cache_duration', 0);
        }
    }

    public function test_overview_returns_one_complete_snapshot_and_supports_thirty_days(): void
    {
        $service  = new DashboardService;
        $overview = $service->getOverview('month');

        self::assertSame('month', $overview['stats']['time_range']);
        self::assertCount(30, $overview['trends']['labels']);
        self::assertCount(30, $overview['mini_charts']['visitor']);
        self::assertSame('storefront_visits', $overview['meta']['traffic_source']);
        self::assertSame(2, $overview['meta']['tracked_visitors']);
        self::assertSame(100.0, $overview['meta']['country_coverage_percent']);
        self::assertArrayHasKey('generated_at', $overview['meta']);
        self::assertSame($overview['meta']['generated_at'], $overview['meta']['period_end']);
    }

    public function test_overview_reuses_range_metrics_only_inside_the_current_snapshot(): void
    {
        $service = new DashboardService;

        DB::flushQueryLog();
        DB::enableQueryLog();
        $overview = $service->getOverview('today');
        $queries  = DB::getQueryLog();
        DB::disableQueryLog();

        self::assertSame(2, $overview['stats']['visitors']['current']);

        $currentRangeQueries = static function (array $query): bool {
            $bindings = array_map(static fn ($binding): string => $binding instanceof \DateTimeInterface
                ? $binding->format('Y-m-d H:i:s')
                : (string) $binding, $query['bindings']);

            return in_array('2026-10-08 00:00:00', $bindings, true)
                && in_array('2026-10-08 12:00:00', $bindings, true);
        };

        $visitorCounts = array_filter($queries, static fn (array $query): bool => str_contains(strtolower($query['query']), 'dashboard_unique_visitors')
            && str_contains(strtolower($query['query']), 'storefront_visits')
            && str_contains(strtolower($query['query']), 'product_views')
            && $currentRangeQueries($query));
        $visitChecks = array_filter($queries, static fn (array $query): bool => str_contains(strtolower($query['query']), 'select exists')
            && str_contains(strtolower($query['query']), 'storefront_visits')
            && $currentRangeQueries($query));

        // Basic cards, the funnel and metadata all need the same value. The
        // overview performs one period-level availability check and one
        // visitor count instead of repeating either query for every panel.
        self::assertCount(1, $visitorCounts);
        self::assertCount(1, $visitChecks);

        DB::table('storefront_visits')->insert([
            'bucket_at'           => '2026-10-08 11:00:00',
            'visitor_key'         => str_repeat('c', 64),
            'customer_id'         => null,
            'country_code'        => 'GB',
            'source_key'          => 'direct',
            'source_detail'       => null,
            'referer'             => null,
            'landing_path'        => '/',
            'page_views'          => 1,
            'first_seen_at'       => '2026-10-08 11:30:00',
            'last_seen_at'        => '2026-10-08 11:30:00',
            'human_confirmed_at'  => '2026-10-08 11:30:00',
            'confirmation_method' => 'pointer',
            'created_at'          => '2026-10-08 11:30:00',
            'updated_at'          => '2026-10-08 11:30:00',
        ]);

        // The memo is request-local. A subsequent standalone read must see
        // newly committed traffic immediately.
        self::assertSame(3, $service->getBasicStats('today')['visitors']['current']);
    }

    public function test_csv_export_neutralizes_spreadsheet_formula_values(): void
    {
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);

        $method = new \ReflectionMethod(DashboardService::class, 'putCsvRow');
        $method->invoke(new DashboardService, $stream, [
            '=HYPERLINK("https://example.test")',
            '+1+1',
            '-2+3',
            '@SUM(A1:A2)',
            'Normal product',
            42,
        ]);
        rewind($stream);
        $row = fgetcsv($stream);
        fclose($stream);

        self::assertSame('\'=HYPERLINK("https://example.test")', $row[0]);
        self::assertSame("'+1+1", $row[1]);
        self::assertSame("'-2+3", $row[2]);
        self::assertSame("'@SUM(A1:A2)", $row[3]);
        self::assertSame('Normal product', $row[4]);
        self::assertSame('42', $row[5]);
    }

    private function createSchema(): void
    {
        Schema::create('product_views', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('ip')->nullable();
            $table->string('session_id')->nullable();
            $table->char('visitor_key', 64)->nullable();
            $table->string('referer')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->timestamp('human_confirmed_at')->nullable();
            $table->string('confirmation_method', 16)->nullable();
            $table->timestamps();
        });
        Schema::create('storefront_visits', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('bucket_at');
            $table->char('visitor_key', 64);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('source_key', 32)->default('direct');
            $table->string('source_detail')->nullable();
            $table->string('referer', 1000)->nullable();
            $table->string('landing_path', 1000)->nullable();
            $table->unsignedInteger('page_views')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('human_confirmed_at')->nullable();
            $table->string('confirmation_method', 16)->nullable();
            $table->timestamps();
            $table->unique(['bucket_at', 'visitor_key']);
        });
        Schema::create('dashboard_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('session_id')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('ip')->nullable();
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id')->default(0);
            $table->string('email')->default('');
            $table->string('ip')->default('');
            $table->string('status');
            $table->decimal('total', 16, 4)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('order_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('order_products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id');
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->decimal('price', 16, 4);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function seedMetrics(): void
    {
        DB::table('storefront_visits')->insert([
            [
                'bucket_at'          => '2026-10-08 09:00:00', 'visitor_key' => str_repeat('a', 64), 'customer_id' => 7,
                'country_code'       => 'CN', 'source_key' => 'google', 'source_detail' => 'google.com',
                'referer'            => 'https://google.com/search', 'landing_path' => '/', 'page_views' => 2,
                'first_seen_at'      => '2026-10-08 09:01:00', 'last_seen_at' => '2026-10-08 09:02:00',
                'human_confirmed_at' => '2026-10-08 09:02:00', 'confirmation_method' => 'pointer',
                'created_at'         => '2026-10-08 09:01:00', 'updated_at' => '2026-10-08 09:02:00',
            ],
            [
                'bucket_at'          => '2026-10-08 09:00:00', 'visitor_key' => str_repeat('b', 64), 'customer_id' => null,
                'country_code'       => 'CN', 'source_key' => 'direct', 'source_detail' => null,
                'referer'            => null, 'landing_path' => '/products/2', 'page_views' => 1,
                'first_seen_at'      => '2026-10-08 09:03:00', 'last_seen_at' => '2026-10-08 09:03:00',
                'human_confirmed_at' => '2026-10-08 09:03:00', 'confirmation_method' => 'scroll',
                'created_at'         => '2026-10-08 09:03:00', 'updated_at' => '2026-10-08 09:03:00',
            ],
            [
                // A later bucket for the same visitor increases PV without
                // creating another source visitor. Country ranking uses the
                // latest resolved country in the selected range.
                'bucket_at'          => '2026-10-08 10:00:00', 'visitor_key' => str_repeat('a', 64), 'customer_id' => 7,
                'country_code'       => 'US', 'source_key' => 'google', 'source_detail' => 'google.com',
                'referer'            => 'https://google.com/search', 'landing_path' => '/products/1', 'page_views' => 1,
                'first_seen_at'      => '2026-10-08 10:01:00', 'last_seen_at' => '2026-10-08 10:01:00',
                'human_confirmed_at' => '2026-10-08 10:01:00', 'confirmation_method' => 'scroll',
                'created_at'         => '2026-10-08 10:01:00', 'updated_at' => '2026-10-08 10:01:00',
            ],
        ]);
        DB::table('product_views')->insert([
            ['product_id' => 1, 'customer_id' => 7, 'session_id' => 'member-a', 'visitor_key' => str_repeat('a', 64), 'ip' => '10.0.0.1', 'referer' => 'https://google.com/search', 'country_code' => 'CN', 'human_confirmed_at' => '2026-10-08 09:01:00', 'confirmation_method' => 'pointer', 'created_at' => '2026-10-08 09:01:00', 'updated_at' => '2026-10-08 09:01:00'],
            ['product_id' => 1, 'customer_id' => 7, 'session_id' => 'member-b', 'visitor_key' => str_repeat('a', 64), 'ip' => '10.0.0.2', 'referer' => 'https://google.com/search', 'country_code' => 'CN', 'human_confirmed_at' => '2026-10-08 09:02:00', 'confirmation_method' => 'keyboard', 'created_at' => '2026-10-08 09:02:00', 'updated_at' => '2026-10-08 09:02:00'],
            ['product_id' => 2, 'customer_id' => 0, 'session_id' => 'guest-a', 'visitor_key' => str_repeat('b', 64), 'ip' => '10.0.0.3', 'referer' => null, 'country_code' => 'CN', 'human_confirmed_at' => '2026-10-08 09:03:00', 'confirmation_method' => 'scroll', 'created_at' => '2026-10-08 09:03:00', 'updated_at' => '2026-10-08 09:03:00'],
        ]);
        DB::table('dashboard_events')->insert([
            ['event_type' => 'cart_added', 'product_id' => 1, 'customer_id' => 7, 'session_id' => 'member-a', 'quantity' => 2, 'ip' => '10.0.0.1', 'created_at' => '2026-10-08 09:10:00', 'updated_at' => '2026-10-08 09:10:00'],
            ['event_type' => 'cart_added', 'product_id' => 2, 'customer_id' => 7, 'session_id' => 'member-b', 'quantity' => 1, 'ip' => '10.0.0.2', 'created_at' => '2026-10-08 09:11:00', 'updated_at' => '2026-10-08 09:11:00'],
        ]);
        DB::table('orders')->insert([
            ['id' => 1, 'customer_id' => 7, 'email' => 'buyer@example.com', 'ip' => '10.0.0.1', 'status' => 'paid', 'total' => 20, 'created_at' => '2026-10-07 22:00:00', 'updated_at' => '2026-10-08 10:00:00', 'deleted_at' => null],
            ['id' => 2, 'customer_id' => 0, 'email' => 'pending@example.com', 'ip' => '10.0.0.2', 'status' => 'unpaid', 'total' => 15, 'created_at' => '2026-10-08 09:30:00', 'updated_at' => '2026-10-08 09:30:00', 'deleted_at' => null],
            ['id' => 3, 'customer_id' => 0, 'email' => 'legacy@example.com', 'ip' => '10.0.0.3', 'status' => 'paid', 'total' => 45, 'created_at' => '2026-10-08 11:00:00', 'updated_at' => '2026-10-08 11:00:00', 'deleted_at' => null],
        ]);
        DB::table('order_histories')->insert([
            ['order_id' => 1, 'status' => 'paid', 'created_at' => '2026-10-08 10:00:00', 'updated_at' => '2026-10-08 10:00:00'],
        ]);
        DB::table('order_products')->insert([
            ['order_id' => 1, 'product_id' => 1, 'name' => 'Tracked product', 'quantity' => 2, 'price' => 10, 'created_at' => '2026-10-07 22:00:00', 'updated_at' => '2026-10-07 22:00:00', 'deleted_at' => null],
        ]);
    }
}
