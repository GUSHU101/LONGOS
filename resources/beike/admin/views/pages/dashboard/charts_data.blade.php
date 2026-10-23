@push('header')
  <script src="{{ asset('vendor/chart/chart.min.js') }}"></script>
@endpush

@hook('admin.dashboard.charts_data.before')

<section class="commerce-dashboard" id="commerce-dashboard" aria-busy="true">
  <header class="commerce-dashboard__header">
    <div class="commerce-dashboard__heading">
      <div class="commerce-dashboard__eyebrow">LONGOS COMMERCE</div>
      <h1>{{ __('admin/dashboard.operations_overview') }}</h1>
      <p>{{ __('admin/dashboard.page_title') }}</p>
      <div class="commerce-dashboard__status" aria-live="polite">
        <span class="commerce-dashboard__status-dot" id="dashboard-freshness-dot" aria-hidden="true"></span>
        <span id="dashboard-freshness-label">{{ __('admin/dashboard.loading') }}</span>
        <span class="commerce-dashboard__status-separator" aria-hidden="true"></span>
        <span id="dashboard-last-updated">{{ __('admin/dashboard.preparing_snapshot') }}</span>
      </div>
    </div>

    <div class="commerce-dashboard__toolbar" aria-label="{{ __('admin/dashboard.dashboard_controls') }}">
      <div class="commerce-dashboard__range" role="group" aria-label="{{ __('admin/dashboard.time_range') }}">
        @foreach ([
          'today' => __('admin/dashboard.today'),
          'yesterday' => __('admin/dashboard.yesterday'),
          'week' => __('admin/dashboard.near_7_days'),
          'month' => __('admin/dashboard.near_30_days'),
        ] as $range => $label)
          <button type="button" class="commerce-dashboard__range-button {{ $range === 'today' ? 'is-active' : '' }}"
            data-dashboard-range="{{ $range }}" aria-pressed="{{ $range === 'today' ? 'true' : 'false' }}">
            {{ $label }}
          </button>
        @endforeach
      </div>

      <div class="dropdown">
        <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="bi bi-download" aria-hidden="true"></i>
          {{ __('admin/dashboard.export_report') }}
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><button type="button" class="dropdown-item" data-dashboard-export="excel">Excel (.xlsx)</button></li>
          <li><button type="button" class="dropdown-item" data-dashboard-export="csv">CSV (.csv)</button></li>
        </ul>
      </div>

      <button type="button" class="btn btn-primary" data-dashboard-refresh>
        <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
        <span>{{ __('admin/dashboard.refresh_data') }}</span>
      </button>
    </div>
  </header>

  <div class="commerce-dashboard__alert" id="dashboard-alert" role="alert" hidden>
    <div>
      <strong>{{ __('admin/dashboard.data_load_failed') }}</strong>
      <span id="dashboard-alert-message">{{ __('admin/dashboard.network_error') }}</span>
    </div>
    <button type="button" class="btn btn-sm btn-outline-danger" data-dashboard-retry>{{ __('admin/dashboard.retry') }}</button>
  </div>

  <section class="commerce-dashboard__kpis" aria-label="{{ __('admin/dashboard.key_metrics') }}">
    @foreach ([
      ['key' => 'revenue', 'label' => __('admin/dashboard.gross_sales'), 'icon' => 'bi-currency-dollar', 'tone' => 'revenue'],
      ['key' => 'paid_orders', 'label' => __('admin/dashboard.paid_orders'), 'icon' => 'bi-receipt', 'tone' => 'orders'],
      ['key' => 'visitors', 'label' => __('admin/dashboard.unique_visitors'), 'icon' => 'bi-people', 'tone' => 'visitors'],
      ['key' => 'carts', 'label' => __('admin/dashboard.cart_count'), 'icon' => 'bi-bag-plus', 'tone' => 'carts'],
      ['key' => 'conversion', 'label' => __('admin/dashboard.conversion_rate'), 'icon' => 'bi-graph-up-arrow', 'tone' => 'conversion'],
      ['key' => 'average_order_value', 'label' => __('admin/dashboard.average_order_value'), 'icon' => 'bi-wallet2', 'tone' => 'aov'],
    ] as $metric)
      <article class="commerce-kpi commerce-kpi--{{ $metric['tone'] }}" data-kpi="{{ $metric['key'] }}">
        <div class="commerce-kpi__topline">
          <span class="commerce-kpi__label">{{ $metric['label'] }}</span>
          <span class="commerce-kpi__icon" aria-hidden="true"><i class="bi {{ $metric['icon'] }}"></i></span>
        </div>
        <div class="commerce-kpi__value dashboard-skeleton" data-kpi-value>—</div>
        <div class="commerce-kpi__comparison" data-kpi-comparison>
          <span class="commerce-kpi__trend" data-kpi-trend>—</span>
          <span>{{ __('admin/dashboard.compared_with_previous_period') }}</span>
        </div>
        <div class="commerce-kpi__sparkline" aria-hidden="true">
          <canvas id="kpi-chart-{{ $metric['key'] }}"></canvas>
        </div>
      </article>
    @endforeach
  </section>

  <div class="commerce-dashboard__grid commerce-dashboard__grid--primary">
    <article class="commerce-panel commerce-panel--trend">
      <header class="commerce-panel__header">
        <div>
          <h2>{{ __('admin/dashboard.performance_trend') }}</h2>
          <p id="dashboard-period-label">{{ __('admin/dashboard.loading') }}</p>
        </div>
        <div class="commerce-panel__tabs" role="group" aria-label="{{ __('admin/dashboard.chart_metric') }}">
          <button type="button" class="is-active" data-dashboard-chart-mode="traffic" aria-pressed="true">{{ __('admin/dashboard.traffic') }}</button>
          <button type="button" data-dashboard-chart-mode="sales" aria-pressed="false">{{ __('admin/dashboard.sales') }}</button>
        </div>
      </header>
      <div class="commerce-panel__chart">
        <canvas id="dashboard-performance-chart"></canvas>
      </div>
      <div class="commerce-panel__legend" id="dashboard-performance-summary" aria-live="polite"></div>
    </article>

    <aside class="commerce-panel commerce-panel--quality">
      <header class="commerce-panel__header">
        <div>
          <h2>{{ __('admin/dashboard.data_quality') }}</h2>
          <p>{{ __('admin/dashboard.data_quality_description') }}</p>
        </div>
      </header>
      <dl class="commerce-quality-list">
        <div><dt>{{ __('admin/dashboard.tracking_source') }}</dt><dd id="dashboard-tracking-source">—</dd></div>
        <div><dt>{{ __('admin/dashboard.country_resolution') }}</dt><dd id="dashboard-country-coverage">—</dd></div>
        <div><dt>{{ __('admin/dashboard.tracked_visitors') }}</dt><dd id="dashboard-tracked-visitors">—</dd></div>
        <div><dt>{{ __('admin/dashboard.tracking_started') }}</dt><dd id="dashboard-tracking-started">—</dd></div>
      </dl>
      <p class="commerce-panel__note">{{ __('admin/dashboard.data_quality_note') }}</p>
    </aside>
  </div>

  <div class="commerce-dashboard__grid commerce-dashboard__grid--analysis">
    <article class="commerce-panel">
      <header class="commerce-panel__header">
        <div><h2>{{ __('admin/dashboard.customer_source_analysis') }}</h2><p>{{ __('admin/dashboard.first_touch_attribution') }}</p></div>
      </header>
      <div class="commerce-ranking" id="dashboard-source-list" aria-live="polite"></div>
    </article>

    <article class="commerce-panel">
      <header class="commerce-panel__header">
        <div><h2>{{ __('admin/dashboard.conversion_funnel') }}</h2><p>{{ __('admin/dashboard.unique_identity_funnel') }}</p></div>
      </header>
      <div class="commerce-funnel" id="dashboard-funnel-list" aria-live="polite"></div>
    </article>
  </div>

  <div class="commerce-dashboard__grid commerce-dashboard__grid--tables">
    <article class="commerce-panel commerce-panel--products">
      <header class="commerce-panel__header">
        <div><h2>{{ __('admin/dashboard.hot_products') }}</h2><p>{{ __('admin/dashboard.paid_sales_ranking') }}</p></div>
        <a class="commerce-panel__link" href="{{ admin_route('products.index') }}">{{ __('admin/dashboard.view_all_products') }}</a>
      </header>
      <div class="table-responsive">
        <table class="commerce-table">
          <thead><tr>
            <th scope="col">{{ __('admin/dashboard.product_name') }}</th>
            <th scope="col" class="text-end">{{ __('admin/dashboard.sales') }}</th>
            <th scope="col" class="text-end">{{ __('admin/dashboard.sales_amount') }}</th>
          </tr></thead>
          <tbody id="dashboard-products-table"></tbody>
        </table>
      </div>
    </article>

    <article class="commerce-panel commerce-panel--countries">
      <header class="commerce-panel__header">
        <div><h2>{{ __('admin/dashboard.visitor_country_ranking') }}</h2><p>{{ __('admin/dashboard.unique_visitor_distribution') }}</p></div>
      </header>
      <div class="commerce-country-list" id="dashboard-country-list" aria-live="polite"></div>
    </article>
  </div>

  <footer class="commerce-dashboard__footer">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    <span>{{ __('admin/dashboard.metric_definition_note') }}</span>
  </footer>
