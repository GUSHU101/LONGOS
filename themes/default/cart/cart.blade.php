@extends('layout.master')

@section('body-class', 'page-cart')

@push('header')
  <script src="{{ asset('vendor/vue/2.7/vue' . (!config('app.debug') ? '.min' : '') . '.js') }}"></script>
  <script src="{{ asset('vendor/element-ui/index.js') }}"></script>
  <script src="{{ asset('vendor/scrolltofixed/jquery-scrolltofixed-min.js') }}"></script>
  <link rel="stylesheet" href="{{ asset('vendor/element-ui/index.css') }}">
@endpush

@section('content')
  <x-shop-breadcrumb type="static" value="carts.index" />
  @hook('shop.cart.content.before')

  <div class="container cart-page-shell" id="app-cart" v-cloak>
    @if ($errors->has('error'))
      <x-shop-alert type="danger" msg="{{ $errors->first('error') }}" class="mt-4" />
    @endif

    <div class="cart-progress" aria-label="{{ __('shop/carts.checkout_progress') }}">
      @include('shared.steps', ['steps' => 1])
    </div>

    <header class="cart-page-heading">
      <div>
        <p class="cart-page-eyebrow">{{ __('shop/carts.shopping_bag') }}</p>
        <h1>{{ __('shop/carts.index') }}</h1>
      </div>
      <p class="cart-page-count" aria-live="polite">
        @{{ allProduct }} {{ __('shop/carts.items') }}
      </p>
    </header>

    <div class="cart-layout" v-if="products.length">
      <section class="cart-items-panel" aria-labelledby="cart-products-title">
        <div class="cart-items-toolbar">
          <label class="cart-check-control" for="check-all">
            <input class="form-check-input" type="checkbox" id="check-all" v-model="allSelected">
            <span>{{ __('shop/carts.select_all') }}</span>
          </label>
          <h2 id="cart-products-title">{{ __('shop/carts.commodity') }}</h2>
          <span class="cart-toolbar-count">@{{ products.length }} {{ __('shop/carts.items') }}</span>
          <div class="cart-extension-headers">@hook('shop.cart.index.table.headers')</div>
        </div>

        <div class="cart-items-list">
          <article v-for="product, index in products" :key="product.cart_id" class="cart-line-item" :class="{ 'is-selected': product.selected }">
            <label class="cart-item-selector" :for="'cart-product-' + product.cart_id">
              <input class="form-check-input" type="checkbox" :id="'cart-product-' + product.cart_id" :aria-label="'{{ __('shop/carts.select_product') }} ' + product.name" @change="checkedCartTr(index)" v-model="product.selected">
            </label>

            <a :href="product.url" class="cart-item-media">
              <img :src="product.image_url" :alt="product.name" loading="lazy" decoding="async">
            </a>

            <div class="cart-item-content">
              <div class="cart-item-heading">
                <div class="cart-item-copy">
                  <a class="cart-item-name" :href="product.url" v-text="product.name"></a>
                  @hook('cart.product.name.after')
                  <p class="cart-item-variant" v-if="product.variant_labels">@{{ product.variant_labels }}</p>
                  <div class="cart-item-unit-price" v-html="product.price_format"></div>
                </div>

                <div class="cart-item-action">
                  @hook('shop.cart.index.table.action.before')
                  <button type="button" :data-id="product.sku_id" :aria-label="'{{ __('common.delete') }} ' + product.name" class="cart-remove-button" @click.stop="checkedBtnDelete(product.cart_id)">
                    <i class="bi bi-trash3" aria-hidden="true"></i>
                    <span>{{ __('common.delete') }}</span>
                  </button>
                  @hook('shop.cart.index.table.action.after')
                </div>
              </div>

              <div class="cart-item-purchase">
                <div class="cart-quantity-field">
                  <span class="cart-field-label">{{ __('shop/carts.quantity') }}</span>
                  <div class="quantity-wrap cart-quantity-stepper" role="group" :aria-label="'{{ __('shop/carts.quantity') }} ' + product.name">
                    <button class="btn quantity-reduce" :data-id="product.sku_id" type="button" :aria-label="'{{ __('shop/carts.decrease_quantity') }} ' + product.name">
                      <i class="bi bi-dash-lg" aria-hidden="true"></i>
                    </button>
                    <input type="number" class="form-control" :data-id="product.sku_id" :aria-label="'{{ __('shop/carts.quantity') }} ' + product.name" @input="quantityChange(product.quantity, product.cart_id, product.sku_id)" onkeyup="this.value=this.value.replace(/\D/g,'')" v-model.number="product.quantity" name="quantity" inputmode="numeric" pattern="[0-9]*" min="1" step="1" minimum="1">
                    <button class="btn quantity-increase" :data-id="product.sku_id" type="button" :aria-label="'{{ __('shop/carts.increase_quantity') }} ' + product.name">
                      <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    </button>
                  </div>
                </div>

                <div class="cart-item-subtotal">
                  <span class="cart-field-label">{{ __('shop/carts.subtotal') }}</span>
                  <strong v-html="product.subtotal_format"></strong>
                </div>
                <div class="cart-item-extensions">@hook('shop.cart.index.table.body')</div>
              </div>
            </div>
          </article>
        </div>

        <div class="cart-items-footer">
          <a href="{{ shop_route('home.index') }}" class="cart-continue-link">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            {{ __('shop/carts.continue_shopping') }}
          </a>
          @hook('carts.products.after')
        </div>
      </section>

      <aside class="cart-summary-column" aria-labelledby="cart-summary-title">
        <div class="cart-summary-card">
          <h2 id="cart-summary-title">{{ __('shop/checkout.order_summary') }}</h2>

          <dl class="cart-summary-list">
            @hook('shop.cart.index.total.before')
            <div>
              <dt>{{ __('shop/carts.all') }}</dt>
              <dd>@{{ allProduct }}</dd>
            </div>
            <div>
              <dt>{{ __('shop/carts.selected') }}</dt>
              <dd>@{{ total_quantity }}</dd>
            </div>
            @hook('shop.cart.index.total.after')
            <div class="cart-summary-total">
              <dt>{{ __('shop/carts.product_total') }}</dt>
              <dd>@{{ amount_format }}</dd>
            </div>
          </dl>

          @hookwrapper('cart.confirm')
          <button type="button" class="cart-checkout-button" @click="checkedBtnToCheckout">
            <span>{{ __('shop/carts.to_checkout') }}</span>
            <i class="bi bi-arrow-right" aria-hidden="true"></i>
          </button>
          @endhookwrapper

          <div class="cart-assurance-list">
            <p>
              <i class="bi bi-shield-check" aria-hidden="true"></i>
              <span><strong>{{ __('shop/checkout.secure_checkout') }}</strong>{{ __('shop/checkout.encrypted_payment') }}</span>
            </p>
            <p>
              <i class="bi bi-truck" aria-hidden="true"></i>
              <span><strong>{{ __('shop/checkout.shipment_tracking') }}</strong>{{ __('shop/checkout.tracking_delivery_progress') }}</span>
            </p>
          </div>
        </div>
      </aside>
    </div>

    <div v-else class="cart-empty-state">
      <div class="empty-cart-icon" aria-hidden="true">
        <i class="bi bi-bag"></i>
      </div>
      <div class="empty-cart-text">
        <h2>{{ __('shop/carts.cart_empty') }}</h2>
        <p>{{ __('shop/carts.go_buy') }}</p>
      </div>
      <a href="{{ shop_route('home.index') }}" class="cart-empty-action">{{ __('shop/carts.go_shopping') }}</a>
    </div>
  </div>

  @hook('carts.footer')
