import http from "../../../js/http";

class CommerceDashboard {
  constructor(root, config) {
    this.root = root;
    this.config = config;
    this.currentRange = 'today';
    this.chartMode = 'traffic';
    this.snapshot = null;
    this.requestId = 0;
    this.charts = new Map();
    this.numberFormatter = new Intl.NumberFormat(this.normalizedLocale(), { maximumFractionDigits: 0 });
    this.compactFormatter = new Intl.NumberFormat(this.normalizedLocale(), { notation: 'compact', maximumFractionDigits: 1 });

    this.bindControls();
    this.load();
    this.startAutoRefresh();
  }

  normalizedLocale() {
    return String(this.config.locale || document.documentElement.lang || 'en').replace('_', '-');
  }

  translations(key) {
    return this.config.translations?.[key] || key;
  }

  bindControls() {
    this.root.querySelectorAll('[data-dashboard-range]').forEach((button) => {
      button.addEventListener('click', () => {
        if (button.dataset.dashboardRange === this.currentRange) return;
        this.currentRange = button.dataset.dashboardRange || 'today';
        this.root.querySelectorAll('[data-dashboard-range]').forEach((item) => {
          const active = item === button;
          item.classList.toggle('is-active', active);
          item.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        this.load();
      });
    });

    this.root.querySelector('[data-dashboard-refresh]')?.addEventListener('click', () => this.load());
    this.root.querySelector('[data-dashboard-retry]')?.addEventListener('click', () => this.load());
    this.root.querySelectorAll('[data-dashboard-export]').forEach((button) => {
      button.addEventListener('click', () => this.exportReport(button.dataset.dashboardExport || 'excel'));
    });
    this.root.querySelectorAll('[data-dashboard-chart-mode]').forEach((button) => {
      button.addEventListener('click', () => {
        this.chartMode = button.dataset.dashboardChartMode || 'traffic';
        this.root.querySelectorAll('[data-dashboard-chart-mode]').forEach((item) => {
          const active = item === button;
          item.classList.toggle('is-active', active);
          item.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        this.renderPerformanceChart();
      });
    });
  }

  startAutoRefresh() {
    window.setInterval(() => {
      if (document.visibilityState === 'visible' && this.currentRange === 'today' && !this.root.classList.contains('is-loading')) {
        this.load(true);
      }
    }, 60000);
  }

  async load(silent = false) {
    const requestId = ++this.requestId;
    this.setLoading(true, silent);
    this.hideError();

    try {
      const response = await http.axiosApi.get(this.config.routes.overview, {
        params: { time_range: this.currentRange },
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (requestId !== this.requestId) return;
      if (!response.data?.success || !response.data?.data) {
        throw new Error(response.data?.message || this.translations('refresh_failed'));
      }

      this.snapshot = response.data.data;
      this.render();
    } catch (error) {
      if (requestId !== this.requestId) return;
      const message = error.response?.data?.message || error.message || this.translations('network_error');
      this.showError(message);
    } finally {
      if (requestId === this.requestId) this.setLoading(false, silent);
    }
  }

  setLoading(loading, silent) {
    this.root.classList.toggle('is-loading', loading && !silent);
    this.root.setAttribute('aria-busy', loading ? 'true' : 'false');
    this.root.querySelectorAll('[data-dashboard-range], [data-dashboard-refresh]').forEach((button) => {
      button.disabled = loading;
    });
  }

  render() {
    this.renderMeta();
    this.renderKpis();
    this.renderPerformanceChart();
    this.renderSources();
    this.renderFunnel();
    this.renderPayments();
    this.renderProducts();
    this.renderCountries();
  }

  renderMeta() {
    const meta = this.snapshot.meta || {};
    const status = ['live', 'delayed', 'stale', 'empty'].includes(meta.freshness) ? meta.freshness : 'empty';
    const statusLabel = this.root.querySelector('#dashboard-freshness-label');
    const statusDot = this.root.querySelector('#dashboard-freshness-dot');
    const updated = this.root.querySelector('#dashboard-last-updated');
    const period = this.root.querySelector('#dashboard-period-label');

    if (statusLabel) statusLabel.textContent = this.translations(status);
    if (statusDot) statusDot.className = `commerce-dashboard__status-dot is-${status}`;
    if (updated) updated.textContent = `${this.translations('updated_at')} ${this.formatDateTime(meta.generated_at)}`;
    if (period) period.textContent = `${this.translations(this.currentRange)} · ${this.formatDate(meta.period_start)} — ${this.formatDate(meta.period_end)}`;

    this.setText('#dashboard-tracking-source', this.translations(meta.traffic_source || 'unavailable'));
    this.setText('#dashboard-country-coverage', `${this.formatDecimal(meta.country_coverage_percent)}%`);
    this.setText('#dashboard-tracked-visitors', this.formatNumber(meta.tracked_visitors));
    this.setText('#dashboard-filtered-unverified', this.formatNumber(meta.filtered_unverified));
    this.setText('#dashboard-tracking-started', meta.tracking_started_at ? this.formatDate(meta.tracking_started_at) : '—');
  }

  renderKpis() {
    const stats = this.snapshot.stats || {};
    const conversion = stats.conversion?.['visitor-to-purchase'] || this.emptyMetric();
    const metrics = {
      revenue: { ...this.metric(stats.revenue), value: this.formatCurrency(stats.revenue?.current, stats.currency), series: this.snapshot.mini_charts?.revenue || [] },
      paid_orders: { ...this.metric(stats.paid_orders), value: this.formatNumber(stats.paid_orders?.current), series: this.snapshot.mini_charts?.purchase || [] },
      visitors: { ...this.metric(stats.visitors), value: this.formatNumber(stats.visitors?.current), series: this.snapshot.mini_charts?.visitor || [] },
      carts: { ...this.metric(stats.carts), value: this.formatNumber(stats.carts?.current), series: this.snapshot.mini_charts?.cart || [] },
      conversion: { ...this.metric(conversion, 'change'), value: `${this.formatDecimal(conversion.rate)}%`, series: this.snapshot.mini_charts?.conversion || [] },
      average_order_value: { ...this.metric(stats.average_order_value), value: this.formatCurrency(stats.average_order_value?.current, stats.currency), series: this.averageOrderSeries() },
    };

    Object.entries(metrics).forEach(([key, metric]) => {
      const card = this.root.querySelector(`[data-kpi="${key}"]`);
      if (!card) return;
      const value = card.querySelector('[data-kpi-value]');
      const trend = card.querySelector('[data-kpi-trend]');
      if (value) {
        value.textContent = metric.value;
        value.classList.remove('dashboard-skeleton');
      }
      if (trend) {
        const percentage = Number(metric.percentage || 0);
        trend.textContent = percentage > 0 ? `↑ ${this.formatDecimal(percentage)}%` : percentage < 0 ? `↓ ${this.formatDecimal(Math.abs(percentage))}%` : '0%';
        trend.className = `commerce-kpi__trend ${percentage > 0 ? 'is-up' : percentage < 0 ? 'is-down' : 'is-neutral'}`;
      }
      this.renderSparkline(`kpi-chart-${key}`, metric.series, key);
    });
  }

  metric(value, percentageKey = 'percentage') {
    const normalized = value || this.emptyMetric();
    return { percentage: Number(normalized[percentageKey] || 0), trend: normalized.trend || 'up' };
  }

  emptyMetric() {
    return { current: 0, previous: 0, percentage: 0, change: 0, trend: 'up' };
  }

  averageOrderSeries() {
    const revenue = this.snapshot.mini_charts?.revenue || [];
    const orders = this.snapshot.mini_charts?.purchase || [];
    return revenue.map((value, index) => Number(orders[index] || 0) > 0 ? Number(value || 0) / Number(orders[index]) : 0);
  }

  renderSparkline(canvasId, values, key) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || typeof Chart === 'undefined') return;
    this.destroyChart(canvasId);
    const color = this.chartColor(key);
    const chart = new Chart(canvas.getContext('2d'), {
      type: 'line',
      data: {
        labels: values.map((_, index) => index + 1),
        datasets: [{ data: values.map(Number), borderColor: color, backgroundColor: this.withAlpha(color, .09), borderWidth: 1.7, fill: true, pointRadius: 0, tension: .35 }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        plugins: { legend: { display: false }, tooltip: { enabled: false } },
        scales: { x: { display: false }, y: { display: false, beginAtZero: true } },
      },
    });
    this.charts.set(canvasId, chart);
  }

  renderPerformanceChart() {
    if (!this.snapshot || typeof Chart === 'undefined') return;
    const canvas = document.getElementById('dashboard-performance-chart');
    if (!canvas) return;
    this.destroyChart('performance');
    const trends = this.snapshot.trends || {};
    const salesMode = this.chartMode === 'sales';
    const textColor = this.cssVariable('--dashboard-muted');
    const gridColor = this.cssVariable('--dashboard-border');
    const primary = this.cssVariable('--dashboard-primary');
    const blue = this.cssVariable('--dashboard-blue');

    const datasets = salesMode ? [
      { label: this.translations('gross_sales'), data: trends.revenue || [], borderColor: primary, backgroundColor: this.withAlpha(primary, .1), fill: true, tension: .34, pointRadius: 0, borderWidth: 2, yAxisID: 'y' },
      { label: this.translations('paid_orders'), data: trends.orders || [], borderColor: blue, backgroundColor: blue, fill: false, tension: .34, pointRadius: 0, borderWidth: 2, yAxisID: 'yOrders' },
    ] : [
      { label: this.translations('page_views'), data: trends.pv || [], borderColor: primary, backgroundColor: this.withAlpha(primary, .1), fill: true, tension: .34, pointRadius: 0, borderWidth: 2 },
      { label: this.translations('unique_visitors'), data: trends.uv || [], borderColor: blue, backgroundColor: blue, fill: false, tension: .34, pointRadius: 0, borderWidth: 2 },
    ];

    const scales = {
      x: { grid: { display: false }, ticks: { color: textColor, maxTicksLimit: this.currentRange === 'month' ? 10 : 12, maxRotation: 0 } },
      y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor, callback: (value) => salesMode ? this.formatCompactCurrency(value) : this.compactFormatter.format(value) } },
    };
    if (salesMode) scales.yOrders = { beginAtZero: true, position: 'right', grid: { display: false }, ticks: { color: textColor, precision: 0 } };

    const chart = new Chart(canvas.getContext('2d'), {
      type: 'line',
      data: { labels: trends.labels || [], datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { intersect: false, mode: 'index' },
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: (context) => salesMode && context.datasetIndex === 0 ? `${context.dataset.label}: ${this.formatCurrency(context.raw)}` : `${context.dataset.label}: ${this.formatNumber(context.raw)}` } } },
        scales,
      },
    });
    this.charts.set('performance', chart);
    this.renderPerformanceSummary();
  }

