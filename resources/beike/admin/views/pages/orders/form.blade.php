@extends('admin::layouts.master')

@section('title', __('admin/common.order'))

@section('page-bottom-btns')
@hook('order.detail.title.right')
@endsection

@section('page-title-right')
  <a href="{{ admin_route('orders.shipping.get') }}?order_id={{ $order->id }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary"><i class="bi bi-printer-fill"></i> {{ __('admin/order.btn_print') }}</a>
@endsection

@section('page-title-back', admin_route('orders.index', http_build_query(request()->query())))

@push('header')
  <style>
    .order-detail-page { --order-border: var(--bs-border-color, #e4e7ec); --order-muted: var(--bs-secondary-color, #667085); --order-surface: var(--bs-tertiary-bg, #f8fafc); }
    .order-detail-page .card { border-color: var(--order-border); box-shadow: 0 1px 2px rgba(16, 24, 40, .035); }
    .order-detail-page .card-header { align-items: center; background: transparent; display: flex; justify-content: space-between; min-height: 54px; padding: 14px 20px; }
    .order-detail-page .card-title { font-size: 15px; letter-spacing: .01em; margin: 0; }
    .order-detail-page .order-identity { align-items: flex-start; display: flex; flex-wrap: wrap; gap: 10px 14px; justify-content: space-between; margin-bottom: 20px; }
    .order-detail-page .order-number { color: var(--bs-body-color); font-size: clamp(20px, 2vw, 28px); font-weight: 700; letter-spacing: -.025em; line-height: 1.2; overflow-wrap: anywhere; }
    .order-detail-page .order-number-label { color: var(--order-muted); display: block; font-size: 12px; font-weight: 600; letter-spacing: .04em; margin-bottom: 5px; text-transform: uppercase; }
    .order-detail-page .order-status-pill, .order-detail-page .payment-state-pill { align-items: center; border: 1px solid transparent; border-radius: 999px; display: inline-flex; font-size: 12px; font-weight: 650; gap: 6px; line-height: 20px; padding: 3px 10px; white-space: nowrap; }
    .order-detail-page .order-status-pill::before, .order-detail-page .payment-state-pill::before { background: currentColor; border-radius: 50%; content: ''; height: 6px; width: 6px; }
    .order-detail-page .is-completed { background: #ecfdf3; border-color: #abefc6; color: #067647; }
    .order-detail-page .is-pending { background: #fffaeb; border-color: #fedf89; color: #b54708; }
    .order-detail-page .is-failed { background: #fef3f2; border-color: #fecdca; color: #b42318; }
    .order-detail-page .is-neutral { background: #f2f4f7; border-color: #e4e7ec; color: #475467; }
    .order-detail-page .order-facts { display: grid; gap: 1px; grid-template-columns: repeat(4, minmax(0, 1fr)); overflow: hidden; border: 1px solid var(--order-border); border-radius: 10px; background: var(--order-border); }
    .order-detail-page .order-fact { background: var(--bs-body-bg, #fff); min-width: 0; padding: 14px 16px; }
    .order-detail-page .order-fact-label { color: var(--order-muted); display: block; font-size: 12px; margin-bottom: 6px; }
    .order-detail-page .order-fact-value { color: var(--bs-body-color); display: block; font-size: 14px; font-weight: 600; line-height: 1.45; overflow-wrap: anywhere; }
    .order-detail-page .order-fact-subvalue { color: var(--order-muted); display: block; font-size: 12px; line-height: 1.45; margin-top: 3px; overflow-wrap: anywhere; }
    .order-detail-page .address-grid { display: grid; gap: 16px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .order-detail-page .address-card { background: var(--order-surface); border: 1px solid var(--order-border); border-radius: 10px; min-width: 0; padding: 18px; }
    .order-detail-page .address-card__title { align-items: center; display: flex; font-size: 14px; font-weight: 700; gap: 8px; margin-bottom: 14px; }
    .order-detail-page .address-card__person { font-size: 14px; font-weight: 650; margin-bottom: 8px; overflow-wrap: anywhere; }
    .order-detail-page .address-card__phone { color: var(--order-muted); font-size: 13px; font-weight: 400; margin-left: 6px; }
    .order-detail-page .address-card address { color: var(--bs-body-color); font-size: 13px; line-height: 1.7; margin: 0; overflow-wrap: anywhere; }
    .order-detail-page .address-card__postal { color: var(--order-muted); display: block; margin-top: 4px; }
    .order-detail-page .status-layout { display: grid; gap: 24px; grid-template-columns: minmax(210px, .45fr) minmax(0, 1fr); }
    .order-detail-page .status-current { background: var(--order-surface); border: 1px solid var(--order-border); border-radius: 10px; padding: 18px; }
    .order-detail-page .status-current__label { color: var(--order-muted); display: block; font-size: 12px; margin-bottom: 9px; }
    .order-detail-page .status-current__help { color: var(--order-muted); font-size: 12px; line-height: 1.55; margin: 12px 0 0; }
    .order-detail-page .status-form .el-form-item { margin-bottom: 16px; }
    .order-detail-page .status-form .el-form-item__label { color: var(--bs-body-color); float: none; font-size: 13px; font-weight: 600; line-height: 1.35; padding: 0 0 7px; text-align: left; }
    .order-detail-page .status-form .el-form-item__content { line-height: 1.4; margin-left: 0 !important; }
    .order-detail-page .status-form .el-select, .order-detail-page .status-form .el-input, .order-detail-page .status-form textarea { max-width: 620px; width: 100%; }
    .order-detail-page .status-form textarea { min-height: 92px; resize: vertical; }
    .order-detail-page .payment-overview { align-items: center; display: flex; flex-wrap: wrap; gap: 10px 16px; justify-content: space-between; }
    .order-detail-page .payment-meta { display: flex; flex-wrap: wrap; gap: 8px 20px; margin-top: 14px; }
    .order-detail-page .payment-meta__item { min-width: min(220px, 100%); }
    .order-detail-page .payment-meta__item small { color: var(--order-muted); display: block; margin-bottom: 3px; }
    .order-detail-page .payment-meta__item strong { font-size: 13px; font-weight: 600; overflow-wrap: anywhere; }
    .order-detail-page .payment-attempt { border: 1px solid var(--order-border); border-radius: 10px; padding: 16px; }
    .order-detail-page .payment-attempt + .payment-attempt { margin-top: 12px; }
    .order-detail-page .payment-attempt details { border-top: 1px solid var(--order-border); margin-top: 14px; padding-top: 12px; }
    .order-detail-page .payment-attempt summary { color: var(--bs-primary); cursor: pointer; font-size: 13px; font-weight: 600; }
    .order-detail-page .payment-payload-grid { display: grid; gap: 10px; grid-template-columns: repeat(3, minmax(0, 1fr)); margin-top: 12px; }
    .order-detail-page .payment-payload { min-width: 0; }
    .order-detail-page .payment-payload label { color: var(--order-muted); display: block; font-size: 11px; font-weight: 600; margin-bottom: 5px; text-transform: uppercase; }
    .order-detail-page .payment-payload pre { background: var(--order-surface); border: 1px solid var(--order-border); border-radius: 8px; font-size: 11px; margin: 0; max-height: 220px; overflow: auto; padding: 10px; white-space: pre-wrap; word-break: break-word; }
    .order-detail-page .table-push { overflow-x: auto; }
    .order-detail-page .order-products-table { min-width: 760px; }
    .order-detail-page .order-extension-table:has(tbody:empty) { display: none; }
    @media (max-width: 1199.98px) { .order-detail-page .order-facts { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 767.98px) {
      .order-detail-page .card-header, .order-detail-page .card-body { padding-left: 16px; padding-right: 16px; }
      .order-detail-page .order-facts, .order-detail-page .address-grid, .order-detail-page .status-layout, .order-detail-page .payment-payload-grid { grid-template-columns: 1fr; }
      .order-detail-page .order-identity { margin-bottom: 16px; }
      .order-detail-page .order-fact { padding: 12px 14px; }
      .order-detail-page .address-card { padding: 15px; }
      .order-detail-page .status-layout { gap: 16px; }
    }
  </style>
@endpush

@section('content')
<div class="order-detail-page">
  @hook('admin.order.form.content.before')

  @hookwrapper('admin.order.form.base')
  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title">{{ __('admin/order.order_overview') }}</h6></div>
    <div class="card-body">
      @php
        $orderStatusClass = match ((string) $order->status) {
          'completed', 'paid', 'shipped' => 'is-completed',
          'cancelled', 'refunded', 'refunding' => 'is-failed',
          'unpaid' => 'is-pending',
          default => 'is-neutral',
        };
        $shippingFields = collect((array) data_get($order->shipping_quote_data, 'fields', []))
          ->filter(static fn ($value) => is_scalar($value) && trim((string) $value) !== '');
      @endphp
      <div class="order-identity">
        <div>
          <span class="order-number-label">{{ __('order.number') }}</span>
          <div class="order-number">{{ $order->number }}</div>
        </div>
        <span class="order-status-pill {{ $orderStatusClass }}">{{ $order->status_format ?: $order->status }}</span>
      </div>

      <div class="order-facts">
        <div class="order-fact">
          <span class="order-fact-label">{{ __('order.total') }}</span>
          <span class="order-fact-value">{{ currency_format($order->total, $order->currency_code, $order->currency_value) }}</span>
        </div>
        <div class="order-fact">
          <span class="order-fact-label">{{ __('order.payment_method') }}</span>
          <span class="order-fact-value">{{ $order->payment_method_name ?: '-' }}</span>
          @if ($order->payment_method_code)
            <span class="order-fact-subvalue">{{ $order->payment_method_code }}</span>
          @endif
        </div>
        <div class="order-fact">
          <span class="order-fact-label">{{ __('order.customer_name') }}</span>
          <span class="order-fact-value">{{ $order->customer_name ?: '-' }}</span>
          <span class="order-fact-subvalue">{{ $order->email ?: '-' }}</span>
        </div>
        <div class="order-fact">
          <span class="order-fact-label">{{ __('admin/plugin.shipping') }}</span>
          <span class="order-fact-value">{{ $order->shipping_method_name ?: '-' }}</span>
          @foreach ($shippingFields as $field => $value)
            <span class="order-fact-subvalue"><span class="fw-semibold">{{ str_replace(['_', '.'], ' ', (string) $field) }}:</span> {{ $value }}</span>
          @endforeach
        </div>
        <div class="order-fact">
          <span class="order-fact-label">{{ __('order.created_at') }}</span>
          <span class="order-fact-value">{{ $order->created_at }}</span>
        </div>
        <div class="order-fact">
          <span class="order-fact-label">{{ __('order.updated_at') }}</span>
          <span class="order-fact-value">{{ $order->updated_at }}</span>
        </div>
        <div class="order-fact">
          <span class="order-fact-label">{{ __('admin/order.telephone') }}</span>
          <span class="order-fact-value">{{ trim(implode(' ', array_filter([$order->calling_code, $order->telephone]))) ?: '-' }}</span>
        </div>
        <div class="order-fact">
          <span class="order-fact-label">{{ __('admin/order.payment_state') }}</span>
          @php
            $paymentStateLabel = __('admin/order.payment_' . $paymentSummary['state']);
            $paymentStateClass = match ($paymentSummary['state']) {
              'completed' => 'is-completed',
              'failed' => 'is-failed',
              'pending' => 'is-pending',
              default => 'is-neutral',
            };
          @endphp
          <span class="payment-state-pill {{ $paymentStateClass }}">{{ $paymentStateLabel }}</span>
        </div>
      </div>
      <table class="table table-borderless table-sm order-extension-table mb-0 mt-2"><tbody>@hook('admin.order.form.base.updated_at.after')</tbody></table>
    </div>
  </div>
  @endhookwrapper

  @hookwrapper('admin.order.form.address')
  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title">{{ __('order.address_info') }}</h6></div>
    <div class="card-body">
      @php
        $hasShippingAddress = collect([
          $order->shipping_customer_name, $order->shipping_country, $order->shipping_zone,
          $order->shipping_city, $order->shipping_address_1, $order->shipping_address_2,
        ])->filter(static fn ($value) => trim((string) $value) !== '')->isNotEmpty();
        $shippingLocality = collect([$order->shipping_city, $order->shipping_zone, $order->shipping_country])
          ->filter(static fn ($value) => trim((string) $value) !== '')->implode(', ');
        $paymentLocality = collect([$order->payment_city, $order->payment_zone, $order->payment_country])
          ->filter(static fn ($value) => trim((string) $value) !== '')->implode(', ');
      @endphp
      <div class="address-grid" @if (! $hasShippingAddress) style="grid-template-columns: minmax(0, 1fr)" @endif>
        @if ($hasShippingAddress)
          <section class="address-card" aria-labelledby="shipping-address-title">
            <h3 class="address-card__title" id="shipping-address-title"><i class="bi bi-truck" aria-hidden="true"></i>{{ __('admin/order.shipping_details') }}</h3>
            <div class="address-card__person">
              {{ $order->shipping_customer_name ?: '-' }}
              @if ($order->shipping_telephone)
                <span class="address-card__phone">{{ trim(implode(' ', array_filter([$order->shipping_calling_code, $order->shipping_telephone]))) }}</span>
              @endif
            </div>
            <address>
              @if ($order->shipping_address_1)<div>{{ $order->shipping_address_1 }}</div>@endif
              @if ($order->shipping_address_2)<div>{{ $order->shipping_address_2 }}</div>@endif
              @if ($shippingLocality)<div>{{ $shippingLocality }}</div>@endif
              @if ($order->shipping_zipcode)<span class="address-card__postal">{{ __('address.post_code') }}: {{ $order->shipping_zipcode }}</span>@endif
            </address>
          </section>
        @endif
        <section class="address-card" aria-labelledby="billing-address-title">
          <h3 class="address-card__title" id="billing-address-title"><i class="bi bi-receipt" aria-hidden="true"></i>{{ __('admin/order.billing_details') }}</h3>
          <div class="address-card__person">
            {{ $order->payment_customer_name ?: '-' }}
            @if ($order->payment_telephone)
              <span class="address-card__phone">{{ trim(implode(' ', array_filter([$order->payment_calling_code, $order->payment_telephone]))) }}</span>
            @endif
          </div>
          <address>
            @if ($order->payment_address_1)<div>{{ $order->payment_address_1 }}</div>@endif
            @if ($order->payment_address_2)<div>{{ $order->payment_address_2 }}</div>@endif
            @if ($paymentLocality)<div>{{ $paymentLocality }}</div>@endif
            @if ($order->payment_zipcode)<span class="address-card__postal">{{ __('address.post_code') }}: {{ $order->payment_zipcode }}</span>@endif
          </address>
        </section>
      </div>
    </div>
  </div>
  @endhookwrapper

  @foreach ($html_items as $item)
    {!! $item !!}
  @endforeach

  @can('orders_update_status')
  @hookwrapper('admin.order.form.status')
  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title">{{ __('order.order_status') }}</h6></div>
    <div class="card-body" id="app">
      <div class="status-layout">
        <div class="status-current">
          <span class="status-current__label">{{ __('order.current_status') }}</span>
          <span class="order-status-pill {{ $orderStatusClass }}">{{ $order->status_format ?: $order->status }}</span>
          <p class="status-current__help">{{ __('admin/order.status_update_help') }}</p>
        </div>
        @if (count($statuses))
          <el-form class="status-form" ref="form" :model="form" :rules="rules" label-position="top">
            <el-form-item label="{{ __('order.change_to_status') }}" prop="status">
              <el-select size="small" v-model="form.status" placeholder="{{ __('common.please_choose') }}">
                <el-option
                  v-for="item in statuses"
                  :key="item.status"
                  :label="item.name"
                  :value="item.status">
                </el-option>
              </el-select>
            </el-form-item>
            <el-form-item label="{{ __('order.express_company') }}" v-if="form.status == 'shipped'" prop="express_code">
              <div class="d-flex align-items-center flex-wrap gap-2 w-100">
                <el-select size="small" v-model="form.express_code" placeholder="{{ __('common.please_choose') }}">
                  <el-option
                    v-for="item in source.express_company"
                    :key="item.code"
                    :label="item.name"
                    :value="item.code">
                  </el-option>
                </el-select>
                <a href="{{ admin_route('settings.express') }}" target="_blank" rel="noopener noreferrer">{{ __('common.to_setting') }}</a>
              </div>
            </el-form-item>
            <el-form-item label="{{ __('order.express_number') }}" v-if="form.status == 'shipped'" prop="express_number">
              <el-input v-model="form.express_number" size="small" placeholder="{{ __('order.express_number') }}"></el-input>
            </el-form-item>
            <el-form-item label="{{ __('order.comment') }}">
              <textarea class="form-control" v-model="form.comment"></textarea>
            </el-form-item>
            <el-form-item class="mb-0">
              <el-button type="primary" @click="submitForm('form')">{{ __('order.submit_status') }}</el-button>
            </el-form-item>
          </el-form>
        @endif
      </div>
    </div>
  </div>
  @endhookwrapper
  @endcan

  @hookwrapper('admin.order.form.products')
  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title">{{ __('order.product_info') }}</h6></div>
    <div class="card-body">
      <div class="table-push">
        <table class="table order-products-table">
          <thead class="">
            <tr>
              <th>ID</th>
              <th>{{ __('order.product_name') }}</th>
              <th class="">{{ __('order.product_sku') }}</th>
              <th>{{ __('order.product_price') }}</th>
              <th class="">{{ __('order.product_quantity') }}</th>
              <th class="text-end">{{ __('order.product_sub_price') }}</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($order->orderProducts as $product)
            <tr>
              <td>{{ $product->product_id }}</td>
              <td>
                <div class="d-flex align-items-center">
                  <div class="border d-flex justify-content-center align-items-center wh-60 me-2"><img src="{{ image_resize($product->image) }}" class="img-fluid max-h-100"></div>
                  <div class="product-name">
                    {{ $product->name }}
                    @hook('admin.order_form.product_name.after', $product)
                  </div>
                </div>
              </td>
              <td class="">{{ $product->product_sku }}</td>
              <td>{{ currency_format($product->price, $order->currency_code, $order->currency_value) }}</td>
              <td class="">{{ $product->quantity }}</td>
              <td class="text-end">{{ currency_format($product->price * $product->quantity, $order->currency_code, $order->currency_value) }}</td>
            </tr>
            @endforeach
          </tbody>
          <tfoot>
            @foreach ($order->orderTotals as $orderTotal)
              <tr>
                <td colspan="5" class="text-end">{{ $orderTotal->title }}</td>
                <td class="text-end"><span class="fw-bold">{{ currency_format($orderTotal->value, $order->currency_code, $order->currency_value) }}</span></td>
              </tr>
            @endforeach
          </tfoot>
        </table>
      </div>
    </div>
  </div>
  @endhookwrapper

  @if ($order->comment)
    <div class="card mb-4">
      <div class="card-header"><h6 class="card-title">{{ __('order.order_comment') }}</h6></div>
      <div class="card-body">{{ $order->comment }}</div>
    </div>
  @endif

  @hookwrapper('admin.order.form.payments')
  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title">{{ __('admin/order.payments_history') }}</h6></div>
    <div class="card-body">
      <div class="payment-overview">
        <div>
          <div class="small text-secondary mb-2">{{ $order->payment_method_name ?: __('order.payment_method') }}</div>
          <span class="payment-state-pill {{ $paymentStateClass }}">{{ $paymentStateLabel }}</span>
        </div>
        @if ($paymentSummary['updated_at'])
          <div class="text-secondary small">{{ __('admin/order.payment_last_attempt') }}: {{ $paymentSummary['updated_at'] }}</div>
        @endif
      </div>

      @if ($paymentSummary['transaction_id'] || $paymentSummary['provider_status'] || $paymentSummary['stage'] || $paymentSummary['reference'])
        <div class="payment-meta">
          @if ($paymentSummary['transaction_id'])
            <div class="payment-meta__item"><small>{{ __('admin/order.transaction_reference') }}</small><strong>{{ $paymentSummary['transaction_id'] }}</strong></div>
          @endif
          @if ($paymentSummary['provider_status'])
            <div class="payment-meta__item"><small>{{ __('admin/order.payment_provider_status') }}</small><strong>{{ $paymentSummary['provider_status'] }}</strong></div>
          @endif
          @if ($paymentSummary['stage'])
            <div class="payment-meta__item"><small>{{ __('admin/order.payment_stage') }}</small><strong>{{ $paymentSummary['stage'] }}</strong></div>
          @endif
          @if ($paymentSummary['reference'])
            <div class="payment-meta__item"><small>{{ __('admin/order.payment_reference') }}</small><strong>{{ $paymentSummary['reference'] }}</strong></div>
          @endif
        </div>
      @elseif ($order->orderPayments->isEmpty())
        <p class="text-secondary small mb-0 mt-3">{{ __('admin/order.no_payment_attempt') }}</p>
      @endif

      @foreach ($order->orderPayments->sortByDesc('id') as $payment)
        @php
          $formatPaymentPayload = static function ($payload): string {
            if (is_array($payload)) {
              return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
            }
            $decoded = json_decode((string) $payload, true);
            return is_array($decoded)
              ? (json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '')
              : trim((string) $payload);
          };
        @endphp
        <article class="payment-attempt mt-3">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <strong class="small">{{ __('admin/order.payment_attempt_details') }}</strong>
            <span class="small text-secondary">{{ $payment->updated_at }}</span>
          </div>
          @if ($payment->receipt)
            <a class="small d-inline-block mt-2" href="{{ image_origin($payment->receipt) }}" target="_blank" rel="noopener noreferrer">{{ __('admin/order.text_click_view') }}</a>
          @endif
          <details>
            <summary>{{ __('admin/order.view_technical_details') }}</summary>
            <div class="payment-payload-grid">
              <div class="payment-payload"><label>{{ __('admin/order.text_request') }}</label><pre>{{ $formatPaymentPayload($payment->request) ?: '-' }}</pre></div>
              <div class="payment-payload"><label>{{ __('admin/order.text_response') }}</label><pre>{{ $formatPaymentPayload($payment->response) ?: '-' }}</pre></div>
              <div class="payment-payload"><label>{{ __('admin/order.text_callback') }}</label><pre>{{ $formatPaymentPayload($payment->callback) ?: '-' }}</pre></div>
            </div>
          </details>
        </article>
      @endforeach
    </div>
  </div>
  @endhookwrapper

  @if ($order->orderShipments->count())
    @hookwrapper('admin.order.form.shipments')
    <div class="card mb-4">
      <div class="card-header"><h6 class="card-title">{{ __('order.order_shipments') }}</h6></div>
      <div class="card-body">
        <div class="table-push">
          <table class="table">
            <thead class="">
              <tr>
                <th>{{ __('order.express_company') }}</th>
                <th>{{ __('order.express_number') }}</th>
                <th>{{ __('order.updated_at') }}</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($order->orderShipments as $ship)
              <tr data-id="{{ $ship->id }}">
                <td>
                  <div class="edit-show express-company">{{ $ship->express_company }}</div>
                  @if($expressCompanies)
                  <select class="form-select edit-form express-code d-none" aria-label="Default select example">
                    @foreach ($expressCompanies as $item)
                      <option value="{{ $item['code'] }}" {{ $ship->express_code == $item['code'] ? 'selected' : '' }}>{{ $item['name'] }}</option>
                    @endforeach
                  </select>
                  @endif
                </td>
                <td>
                  <div class="edit-show">{{ $ship->express_number }}</div>
                  <input type="text" class="form-control edit-form express-number d-none" placeholder="{{ __('order.express_number') }}" value="{{ $ship->express_number }}">
                </td>
                <td>
                  <div class="d-flex justify-content-between align-items-center">
                    {{ $ship->created_at }}
                    <div class="btn btn-outline-primary btn-sm edit-shipment">{{ __('common.edit') }}</div>
                    <div class="d-none shipment-tool">
                      <div class="btn btn-primary btn-sm">{{ __('common.confirm') }}</div>
                      <div class="btn btn-outline-secondary btn-sm">{{ __('common.cancel') }}</div>
                    </div>
                  </div>
                </td>
              </tr>
              @endforeach
            </tbody>
            <tfoot>
              <tr>
                <td colspan="3" class="text-end">
                  <a href="#" class="btn btn-sm btn-outline-secondary add-express">{{ __('admin/order.add_express') }}</a>
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
    @endhookwrapper
  @endif

  @hookwrapper('admin.order.form.history')
  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title">{{ __('order.action_history') }}</h6></div>
    <div class="card-body">
      <div class="table-push">
        <table class="table ">
          <thead class="">
            <tr>
              <th>{{ __('order.history_status') }}</th>
              <th>{{ __('order.history_comment') }}</th>
              <th>{{ __('order.updated_at') }}</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($order->orderHistories as $orderHistory)
              <tr>
                <td>{{ $orderHistory->status_format }}</td>
                <td>{{ $orderHistory->comment }}</td>
                <td>{{ $orderHistory->created_at }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  </div>
  @endhookwrapper

  @hook('admin.order.form.content.after')
</div>
@endsection

@push('footer')
  <script>
    const express_company = @json(system_setting('base.express_company', []));

    $('.add-express').on('click', function(e) {
      e.preventDefault();

      if (!express_company) {
        layer.alert('{{ __('admin/order.error_no_express_company') }}', {
          title: '{{ __('common.text_hint') }}',
          btn: ['{{ __('admin/order.to_add_express_company') }}'],
          btn1: function(index, layero) {
            window.open('{{ admin_route('settings.index') }}?tab=tab-express-company');
          }
        });
        return;
      }

      let html = '<div class="px-3 pt-3 add-express-wrap">';
      html += '<div class="form-group mb-2">';
      html += '<label for="express_company" class="form-label">{{ __('order.express_company') }}</label>';
      html += '<select class="form-select" id="express_company" aria-label="Default select example">';
      html += '<option value="">{{ __('common.please_choose') }}</option>';
      express_company.forEach(item => {
        html += `<option value="${item.code}">${item.name}</option>`;
      });
      html += '</select>';
      html += '</div>';
      html += '<div class="form-group mb-2">';
      html += '<label for="express_number" class="form-label">{{ __('order.express_number') }}</label>';
      html += '<input type="text" class="form-control" id="express_number" placeholder="{{ __('order.express_number') }}">';
      html += '</div>';
      html += '</div>';

      layer.open({
        type: 1,
        title: '{{ __('admin/order.add_express') }}',
        content: html,
        area: ['400px', '300px'],
        btn: ['{{ __('common.cancel') }}', '{{ __('common.confirm') }}'],
        btn2: function(index, layero) {
          const express_code = $('#express_company').val();
          const express_number = $('#express_number').val();
          if (!express_code) {
            layer.msg('{{ __('common.error_required', ['name' => __('order.express_company')]) }}');
            return false;
          }
          if (!express_number) {
            layer.msg('{{ __('common.error_required', ['name' => __('order.express_number')]) }}');
            return false;
          }

          $http.post(`/orders/{{ $order->id }}/shipments`, {express_code, express_number}).then((res) => {
            layer.msg(res.message);
            window.location.reload();
          });

          return false; // 阻止默认关闭行为
        }
      });
    });
  </script>

  @can('orders_update_status')
  <script>
    $('.edit-shipment').click(function() {
      $(this).siblings('.shipment-tool').removeClass('d-none');
      $(this).addClass('d-none');

      $(this).parents('tr').find('.edit-show').addClass('d-none');
      $(this).parents('tr').find('.edit-form').removeClass('d-none');
      @if(!$expressCompanies)
      $(this).parents('tr').find('.express-company').removeClass('d-none');
      @endif
    });

    $('.shipment-tool .btn-outline-secondary').click(function() {
      $(this).parent().siblings('.edit-shipment').removeClass('d-none');
      $(this).parent().addClass('d-none');

      $(this).parents('tr').find('.edit-show').removeClass('d-none');
      $(this).parents('tr').find('.edit-form').addClass('d-none');
    });

    $('.shipment-tool .btn-primary').click(function() {
      const id = $(this).parents('tr').data('id');
      const express_code = $(this).parents('tr').find('.express-code').val();
      const express_name = $(this).parents('tr').find('.express-code option:selected').text();
      const express_number = $(this).parents('tr').find('.express-number').val();

      $(this).parent().siblings('.edit-shipment').removeClass('d-none');
      $(this).parent().addClass('d-none');

      $(this).parents('tr').find('.edit-show').removeClass('d-none');
      $(this).parents('tr').find('.edit-form').addClass('d-none');

      $http.put(`/orders/{{ $order->id }}/shipments/${id}`, {express_code,express_name,express_number}).then((res) => {
        layer.msg(res.message);
        window.location.reload();
      })
    });

    let app = new Vue({
      el: '#app',

      data: {
        statuses: @json($statuses ?? []),
        form: {
          status: "",
          express_number: '',
          express_code: '',
          notify: 0,
          comment: '',
        },

        source: {
          express_company: @json(system_setting('base.express_company', [])) || [],
        },

        rules: {
          status: [{required: true, message: '{{ __('admin/order.error_status') }}', trigger: 'blur'}, ],
        },

        @hook('admin.order.form.vue.data')
      },

      methods: {
        submitForm(form) {
          this.$refs[form].validate((valid) => {
            if (!valid) {
              layer.msg('{{ __('common.error_form') }}',()=>{});
              return;
            }

            $http.put(`/orders/{{ $order->id }}/status`,this.form).then((res) => {
              layer.msg(res.message);
              window.location.reload();
            })
          });
        },

        @hook('admin.order.form.vue.methods')
      },

      @hook('admin.order.form.vue.options')
    })
  </script>
  @endcan
@endpush

