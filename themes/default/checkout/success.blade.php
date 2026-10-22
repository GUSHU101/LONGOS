@extends('layout.master')
@section('body-class', 'page-order-success')
@section('title', __('shop/checkout.checkout_success_title'))

@push('header')
  <meta name="robots" content="noindex,nofollow,noarchive">
@endpush

@php
  $paymentRequired = $order->status === \Beike\Services\StateMachineService::UNPAID;
  $orderDetailsUrl = current_customer()
    ? shop_route('account.order.show', $order->number)
    : shop_route('orders.show', ['number' => $order->number]);
@endphp

@section('content')
  <div class="container purchase-state-shell" aria-labelledby="order-result-title">
    <div class="purchase-progress">
      @include('shared.steps', ['steps' => 3])
    </div>

    @if ($errors->any())
      <div class="purchase-alert" role="alert" tabindex="-1">
        <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
        <div><strong>{{ __('shop/checkout.payment_needs_attention') }}</strong><p>{{ $errors->first() }}</p></div>
      </div>
    @endif

    <section class="purchase-card order-result-card">
      <header class="purchase-card__header purchase-card__header--centered">
        <span class="purchase-status-icon {{ $paymentRequired ? 'purchase-status-icon--pending' : 'purchase-status-icon--success' }}" aria-hidden="true">
          <i class="bi {{ $paymentRequired ? 'bi-clock' : 'bi-check-lg' }}"></i>
        </span>
        <div>
          <p class="purchase-eyebrow">{{ $paymentRequired ? __('shop/checkout.payment_required') : __('shop/checkout.order_confirmed') }}</p>
          <h1 id="order-result-title">{{ $paymentRequired ? __('shop/checkout.order_received_title') : __('shop/checkout.checkout_success_title') }}</h1>
          @hook('checkout.success.checkout_success_title.after')
          <p>{{ $paymentRequired ? __('shop/checkout.order_unpaid_message') : __('shop/checkout.order_paid_message') }}</p>
        </div>
      </header>

      <dl class="purchase-order-facts purchase-order-facts--summary">
        <div><dt>{{ __('shop/account/order_info.order_number') }}</dt><dd>{{ $order->number }}</dd></div>
        <div><dt>{{ __('shop/account/order_info.order_date') }}</dt><dd>{{ $order->created_at }}</dd></div>
        <div><dt>{{ __('shop/account/order_info.state') }}</dt><dd>{{ $order->status_format }}</dd></div>
        <div><dt>{{ __('shop/account/order_info.order_amount') }}</dt><dd>{{ currency_format($order->total, $order->currency_code, $order->currency_value) }}</dd></div>
      </dl>

      <div class="purchase-primary-actions">
        @if ($paymentRequired)
          <a class="purchase-action purchase-action--primary" href="{{ shop_route('orders.pay', ['number' => $order->number]) }}">
            <i class="bi bi-credit-card" aria-hidden="true"></i>{{ __('shop/checkout.retry_payment') }}
          </a>
        @endif
        <a class="purchase-action {{ $paymentRequired ? 'purchase-action--secondary' : 'purchase-action--primary' }}" href="{{ $orderDetailsUrl }}">
          {{ __('shop/checkout.view_order') }}
        </a>
        <a class="purchase-action purchase-action--text" href="{{ shop_route('home.index') }}">{{ __('shop/checkout.continue_shopping') }}</a>
      </div>

      <section class="purchase-next-steps" aria-labelledby="next-steps-title">
        <h2 id="next-steps-title">{{ __('shop/checkout.what_happens_next') }}</h2>
        <ol>
          <li><span>1</span><div><strong>{{ __('shop/checkout.order_saved') }}</strong><p>{{ __('shop/checkout.confirmation_updates') }}</p></div></li>
          <li><span>2</span><div><strong>{{ $paymentRequired ? __('shop/checkout.complete_payment') : __('shop/checkout.order_processing_next') }}</strong><p>{{ $paymentRequired ? __('shop/checkout.complete_payment_help') : __('shop/checkout.order_processing_help') }}</p></div></li>
          <li><span>3</span><div><strong>{{ __('shop/checkout.shipment_tracking') }}</strong><p>{{ __('shop/checkout.tracking_updates') }}</p></div></li>
        </ol>
      </section>

      @hookwrapper('checkout.success.address_info')
      <section class="purchase-addresses" aria-labelledby="delivery-details-title">
        <h2 id="delivery-details-title">{{ __('order.address_info') }}</h2>
        <div class="purchase-address-grid">
          @if ($order->shipping_customer_name || $order->shipping_address_1)
            <article>
              <h3>{{ __('order.shipping_address') }}</h3>
              <address>
                <strong>{{ $order->shipping_customer_name }}</strong>
                @if ($order->shipping_telephone)<span>{{ $order->shipping_telephone }}</span>@endif
                <span>{{ collect([$order->shipping_address_1, $order->shipping_address_2, $order->shipping_city, $order->shipping_zone, $order->shipping_zipcode, $order->shipping_country])->filter()->implode(', ') }}</span>
              </address>
            </article>
          @endif
          <article>
            <h3>{{ __('order.payment_address') }}</h3>
            <address>
              <strong>{{ $order->payment_customer_name }}</strong>
              @if ($order->payment_telephone)<span>{{ $order->payment_telephone }}</span>@endif
              <span>{{ collect([$order->payment_address_1, $order->payment_address_2, $order->payment_city, $order->payment_zone, $order->payment_zipcode, $order->payment_country])->filter()->implode(', ') }}</span>
            </address>
          </article>
        </div>
      </section>
      @endhookwrapper

      @hook('checkout.success.footer')
    </section>
  </div>
@endsection