  renderPerformanceSummary() {
    const target = this.root.querySelector('#dashboard-performance-summary');
    if (!target) return;
    target.replaceChildren();
    const stats = this.snapshot.stats || {};
    const items = this.chartMode === 'sales'
      ? [[this.translations('gross_sales'), this.formatCurrency(stats.revenue?.current, stats.currency)], [this.translations('paid_orders'), this.formatNumber(stats.paid_orders?.current)]]
      : [[this.translations('page_views'), this.formatNumber((this.snapshot.trends?.pv || []).reduce((sum, value) => sum + Number(value || 0), 0))], [this.translations('unique_visitors'), this.formatNumber(stats.visitors?.current)]];
    items.forEach(([label, value]) => {
      const item = document.createElement('span');
      item.append(`${label} `);
      const strong = document.createElement('strong');
      strong.textContent = value;
      item.append(strong);
      target.append(item);
    });
  }

  renderSources() {
    const target = this.root.querySelector('#dashboard-source-list');
    if (!target) return;
    target.replaceChildren();
    const sources = Array.isArray(this.snapshot.sources) ? this.snapshot.sources : [];
    if (!sources.length || sources.every((source) => Number(source.value || 0) === 0)) return this.renderEmpty(target);
    sources.slice(0, 8).forEach((source) => {
      const row = this.element('div', 'commerce-ranking__item');
      row.append(this.textElement('div', 'commerce-ranking__name', source.name));
      const track = this.element('div', 'commerce-ranking__track');
      const fill = this.element('span', 'commerce-ranking__fill');
      fill.style.width = `${Math.min(100, Math.max(0, Number(source.percentage || 0)))}%`;
      track.append(fill);
      row.append(track);
      row.append(this.textElement('div', 'commerce-ranking__value', `${this.formatNumber(source.value)} · ${this.formatDecimal(source.percentage)}%`));
      target.append(row);
    });
  }

