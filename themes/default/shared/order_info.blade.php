@php
  $isSignedIn = (bool) current_customer();
  $canManageOrder = $isSignedIn || in_array((string) $order->number, array_map('strval', session('guest_order_numbers', [])), true);
  $statusTone = match ($order->status) {
    'unpaid' => 'danger',
    'paid' => 'primary',
    'shipped' => 'info',
    'completed' => 'success',
    'refunding' => 'warning',
    default => 'secondary',
  };
  $hasCustomerAction = in_array($order->status, ['unpaid', 'paid', 'shipped'], true);
@endphp

@if ($errors->any())
  <div class="alert alert-danger order-detail-alert" role="alert" tabindex="-1">
    <strong>{{ __('shop/account/order_info.action_failed') }}</strong>
    <ul class="mb-0 mt-2">
      @foreach ($errors->all() as $error)
        <li>{{ $error }}</li>
      @endforeach
    </ul>
  </div>
@endif

<article class="order-detail" aria-labelledby="order-detail-title">
  <section class="card order-overview mb-lg-4 mb-3">
    <div class="card-body">
      <div class="order-overview__top">
        <div>
          @if ($isSignedIn)
            <a class="order-detail__back" href="{{ shop_route('account.order.index') }}">
              <i class="bi bi-arrow-left" aria-hidden="true"></i>
              {{ __('shop/account/order_info.back_to_orders') }}
            </a>
          @endif
          <p class="order-overview__eyebrow">{{ __('shop/account/order_info.order_details') }}</p>
          <h1 class="order-overview__title" id="order-detail-title">{{ __('shop/account/order_info.order_number') }} {{ $order->number }}</h1>
          <div class="order-overview__meta">
            <time datetime="{{ $order->created_at?->toAtomString() }}">{{ $order->created_at }}</time>
            <button class="order-copy-button" type="button"
              data-copy-order="{{ $order->number }}"
              data-copy-success="{{ __('shop/account/order.number_copied') }}"
              data-copy-error="{{ __('shop/account/order.copy_failed') }}"
              aria-label="{{ __('shop/account/order.copy_number', ['number' => $order->number]) }}">
              <i class="bi bi-copy" aria-hidden="true"></i>
            </button>
          </div>
        </div>
        <span class="order-status-badge order-status-badge--{{ $statusTone }}">{{ $order->status_format }}</span>
      </div>

      <p class="order-overview__hint">{{ __('shop/account/order_info.status_hint_' . $order->status) }}</p>

      <div class="order-overview__actions" aria-label="{{ __('shop/account/order.actions_for', ['number' => $order->number]) }}">
        @if ($canManageOrder)
          @if ($order->status == 'unpaid')
            <a href="{{ shop_route('orders.pay', ['number' => $order->number]) }}" class="btn btn-primary">{{ __('shop/account/order_info.to_pay') }}</a>
          @endif
          @if ($order->status == 'shipped')
            <button class="btn btn-primary" type="button"
              data-order-action="complete"
              data-action-url="{{ shop_route('orders.complete', ['number' => $order->number]) }}"
              data-confirm="{{ __('shop/account/order_info.confirm_receipt_prompt') }}"
              data-processing="{{ __('shop/account/order_info.processing') }}"
              data-error="{{ __('shop/account/order_info.action_failed') }}">
              {{ __('shop/account/order_info.confirm_receipt') }}
            </button>
          @endif
          @if ($order->status == 'unpaid' || $order->status == 'paid')
            <button class="btn btn-outline-danger" type="button"
              data-order-action="cancel"
              data-action-url="{{ shop_route('orders.cancel', ['number' => $order->number]) }}"
              data-confirm="{{ $order->status == 'paid' ? __('shop/account/order_info.refund_prompt') : __('shop/account/order_info.cancel_prompt') }}"
              data-processing="{{ __('shop/account/order_info.processing') }}"
              data-error="{{ __('shop/account/order_info.action_failed') }}">
              {{ $order->status == 'paid' ? __('shop/account/order_info.request_refund') : __('shop/account/order_info.cancel') }}
            </button>
          @endif
        @elseif ($hasCustomerAction)
          <p class="order-overview__session-notice mb-0" role="note">
            <i class="bi bi-shield-lock" aria-hidden="true"></i>
            {{ __('shop/account/order_info.session_required') }}
          </p>
        @endif
        @hook('account.order.info.order_details.top.btns')
      </div>
    </div>
  </section>

  @hookwrapper('account.order_info.order_details.before')
  <section class="card order-detail-card mb-lg-4 mb-3" aria-labelledby="order-summary-title">
    <div class="card-header"><h2 class="card-title h5" id="order-summary-title">{{ __('shop/account/order_info.order_summary') }}</h2></div>
    <div class="card-body">
      <div class="table-responsive order-facts-table">
        <table class="table table-borderless mb-0">
          <caption class="visually-hidden">{{ __('shop/account/order_info.order_summary') }}</caption>
          <thead>
            <tr>
              @hook('account.order_info.order_details.table.head.before')
              <th scope="col">{{ __('shop/account/order_info.order_number') }}</th>
              <th scope="col">{{ __('shop/account/order_info.order_date') }}</th>
              <th scope="col">{{ __('shop/account/order_info.state') }}</th>
              <th scope="col">{{ __('shop/account/order_info.order_amount') }}</th>
              <th scope="col">{{ __('shop/checkout.payment_method') }}</th>
              <th scope="col">{{ __('shop/checkout.delivery_method') }}</th>
              @hook('account.order_info.table.head.after')
            </tr>
          </thead>
          <tbody>
            <tr>
              @hook('account.order_info.order_details.table.body.before')
              <td data-label="{{ __('shop/account/order_info.order_number') }}">{{ $order->number }}</td>
              <td data-label="{{ __('shop/account/order_info.order_date') }}">{{ $order->created_at }}</td>
              <td data-label="{{ __('shop/account/order_info.state') }}"><span class="order-status-badge order-status-badge--{{ $statusTone }}">{{ $order->status_format }}</span></td>
              <td data-label="{{ __('shop/account/order_info.order_amount') }}"><strong>{{ currency_format($order->total, $order->currency_code, $order->currency_value) }}</strong></td>
              <td data-label="{{ __('shop/checkout.payment_method') }}">{{ $order->payment_method_name ?: __('shop/account/order_info.not_available') }}</td>
              <td data-label="{{ __('shop/checkout.delivery_method') }}">{{ $order->shipping_method_name ?: __('shop/account/order_info.not_required') }}</td>
              @hook('account.order_info.table.body.after')
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>
  @endhookwrapper

  @hookwrapper('account.order_info.products')
  <section class="card order-detail-card mb-lg-4 mb-3" aria-labelledby="order-items-title">
    <div class="card-header">
      <h2 class="card-title h5" id="order-items-title">{{ __('shop/account/order_info.order_items') }}</h2>
      <span class="order-card-count">{{ __('shop/account/order.items_count', ['count' => $order->orderProducts->sum('quantity')]) }}</span>
    </div>
    <div class="card-body order-product-list">
      @foreach ($order->orderProducts as $product)
        <article class="order-product">
          <a class="order-product__image" href="{{ shop_route('products.show', ['product' => $product->product_id, 'variant' => $product->product_sku]) }}" tabindex="-1" aria-hidden="true">
            <img src="{{ image_resize($product->image) }}" alt="" loading="lazy">
          </a>
          <div class="order-product__content">
            <h3 class="order-product__name"><a href="{{ shop_route('products.show', ['product' => $product->product_id, 'variant' => $product->product_sku]) }}">{{ $product->name }}</a></h3>
            @hook('order_info.product_name.after', $product)
            <dl class="order-product__pricing">
              <div><dt>{{ __('order.product_price') }}</dt><dd>{{ currency_format($product->price, $order->currency_code, $order->currency_value) }}</dd></div>
              <div><dt>{{ __('order.product_quantity') }}</dt><dd>{{ $product->quantity }}</dd></div>
              <div><dt>{{ __('order.product_sub_price') }}</dt><dd>{{ currency_format($product->price * $product->quantity, $order->currency_code, $order->currency_value) }}</dd></div>
            </dl>
          </div>
          @if ($isSignedIn && $order->status == 'completed')
            <a href="{{ shop_route('account.rma.create', [$product->id]) }}" class="btn btn-outline-primary btn-sm order-product__after-sales">{{ __('shop/account/order_info.apply_after_sales') }}</a>
          @endif
        </article>
      @endforeach
    </div>
  </section>
  @endhookwrapper

  <div class="order-detail-grid">
    @hookwrapper('account.order_info.address_info')
    <section class="card order-detail-card" aria-labelledby="order-address-title">
      <div class="card-header"><h2 class="card-title h5" id="order-address-title">{{ __('order.address_info') }}</h2></div>
      <div class="card-body order-address-grid">
        @if ($order->shipping_country)
          <section class="order-address" aria-labelledby="shipping-address-title">
            <h3 id="shipping-address-title">{{ __('order.shipping_address') }}</h3>
            <address>
              <strong>{{ $order->shipping_customer_name }}</strong>
              @if ($order->shipping_telephone)<span>{{ $order->shipping_telephone }}</span>@endif
              <span>{{ collect([$order->shipping_address_1, $order->shipping_address_2, $order->shipping_city, $order->shipping_zone, $order->shipping_country])->filter()->join(' ') }}</span>
              @if ($order->shipping_zipcode)<span>{{ __('address.post_code') }}: {{ $order->shipping_zipcode }}</span>@endif
            </address>
          </section>
        @endif
        <section class="order-address" aria-labelledby="payment-address-title">
          <h3 id="payment-address-title">{{ __('order.payment_address') }}</h3>
          <address>
            <strong>{{ $order->payment_customer_name }}</strong>
            @if ($order->payment_telephone)<span>{{ $order->payment_telephone }}</span>@endif
            <span>{{ collect([$order->payment_address_1, $order->payment_address_2, $order->payment_city, $order->payment_zone, $order->payment_country])->filter()->join(' ') }}</span>
            @if ($order->payment_zipcode)<span>{{ __('address.post_code') }}: {{ $order->payment_zipcode }}</span>@endif
          </address>
        </section>
      </div>
    </section>
    @endhookwrapper

    @hookwrapper('account.order_info.totals')
    <section class="card order-detail-card" aria-labelledby="order-total-title">
      <div class="card-header"><h2 class="card-title h5" id="order-total-title">{{ __('shop/account/order_info.order_total') }}</h2></div>
      <div class="card-body">
        <dl class="order-total-list">
          @foreach ($order->orderTotals as $total)
            <div class="{{ $total->code === 'order_total' ? 'order-total-list__grand' : '' }}">
              <dt>{{ $total->title }}</dt>
              <dd>{{ currency_format($total->value, $order->currency_code, $order->currency_value) }}</dd>
            </div>
          @endforeach
        </dl>
      </div>
    </section>
    @endhookwrapper
  </div>

  @if ($order->comment)
    @hookwrapper('account.order_info.comment')
    <section class="card order-detail-card mb-lg-4 mb-3" aria-labelledby="order-comment-title">
      <div class="card-header"><h2 class="card-title h5" id="order-comment-title">{{ __('order.comment') }}</h2></div>
      <div class="card-body"><p class="order-comment mb-0">{{ $order->comment }}</p></div>
    </section>
    @endhookwrapper
  @endif

  @foreach ($html_items as $item)
    {!! $item !!}
  @endforeach

  @hook('account.order_info.after')

  @if ($order->orderShipments->count())
    @hookwrapper('account.order_info.shipments')
    <section class="card order-detail-card mb-lg-4 mb-3" aria-labelledby="order-shipments-title">
      <div class="card-header"><h2 class="card-title h5" id="order-shipments-title">{{ __('order.order_shipments') }}</h2></div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table order-shipment-table mb-0">
            <thead><tr><th scope="col">{{ __('order.express_company') }}</th><th scope="col">{{ __('order.express_number') }}</th><th scope="col">{{ __('order.history_created_at') }}</th><th scope="col"><span class="visually-hidden">{{ __('shop/account/order.actions') }}</span></th></tr></thead>
            <tbody>
              @foreach ($order->orderShipments as $ship)
                @php
                  $trackingUrl = \Beike\Services\ShipmentService::trackingUrl($ship);
                @endphp
                <tr>
                  <td>{{ $ship->express_company }}</td>
                  <td><code>{{ $ship->express_number }}</code></td>
                  <td>{{ $ship->created_at }}</td>
                  <td class="text-end">
                    @if ($trackingUrl)
                      <a class="btn btn-sm btn-outline-primary" href="{{ $trackingUrl }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('shop/account/order_info.track_number', ['number' => $ship->express_number]) }}">{{ __('order.track_shipment') }} <span aria-hidden="true">↗</span></a>
                    @else
                      <span class="text-muted">{{ __('shop/account/order_info.tracking_pending') }}</span>
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </section>
    @endhookwrapper
  @endif

  @if ($order->orderHistories->count())
    <section class="card order-detail-card mb-lg-4 mb-3" aria-labelledby="order-history-title">
      <div class="card-header"><h2 class="card-title h5" id="order-history-title">{{ __('shop/account/order_info.order_status') }}</h2></div>
      <div class="card-body">
        <ol class="order-timeline">
          @foreach ($order->orderHistories as $orderHistory)
            <li class="order-timeline__item {{ $loop->first ? 'is-current' : '' }}">
              <span class="order-timeline__marker" aria-hidden="true"></span>
              <div class="order-timeline__content">
                <div class="order-timeline__heading">
                  <strong>{{ $orderHistory->status_format }}</strong>
                  @if ($loop->first)<span class="order-timeline__current">{{ __('shop/account/order_info.current_status') }}</span>@endif
                  <time datetime="{{ $orderHistory->created_at?->toAtomString() }}">{{ $orderHistory->created_at }}</time>
                </div>
                <p>{{ $orderHistory->comment ?: __('shop/account/order_info.no_remark') }}</p>
              </div>
            </li>
          @endforeach
        </ol>
      </div>
    </section>
  @endif

  <div class="visually-hidden" role="status" aria-live="polite" aria-atomic="true" data-order-feedback></div>
</article>
