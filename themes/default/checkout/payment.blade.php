@extends('layout.master')

@section('body-class', 'page-payment')
@section('title', __('shop/checkout.payment_page_title') . ' · ' . site_brand_name())

@push('header')
  <meta name="robots" content="noindex,nofollow,noarchive">
@endpush

@section('content')
  <x-shop-breadcrumb type="static" value="checkout.index" />

  <div class="container purchase-state-shell" aria-labelledby="payment-page-title">
    <div class="purchase-progress">
      @include('shared.steps', ['steps' => 3])
    </div>

    @if ($errors->any())
      <div class="purchase-alert" role="alert" tabindex="-1">
        <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
        <div><strong>{{ __('shop/checkout.payment_needs_attention') }}</strong><p>{{ $errors->first() }}</p></div>
      </div>
    @endif

    <div class="payment-layout">
      <section class="purchase-card payment-card" aria-labelledby="payment-page-title">
        <header class="purchase-card__header">
          <span class="purchase-status-icon purchase-status-icon--pending" aria-hidden="true"><i class="bi bi-lock"></i></span>
          <div>
            <p class="purchase-eyebrow">{{ __('shop/steps.payment') }}</p>
            <h1 id="payment-page-title">{{ __('shop/checkout.payment_page_title') }}</h1>
            <p>{{ __('shop/checkout.payment_page_intro') }}</p>
          </div>
        </header>

        <dl class="purchase-order-facts">
          <div><dt>{{ __('shop/checkout.payment_order_number') }}</dt><dd>{{ $order->number }}</dd></div>
          <div><dt>{{ __('shop/checkout.payment_amounts_payable') }}</dt><dd>{{ $order->total_format }}</dd></div>
          <div><dt>{{ __('shop/checkout.payment_payment_method') }}</dt><dd>{{ $order->payment_method_name }}</dd></div>
        </dl>
        @hook('checkout.payment_method_name.after')

        <div class="payment-provider-surface" aria-label="{{ __('shop/checkout.payment_provider_area') }}">
          {!! $payment !!}
        </div>

        @hook('payment.footer')
      </section>

      <aside class="purchase-help-card" aria-labelledby="payment-help-title">
        <span class="purchase-help-card__icon" aria-hidden="true"><i class="bi bi-shield-check"></i></span>
        <h2 id="payment-help-title">{{ __('shop/checkout.payment_secure_title') }}</h2>
        <p>{{ __('shop/checkout.payment_secure_notice') }}</p>
        <p class="purchase-help-card__note">{{ __('shop/checkout.payment_not_charged_notice') }}</p>
        <div class="purchase-help-card__links">
          <a href="{{ shop_route('orders.show', ['number' => $order->number]) }}">{{ __('shop/checkout.view_order') }}</a>
          <a href="{{ shop_route('home.index') }}">{{ __('shop/checkout.continue_shopping') }}</a>
        </div>
      </aside>
    </div>
  </div>
@endsection