  renderFunnel() {
    const target = this.root.querySelector('#dashboard-funnel-list');
    if (!target) return;
    target.replaceChildren();
    const funnel = Array.isArray(this.snapshot.funnel) ? this.snapshot.funnel : [];
    if (!funnel.length) return this.renderEmpty(target);
    funnel.forEach((stage, index) => {
      const row = this.element('div', 'commerce-funnel__item');
      row.append(this.textElement('div', 'commerce-funnel__label', stage.name));
      const track = this.element('div', 'commerce-funnel__track');
      const fill = this.element('span', 'commerce-funnel__fill');
      fill.style.width = `${Math.min(100, Math.max(0, Number(stage.width || 0)))}%`;
      fill.style.opacity = String(Math.max(.45, 1 - index * .11));
      track.append(fill);
      row.append(track);
      const value = this.element('div', 'commerce-funnel__value');
      value.append(this.textElement('strong', '', this.formatNumber(stage.value)));
      value.append(`${this.formatDecimal(stage.percentage)}%`);
      row.append(value);
      target.append(row);
    });
  }

  renderPayments() {
    const payments = this.snapshot.payments || {};
    const state = ['ready', 'disabled', 'unavailable'].includes(payments.state) ? payments.state : 'unavailable';
    const stateTarget = this.root.querySelector('#dashboard-payment-state');
    if (stateTarget) {
      stateTarget.textContent = this.translations(`payment_${state}`);
      stateTarget.className = `commerce-payment-status is-${state}`;
    }

    const scopeKey = payments.scope === 'all_sites' ? 'payment_scope_all_sites' : 'payment_scope_current_site';
    this.setText('#dashboard-payment-scope', this.translations(scopeKey));

    const providers = Array.isArray(payments.providers) ? payments.providers : [];
    const providerNames = providers.map((provider) => this.translations(provider)).filter(Boolean);
    this.setText(
      '#dashboard-payment-providers',
      providerNames.length
        ? `${this.translations('payment_providers')}: ${providerNames.join(' / ')}`
        : this.translations('payment_no_providers'),
    );
    this.setText('#dashboard-payment-started', this.formatNumber(payments.started));
    this.setText('#dashboard-payment-completed', this.formatNumber(payments.completed));
    this.setText('#dashboard-payment-rate', `${this.formatDecimal(payments.completion_rate)}%`);
    this.setText('#dashboard-payment-processing', this.formatNumber(payments.processing));
    this.setText('#dashboard-payment-abandoned', this.formatNumber(payments.abandoned));
    this.setText('#dashboard-payment-attention', this.formatNumber(payments.needs_attention));

    const breakdown = payments.by_provider && typeof payments.by_provider === 'object'
      ? Object.entries(payments.by_provider)
        .filter(([provider, count]) => ['paypal', 'stripe'].includes(provider) && Number(count) > 0)
        .map(([provider, count]) => `${this.translations(provider)} ${this.formatNumber(count)}`)
      : [];
    this.setText(
      '#dashboard-payment-breakdown',
      breakdown.length
        ? `${this.translations('payment_provider_breakdown')}: ${breakdown.join(' · ')}`
        : this.translations('payment_provider_breakdown') + ': —',
    );
  }

