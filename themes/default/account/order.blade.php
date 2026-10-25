@extends('layout.master')

@section('body-class', 'page-account-order-list')

@section('content')
  <x-shop-breadcrumb type="static" value="account.order.index" />

  <div class="container">
    <div class="row">
      <x-shop-sidebar />

      <div class="col-12 col-md-9">
        <section class="card account-card order-list-panel" aria-labelledby="order-list-title">
          <header class="card-header order-list-heading">
            <div>
              <h1 class="card-title h4 mb-1" id="order-list-title">{{ __('shop/account/order.index') }}</h1>
              <p class="order-list-intro mb-0">{{ __('shop/account/order.list_intro') }}</p>
            </div>
          </header>

          <div class="card-body">
            @include('account.order_status')

            @if (count($orders))
              <div class="order-card-list" role="list" aria-label="{{ __('shop/account/order.index') }}">
                @hookwrapper('account.order.table_header', $orders)
                  @hookwrapper('account.order.mobile_table_header', $orders)
                    @foreach ($orders as $order)
                      @php
                        $statusTone = match ($order->status) {
                          'unpaid' => 'danger',
                          'paid' => 'primary',
                          'shipped' => 'info',
                          'completed' => 'success',
                          'refunding' => 'warning',
                          default => 'secondary',
                        };
                        $itemCount = $order->orderProducts->sum('quantity');
                        $detailId = 'order-' . $order->number;
                      @endphp
                      <article class="order-card" role="listitem" aria-labelledby="{{ $detailId }}-title">
                        <header class="order-card__header">
                          <div class="order-card__identity">
                            <span class="order-card__eyebrow">{{ __('shop/account/order.order_number') }}</span>
                            <div class="order-card__number-row">
                              <h2 class="order-card__number" id="{{ $detailId }}-title">{{ $order->number }}</h2>
                              <button class="order-copy-button" type="button"
                                data-copy-order="{{ $order->number }}"
                                data-copy-success="{{ __('shop/account/order.number_copied') }}"
                                data-copy-error="{{ __('shop/account/order.copy_failed') }}"
                                aria-label="{{ __('shop/account/order.copy_number', ['number' => $order->number]) }}">
                                <i class="bi bi-files" aria-hidden="true"></i>
                              </button>
                            </div>
                            <time class="order-card__date" datetime="{{ $order->created_at?->toAtomString() }}">{{ $order->created_at }}</time>
                          </div>
                          <span class="order-status-badge order-status-badge--{{ $statusTone }}">{{ $order->status_format }}</span>
                        </header>

                        <div class="order-card__body">
                          <div class="order-card__products" aria-label="{{ __('shop/account/order_info.order_items') }}">
                            @foreach ($order->orderProducts as $product)
                              <div class="order-card-product">
                                <a class="order-card-product__image" href="{{ shop_route('products.show', ['product' => $product->product_id, 'variant' => $product->product_sku]) }}" tabindex="-1" aria-hidden="true">
                                  <img src="{{ image_resize($product->image) }}" alt="" loading="lazy">
                                </a>
                                <div class="order-card-product__content">
                                  <a class="order-card-product__name" href="{{ shop_route('products.show', ['product' => $product->product_id, 'variant' => $product->product_sku]) }}">{{ $product->name }}</a>
                                  <span class="order-card-product__quantity">{{ __('shop/account/order.quantity', ['quantity' => $product->quantity]) }}</span>
                                  @hook('account.order.list.name.after', $product)
                                </div>
                              </div>
                            @endforeach
                          </div>

                          <dl class="order-card__summary">
                            <div>
                              <dt>{{ __('shop/account/order.items') }}</dt>
                              <dd>{{ __('shop/account/order.items_count', ['count' => $itemCount]) }}</dd>
                            </div>
                            <div>
                              <dt>{{ __('shop/account/order.amount') }}</dt>
                              <dd class="order-card__total">{{ currency_format($order->total, $order->currency_code, $order->currency_value) }}</dd>
                            </div>
                          </dl>
                        </div>

                        <footer class="order-card__actions" aria-label="{{ __('shop/account/order.actions_for', ['number' => $order->number]) }}">
                          @hook('account.order.actions', $order)

                          @if ($order->status == 'unpaid')
                            <a href="{{ shop_route('orders.pay', ['number' => $order->number]) }}" class="btn btn-primary">{{ __('shop/account/order_info.to_pay') }}</a>
                          @elseif ($order->status == 'shipped')
                            <button class="btn btn-primary" type="button"
                              data-order-action="complete"
                              data-action-url="{{ shop_route('orders.complete', ['number' => $order->number]) }}"
                              data-confirm="{{ __('shop/account/order_info.confirm_receipt_prompt') }}"
                              data-processing="{{ __('shop/account/order_info.processing') }}"
                              data-error="{{ __('shop/account/order_info.action_failed') }}">
                              {{ __('shop/account/order_info.confirm_receipt') }}
                            </button>
                          @endif

                          <a href="{{ shop_route('account.order.show', ['number' => $order->number]) }}" class="btn btn-outline-primary">{{ __('shop/account/order.check') }}</a>

                          @if ($order->status == 'unpaid' || $order->status == 'paid')
                            <button class="btn btn-link order-card__danger-action" type="button"
                              data-order-action="cancel"
                              data-action-url="{{ shop_route('orders.cancel', ['number' => $order->number]) }}"
                              data-confirm="{{ $order->status == 'paid' ? __('shop/account/order_info.refund_prompt') : __('shop/account/order_info.cancel_prompt') }}"
                              data-processing="{{ __('shop/account/order_info.processing') }}"
                              data-error="{{ __('shop/account/order_info.action_failed') }}">
                              {{ $order->status == 'paid' ? __('shop/account/order_info.request_refund') : __('shop/account/order_info.cancel') }}
                            </button>
                          @endif

                          @hook('account.order.actions_after', $order)
                        </footer>
                      </article>
                    @endforeach
                  @endhookwrapper
                @endhookwrapper
              </div>

              <div class="order-list-pagination">{{ $orders->links('shared/pagination/bootstrap-4') }}</div>
            @else
              <div class="order-list-empty" role="status">
                <span class="order-list-empty__icon" aria-hidden="true"><i class="bi bi-bag"></i></span>
                <h2 class="h5">{{ __('shop/account/order.empty_title') }}</h2>
                <p>{{ __('shop/account/order.empty_description') }}</p>
                <a class="btn btn-primary" href="{{ shop_route('home.index') }}">{{ __('shop/account/order.start_shopping') }}</a>
              </div>
            @endif

            <div class="visually-hidden" role="status" aria-live="polite" aria-atomic="true" data-order-feedback></div>
          </div>
        </section>
      </div>
    </div>
  </div>
@endsection
