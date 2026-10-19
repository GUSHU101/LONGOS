@extends('admin::layouts.master')

@section('title', __('admin/common.settings.checkout'))

@section('content-area-class', 'w-max-1200')

@section('page-title-back', admin_route('settings.index'))

@section('head-form-btns', true)

@section('content')
  @if (session('success'))
    <x-admin-alert type="success" msg="{{ session('success') }}" class="mt-4"/>
  @endif
  @if (session('error'))
    <div class="alert alert-danger">
      {{ session('error') }}
    </div>
  @endif
  <div class="card h-min-600">
    <div class="card-body">
      @hook('admin.setting.checkout.content.before')
      <form action="{{ admin_route('settings.store') }}" class="needs-validation" novalidate method="POST" id="form-app">
        @csrf
          <input type="hidden" name="return_url" value="{{ url()->full() }}"/>

          @hook('admin.setting.checkout.before')
          <x-admin-form-switch name="show_price_after_login" title="{{ __('admin/setting.show_price_after_login') }}" value="{{ old('show_price_after_login', system_setting('base.show_price_after_login', '0')) }}">
            <div class="help-text font-size-12 lh-base show-price-error-text">{{ __('admin/setting.show_price_after_login_tips') }}</div>
          </x-admin-form-switch>

          <x-admin-form-switch name="guest_checkout" title="{{ __('admin/setting.guest_checkout') }}" value="{{ old('guest_checkout', system_setting('base.guest_checkout', '1')) }}" />

          <x-admin-form-switch name="customer_approved" title="{{ __('admin/setting.customer_approved') }}" value="{{ old('customer_approved', system_setting('base.customer_approved', '0')) }}">
          </x-admin-form-switch>

          <x-admin-form-switch name="address_phoner_equired" title="{{ __('admin/setting.address_phoner_equired') }}" value="{{ old('address_phoner_equired', system_setting('base.address_phoner_equired', '0')) }}" />

          <x-admin-form-switch name="address_post_code" title="{{ __('admin/setting.address_post_code') }}" value="{{ old('address_post_code', system_setting('base.address_post_code', '0')) }}" />

          <x-admin-form-switch name="tax" title="{{ __('admin/setting.enable_tax') }}" value="{{ old('tax', system_setting('base.tax', '0')) }}">
            <div class="help-text font-size-12 lh-base">{{ __('admin/setting.enable_tax_info') }}</div>
          </x-admin-form-switch>

          <x-admin-form-select title="{{ __('admin/setting.tax_address') }}" name="tax_address" :value="old('tax_address', system_setting('base.tax_address', 'shipping'))" :options="$tax_address">
          </x-admin-form-select>

          <x-admin-form-input name="rate_api_key" type="password" autocomplete="new-password" title="{{ __('admin/setting.rate_api_key') }}" value="" placeholder="{{ system_setting('base.rate_api_key', '') ? __('admin/setting.rate_api_key_keep') : __('admin/setting.rate_api_key') }}">
            <div class="help-text font-size-12 lh-base">
              {{ __('admin/setting.rate_api_key_help') }}
              <a class="text-secondary" href="https://www.exchangerate-api.com/" target="_blank" rel="noopener noreferrer">www.exchangerate-api.com</a>
            </div>
          </x-admin-form-input>

          @if (($currency_rate_status['available'] ?? false))
            <div class="alert {{ ($currency_rate_status['fresh'] ?? false) ? 'alert-success' : 'alert-warning' }} py-2 mb-4" role="status">
              {{ __('admin/setting.currency_rate_status', [
                'covered' => $currency_rate_status['covered'] ?? 0,
                'time' => $currency_rate_status['fetched_at'] ?? '-',
              ]) }}
            </div>
          @else
            <div class="alert alert-warning py-2 mb-4" role="status">{{ __('admin/setting.currency_rate_status_unavailable') }}</div>
          @endif

          @if (!($currency_rate_status['storage_ready'] ?? false))
            <div class="alert alert-danger py-2 mb-4" role="alert">
              汇率存储未就绪：当前租户数据库缺少 <code>exchange_rates</code> 表或无法读取该表。请先完成租户数据库升级。
              @if (!empty($currency_rate_status['storage_missing']))
                诊断项：<code>{{ implode(', ', $currency_rate_status['storage_missing']) }}</code>。
              @endif
            </div>
          @endif

          @if (system_setting('base.rate_api_key', ''))
            <x-admin::form.row title="">
              <button type="button" id="refresh-currency-rates" class="btn btn-outline-primary">
                <i class="bi bi-arrow-repeat"></i> 立即验证并刷新汇率
              </button>
              <span id="refresh-currency-rates-status" class="help-text font-size-12 ms-2" aria-live="polite"></span>
            </x-admin::form.row>
          @endif

          <x-admin::form.row title="前台自动本地货币">
            @php
              $rateReady = (bool) ($currency_rate_status['available'] ?? false);
              $storageReady = (bool) ($currency_rate_status['storage_ready'] ?? false);
              $country = $currency_geo_status['detected_country'] ?? null;
              $detectedCurrency = $currency_geo_status['detected_currency'] ?? 'USD';
              $cdnReady = (bool) ($currency_geo_status['cdn_headers_enabled'] ?? false);
              $cloudflareVerified = (bool) ($currency_geo_status['cloudflare_verified'] ?? false);
              $geoLiteReady = (bool) ($currency_geo_status['local_database_available'] ?? false);
              $geoLiteInstalled = (bool) ($currency_geo_status['local_database_installed'] ?? false);
              $detectionReady = (bool) ($currency_geo_status['detection_ready'] ?? false);
              $fullyReady = $storageReady && $rateReady && $detectionReady;
              $blockingIssue = $currency_geo_status['blocking_issue'] ?? null;
              $blockingMessage = match ($blockingIssue) {
                'untrusted_country_header' => '已收到 CF-IPCountry，但请求未经过可验证的 Cloudflare 边缘，因安全原因已忽略该请求头。',
                'missing_country_header' => '当前请求已确认来自 Cloudflare，但未收到有效的 CF-IPCountry。',
                'invalid_geolite_database' => 'GeoLite2 文件存在但无法打开，可能文件损坏或权限不足。',
                'country_sources_disabled' => 'Cloudflare 国家请求头未启用，且未安装可用的 GeoLite2 国家库。',
                'no_country_source' => '当前域名请求未经过可信 Cloudflare，且未安装可用的 GeoLite2 国家库。',
                default => null,
              };
            @endphp
            <div class="form-control-plaintext fw-semibold {{ $fullyReady ? 'text-success' : 'text-warning' }}">
              {{ $fullyReady ? '运行中：汇率和访客国家识别均已就绪' : '待就绪：请根据下方诊断完成配置' }}
            </div>
            <div class="help-text font-size-12 lh-base mt-1">
              运行策略：<code>{{ $currency_rate_status['policy_revision'] ?? $currency_geo_status['policy_revision'] ?? '-' }}</code>。
              Cloudflare 国家识别配置 {{ $cdnReady ? '已启用' : '未启用' }}；当前请求 {{ $cloudflareVerified ? '已验证来自 Cloudflare' : '未验证为 Cloudflare 流量' }}；
              GeoLite2 本地库 {{ $geoLiteReady ? '可用' : ($geoLiteInstalled ? '已安装但不可用' : '未安装') }}。
              已覆盖 {{ $currency_geo_status['mapped_countries'] ?? 0 }} 个国家与地区。
              @if ($country)
                当前请求通过 {{ $currency_geo_status['resolution_source'] ?? '-' }} 检测为 {{ $country }} → {{ $detectedCurrency }}。
              @else
                当前请求未获得可信国家信息。
              @endif
              订单基准金额始终保存为 USD，创建订单时会冻结当时的显示货币和汇率。
            </div>
            @if ($blockingMessage)
              <div class="alert alert-warning py-2 mt-2 mb-0" role="alert">{{ $blockingMessage }} 在此问题解决前，前台会安全回退为 USD。</div>
            @endif
          </x-admin::form.row>

          <x-admin::form.row :title="__('admin/setting.order_auto_cancel')">
            <div class="input-group wp-400">
              <input type="number" value="{{ old('order_auto_cancel', system_setting('base.order_auto_cancel', '')) }}" name="order_auto_cancel" class="form-control" placeholder="{{ __('admin/setting.order_auto_cancel') }}">
              <span class="input-group-text">{{ __('common.text_hour') }}</span>
            </div>
          </x-admin::form.row>

          <x-admin::form.row :title="__('admin/setting.order_auto_complete')">
            <div class="input-group wp-400">
              <input type="number" value="{{ old('order_auto_complete', system_setting('base.order_auto_complete', '')) }}" name="order_auto_complete" class="form-control" placeholder="{{ __('admin/setting.order_auto_complete') }}">
              <span class="input-group-text">{{ __('common.text_hour') }}</span>
            </div>
          </x-admin::form.row>
          @hook('admin.setting.checkout.after')

        <x-admin::form.row title="">
          <button type="submit" class="btn btn-primary d-none mt-4">{{ __('common.submit') }}</button>
        </x-admin::form.row>
      </form>
      @hook('admin.setting.checkout.content.after')
    </div>
  </div>
@endsection

@push('footer')
  <script>
    (() => {
      const button = document.getElementById('refresh-currency-rates');
      const status = document.getElementById('refresh-currency-rates-status');
      if (!button || !status) return;

      button.addEventListener('click', () => {
        if (button.disabled) return;
        button.disabled = true;
        status.className = 'help-text font-size-12 ms-2 text-secondary';
        status.textContent = '正在连接 ExchangeRate-API…';

        $.ajax({
          url: @json(admin_route('settings.currency.refresh')),
          method: 'POST',
          headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            Accept: 'application/json',
          },
        }).done((response) => {
          status.className = 'help-text font-size-12 ms-2 text-success';
          status.textContent = response.message || '汇率已更新。';
          window.setTimeout(() => window.location.reload(), 700);
        }).fail((xhr) => {
          status.className = 'help-text font-size-12 ms-2 text-danger';
          status.textContent = xhr.responseJSON?.message || '刷新失败，请稍后重试。';
        }).always(() => {
          button.disabled = false;
        });
      });
    })();
  </script>
@endpush