  renderProducts() {
    const target = this.root.querySelector('#dashboard-products-table');
    if (!target) return;
    target.replaceChildren();
    const products = Array.isArray(this.snapshot.products) ? this.snapshot.products : [];
    if (!products.length) {
      const row = document.createElement('tr');
      const cell = this.textElement('td', 'commerce-dashboard__empty', this.translations('no_data'));
      cell.colSpan = 3;
      row.append(cell);
      return target.append(row);
    }
    products.forEach((product, index) => {
      const row = document.createElement('tr');
      const productCell = document.createElement('td');
      const identity = this.element('div', 'commerce-table__product');
      identity.append(this.textElement('span', 'commerce-table__rank', index + 1));
      identity.append(this.textElement('span', 'commerce-table__name', product.name));
      productCell.append(identity);
      row.append(productCell);
      const quantity = this.textElement('td', 'text-end', this.formatNumber(product.total_sold));
      const amount = this.textElement('td', 'text-end fw-semibold', product.sales_amount_format || this.formatCurrency(product.sales_amount));
      row.append(quantity, amount);
      target.append(row);
    });
  }

  renderCountries() {
    const target = this.root.querySelector('#dashboard-country-list');
    if (!target) return;
    target.replaceChildren();
    const countries = Array.isArray(this.snapshot.countries) ? this.snapshot.countries : [];
    if (!countries.length) return this.renderEmpty(target);
    countries.forEach((country) => {
      const row = this.element('div', 'commerce-country');
      const identity = this.element('div', 'commerce-country__identity');
      identity.append(this.textElement('span', 'commerce-country__code', country.code));
      identity.append(this.textElement('span', 'commerce-country__name', country.name));
      row.append(identity);
      const track = this.element('div', 'commerce-country__track');
      const fill = this.element('span', 'commerce-country__fill');
      fill.style.width = `${Math.min(100, Math.max(0, Number(country.percentage || 0)))}%`;
      track.append(fill);
      row.append(track);
      row.append(this.textElement('div', 'commerce-country__value', `${this.formatNumber(country.visitors)} · ${this.formatDecimal(country.percentage)}%`));
      target.append(row);
    });
  }