</section>

@hook('admin.dashboard.charts_data.bottom.after')

@push('footer')
  <script>
    window.dashboardConfig = {!! json_encode([
      'routes' => [
        'overview' => admin_route('dashboard.overview'),
        'export' => admin_route('dashboard.export'),
      ],
      'locale' => app()->getLocale(),
      'timezone' => config('app.timezone', 'UTC'),
      'translations' => [
        'loading' => __('admin/dashboard.loading'),
        'no_data' => __('admin/dashboard.no_data'),
        'network_error' => __('admin/dashboard.network_error'),
        'refresh_failed' => __('admin/dashboard.refresh_failed'),
        'live' => __('admin/dashboard.freshness_live'),
        'delayed' => __('admin/dashboard.freshness_delayed'),
        'stale' => __('admin/dashboard.freshness_stale'),
        'empty' => __('admin/dashboard.freshness_empty'),
        'updated_at' => __('admin/dashboard.updated_at'),
        'page_views' => __('admin/dashboard.page_views'),
        'unique_visitors' => __('admin/dashboard.unique_visitors'),
        'paid_orders' => __('admin/dashboard.paid_orders'),
        'gross_sales' => __('admin/dashboard.gross_sales'),
        'visitors' => __('admin/dashboard.visits'),
        'orders' => __('admin/dashboard.orders'),
        'conversion' => __('admin/dashboard.conversion_rate'),
        'of_total' => __('admin/dashboard.of_total'),
        'storefront_visits' => __('admin/dashboard.source_visit_ledger'),
        'product_views' => __('admin/dashboard.source_legacy_views'),
        'combined' => __('admin/dashboard.source_combined_ledger'),
        'unavailable' => __('admin/dashboard.source_unavailable'),
        'export_failed' => __('admin/dashboard.export_report_failed_plain'),
        'today' => __('admin/dashboard.today'),
        'yesterday' => __('admin/dashboard.yesterday'),
        'week' => __('admin/dashboard.near_7_days'),
        'month' => __('admin/dashboard.near_30_days'),
      ],
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
  </script>
@endpush