@endsection

@push('add-scripts')
  <script>
    const cartIndexUrl = @json(shop_route('carts.index'));
    const cartSelectUrl = @json(shop_route('carts.select'));
    const cartUnselectUrl = @json(shop_route('carts.unselect'));
    const cartItemUrl = (cartId) => `${cartIndexUrl.replace(/\/$/, '')}/${encodeURIComponent(cartId)}`;

    var app = new Vue({
      el: "#app-cart",
      data: {
        products: @json($carts),
        total_quantity: @json($quantity),
        amount: @json($amount),
        amount_format: @json($amount_format),

        @hook('shop.cart.index.vue.data')
      },

      computed: {
        allSelected: {
          get() {
            return !this.products.length ? false : this.products.every(s => s.selected)
          },
          set(val) {
            this.products.map(e => e.selected = val)
            this.allSelectedBtn()
          }
        },

        allProduct() {
          return this.products.map(e => Number(e.quantity) || 0).reduce((n,m) => n + m, 0);
        },

        @hook('shop.cart.index.vue.computed')
      },

      methods: {
        checkedBtnToCheckout() {
          if (!this.products.some(e => e.selected)) {
            layer.msg('{{ __('shop/carts.empty_selected_products') }}', ()=>{})
            return
          }

          location = '{{ shop_route("checkout.index") }}'
        },

        quantityChange(quantity, cart_id, sku_id) {
          if (!quantity || isNaN(quantity)) {
            return
          }

          const self = this;
          $http.put(cartItemUrl(cart_id), {quantity: quantity, sku_id}, {hload: true}).then((res) => {
            this.setUpdateData(res);
          })
        },

        checkedBtnDelete(cart_id) {
          const self = this;

          $http.delete(cartItemUrl(cart_id)).then((res) => {
            this.setUpdateData(res);
          })
        },

        checkedCartTr(index) {
          const selected = this.products[index].selected;
          const cart_id = this.products[index].cart_id;
          $http.post(selected ? cartSelectUrl : cartUnselectUrl, {cart_ids: [cart_id]}, {hload: true}).then((res) => {
            this.setUpdateData(res);
          })
        },

        allSelectedBtn() {
          const cart_ids = this.products.map(x => x.cart_id)

          $http.post(this.allSelected ? cartSelectUrl : cartUnselectUrl, {cart_ids: cart_ids}, {hload: true}).then((res) => {
            this.setUpdateData(res);
          })
        },

        setUpdateData(res) {
          if(res.status != 'success'){
            layer.msg(res.message)
          }
          this.products = res.data.carts
          this.amount_format = res.data.amount_format
          this.total_quantity = res.data.quantity
          bk.getCarts()
        },

        @hook('shop.cart.index.vue.methods')
      },

      @hook('shop.cart.index.vue.options')
    })
  </script>
@endpush