  async exportReport(format) {
    const button = this.root.querySelector(`[data-dashboard-export="${format}"]`);
    if (button) button.disabled = true;
    try {
      const params = new URLSearchParams({ time_range: this.currentRange, format });
      const response = await http.axiosApi.post(this.config.routes.export, params, {
        responseType: 'blob',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      });
      const disposition = response.headers?.['content-disposition'] || '';
      const match = disposition.match(/filename\*?=(?:UTF-8''|\")?([^\";]+)/i);
      const filename = match ? decodeURIComponent(match[1].replace(/\"/g, '')) : `longos-dashboard-${this.currentRange}.${format === 'excel' ? 'xlsx' : 'csv'}`;
      const url = URL.createObjectURL(response.data);
      const link = document.createElement('a');
      link.href = url;
      link.download = filename;
      document.body.append(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    } catch (error) {
      this.showError(this.translations('export_failed'));
    } finally {
      if (button) button.disabled = false;
    }
  }

  applyTheme() {
    this.charts.forEach((chart) => chart.destroy());
    this.charts.clear();
    if (this.snapshot) {
      this.renderKpis();
      this.renderPerformanceChart();
    }
  }

  showError(message) {
    const alert = this.root.querySelector('#dashboard-alert');
    this.setText('#dashboard-alert-message', message);
    if (alert) alert.hidden = false;
  }

  hideError() {
    const alert = this.root.querySelector('#dashboard-alert');
    if (alert) alert.hidden = true;
  }

  renderEmpty(target) {
    target.replaceChildren(this.textElement('div', 'commerce-dashboard__empty', this.translations('no_data')));
  }

  setText(selector, value) {
    const element = this.root.querySelector(selector);
    if (element) element.textContent = value;
  }

  element(tag, className) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    return element;
  }

  textElement(tag, className, value) {
    const element = this.element(tag, className);
    element.textContent = value ?? '—';
    return element;
  }

  destroyChart(key) {
    this.charts.get(key)?.destroy();
    this.charts.delete(key);
  }

  cssVariable(name) {
    return getComputedStyle(this.root).getPropertyValue(name).trim();
  }

  chartColor(key) {
    const colors = {
      revenue: '--dashboard-primary', paid_orders: '--dashboard-blue', visitors: '--dashboard-blue', carts: '--dashboard-primary', conversion: '--dashboard-success', average_order_value: '--dashboard-violet',
    };
    return this.cssVariable(colors[key] || '--dashboard-primary');
  }

  withAlpha(color, alpha) {
    if (!color.startsWith('#')) return color;
    const normalized = color.slice(1);
    const hex = normalized.length === 3 ? normalized.split('').map((char) => char + char).join('') : normalized;
    const value = Number.parseInt(hex, 16);
    return `rgba(${(value >> 16) & 255}, ${(value >> 8) & 255}, ${value & 255}, ${alpha})`;
  }

  formatNumber(value) {
    return this.numberFormatter.format(Number(value || 0));
  }

  formatDecimal(value) {
    return new Intl.NumberFormat(this.normalizedLocale(), { minimumFractionDigits: 0, maximumFractionDigits: 1 }).format(Number(value || 0));
  }

  formatCurrency(value, currency = 'USD') {
    try {
      return new Intl.NumberFormat(this.normalizedLocale(), { style: 'currency', currency: currency || 'USD', maximumFractionDigits: 2 }).format(Number(value || 0));
    } catch (_) {
      return `$${this.formatDecimal(value)}`;
    }
  }

  formatCompactCurrency(value) {
    return `$${this.compactFormatter.format(Number(value || 0))}`;
  }

  formatDate(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat(this.normalizedLocale(), {
      timeZone: this.config.timezone || 'UTC', month: 'short', day: 'numeric',
    }).format(new Date(value));
  }

  formatDateTime(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat(this.normalizedLocale(), {
      timeZone: this.config.timezone || 'UTC', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
    }).format(new Date(value));
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const root = document.getElementById('commerce-dashboard');
  if (!root || !window.dashboardConfig || typeof Chart === 'undefined') return;
  window.bkDashboard = new CommerceDashboard(root, window.dashboardConfig);
});

const previousThemeToggle = window.applyThemeToggle;
window.applyThemeToggle = (theme, save, checkedTheme) => {
  if (typeof previousThemeToggle === 'function') previousThemeToggle(theme, save, checkedTheme);
  window.bkDashboard?.applyTheme();
};
