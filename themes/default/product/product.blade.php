@extends('layout.master')
@section('body-class', 'page-product')
@section('title', $product['meta_title'] ?: $product['name'])
@section('keywords', $product['meta_keywords'] ?: system_setting('base.meta_keywords'))
@section('description', $product['meta_description'] ?: system_setting('base.meta_description'))
@section('og_type', 'product')
@section('og_image', $product['images'][0]['popup'] ?? '')
@section('og_image_width', system_setting('base.product_image_origin_width'))
@section('og_image_height', system_setting('base.product_image_origin_height'))
@section('canonical', shop_route('products.show', ['product' => $product['id']]))

@php
  $primaryProductImage = $product['images'][0] ?? null;
@endphp
@section('preloads')
  @if ($primaryProductImage)
    <link
      rel="preload"
      as="image"
      href="{{ $primaryProductImage['preview'] ?? $primaryProductImage['large'] ?? $primaryProductImage['popup'] }}"
      imagesrcset="{{ $primaryProductImage['small'] ?? $primaryProductImage['preview'] }} 480w, {{ $primaryProductImage['preview'] }} 800w, {{ $primaryProductImage['large'] ?? $primaryProductImage['popup'] }} 1200w"
      imagesizes="(min-width: 992px) min(58vw, 820px), 100vw"
      fetchpriority="high">
  @endif
@endsection

@addScript(asset('vendor/vue/2.7/vue' . (!config('app.debug') ? '.min' : '') . '.js'), ['defer' => true])
@addScript(asset('vendor/swiper/swiper-bundle.min.js'), ['defer' => true])

@push('header')
  @php
    $schemaSkus = collect($product['skus'] ?? [])->filter(fn ($sku) => is_array($sku) && (int) ($sku['active'] ?? 0) === 1)->take(50);
    $productCanonical = shop_route('products.show', ['product' => $product['id']]);
    $productSchema = array_filter([
      '@context' => 'https://schema.org',
      '@type' => 'Product',
      '@id' => $productCanonical . '#product',
      'url' => $productCanonical,
      'name' => $product['name'],
      'description' => \Illuminate\Support\Str::limit(trim(strip_tags($product['description'] ?? ($product['meta_description'] ?? ''))), 5000, ''),
      'image' => collect($product['images'] ?? [])->pluck('popup')->filter()->values()->all(),
      'brand' => !empty($product['brand_name']) ? ['@type' => 'Brand', 'name' => $product['brand_name']] : null,
      'offers' => $schemaSkus->map(static fn (array $sku): array => [
        '@type' => 'Offer',
        'url' => $productCanonical . (!empty($sku['sku']) ? '?variant=' . rawurlencode((string) $sku['sku']) : ''),
        'sku' => (string) ($sku['sku'] ?? ''),
        'priceCurrency' => strtoupper((string) ($sku['currency_code'] ?? current_currency_code())),
        'price' => number_format(max(0, (float) ($sku['price_converted'] ?? $sku['price'] ?? 0)), 2, '.', ''),
        'availability' => (int) ($sku['quantity'] ?? 0) > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
        'itemCondition' => 'https://schema.org/NewCondition',
        'seller' => ['@id' => rtrim(shop_route('home.index'), '/') . '/#organization'],
      ])->values()->all(),
      'aggregateRating' => !empty($product_reviews) ? [
        '@type' => 'AggregateRating',
        'ratingValue' => (float) $product_reviews['average'],
        'reviewCount' => (int) $product_reviews['total'],
        'bestRating' => 5,
        'worstRating' => 1,
      ] : null,
      'review' => !empty($product_reviews['reviews']) ? collect($product_reviews['reviews'])->take(5)->map(static fn ($review): array => [
        '@type' => 'Review',
        'author' => ['@type' => 'Person', 'name' => $review->author_name],
        'datePublished' => $review->reviewed_at?->toDateString(),
        'name' => $review->title,
        'reviewBody' => $review->body,
        'reviewRating' => [
          '@type' => 'Rating',
          'ratingValue' => $review->rating,
          'bestRating' => 5,
          'worstRating' => 1,
        ],
      ])->values()->all() : null,
    ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
  @endphp
  <script type="application/ld+json">{!! json_encode($productSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
  @if ($has_video)
    <script src="{{ asset('vendor/video/video.min.js') }}" defer></script>
    <link rel="stylesheet" href="{{ asset('vendor/video/video-js.min.css') }}">
  @endif
  <style id="product-hide-elements">
    .product-description, .relations-wrap, footer {
      display: none;
    }
  </style>
@endpush

@php
  $iframeClass = request('iframe') ? 'd-none' : '';
  $productSkus = is_array($product['skus'] ?? null) ? $product['skus'] : [];
  $initialSku = collect($productSkus)->first(fn ($sku) => is_array($sku) && (int) ($sku['active'] ?? 0) === 1 && (int) ($sku['quantity'] ?? 0) > 0)
    ?? ($productSkus[0] ?? []);
  $initialSku = is_array($initialSku) ? $initialSku : [];
  $initialSkuId = (int) ($initialSku['id'] ?? 0);
  $initialStock = (int) ($initialSku['quantity'] ?? 0);
  $initialPrice = (string) ($initialSku['price_format'] ?? '—');
  $initialOriginPrice = (string) ($initialSku['origin_price_format'] ?? '');
  $initialOriginValue = (float) ($initialSku['origin_price'] ?? 0);
  $initialPriceValue = (float) ($initialSku['price'] ?? 0);
  $initialDiscountPercent = $initialOriginValue > $initialPriceValue && $initialPriceValue > 0
    ? max(1, (int) round((1 - ($initialPriceValue / $initialOriginValue)) * 100))
    : 0;
  $desktopGalleryLimit = 5;
  $desktopGalleryImages = array_slice($product['images'] ?? [], 0, $desktopGalleryLimit);
  $desktopGalleryTotal = count($product['images'] ?? []);
@endphp

@section('content')
  @hook('product.detail.before')

  @if (!request('iframe'))
    <x-shop-breadcrumb type="product" :value="$product['id']"/>
  @endif

  <div class="container product-container {{ request('iframe') ? 'pt-4' : '' }}">
    @hook('product.detail.content.before')
    <div class="row mb-md-5 mt-md-0" id="product-top">
      <div class="col-12 col-lg-7 mb-2">
        @hookwrapper('product.detail.images')
        <div class="product-image" aria-label="{{ $product['name'] }} gallery">
          @include('product.product-video')
          <div class="pdp-gallery-grid {{ $iframeClass }}" data-product-gallery>
            @forelse ($desktopGalleryImages as $item)
              @php
                $galleryButtonLabel = $product['name'] . ' – ' . $loop->iteration;
                if ($loop->last && $desktopGalleryTotal > $desktopGalleryLimit) {
                  $galleryButtonLabel .= ' · ' . __('shop/products.view_all_images', ['count' => $desktopGalleryTotal]);
                }
              @endphp
              <button
                type="button"
                class="pdp-gallery-item {{ $loop->first ? 'pdp-gallery-item--featured' : '' }}"
                data-product-gallery-open
                data-gallery-index="{{ $loop->index }}"
                data-gallery-image="{{ $item['popup'] ?? $item['large'] ?? $item['preview'] }}"
                aria-label="{{ $galleryButtonLabel }}">
                <picture>
                  <source
                    media="(min-width: 992px)"
                    srcset="{{ $item['small'] ?? $item['preview'] }} 480w, {{ $item['preview'] }} 800w, {{ $item['large'] ?? $item['popup'] }} 1200w"
                    sizes="{{ $loop->first ? 'min(58vw, 820px)' : 'min(29vw, 410px)' }}">
                  <img
                    src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs="
                    width="{{ system_setting('base.product_image_origin_width', 800) }}"
                    height="{{ system_setting('base.product_image_origin_height', 800) }}"
                    fetchpriority="{{ $loop->first ? 'high' : 'auto' }}"
                    loading="{{ $loop->first ? 'eager' : 'lazy' }}"
                    decoding="async"
                    alt="{{ $product['name'] }}">
                </picture>
                <span class="pdp-gallery-item__zoom" aria-hidden="true"><i class="bi bi-arrows-angle-expand"></i></span>
                @if ($loop->last && $desktopGalleryTotal > $desktopGalleryLimit)
                  <span class="pdp-gallery-item__count">{{ __('shop/products.view_all_images', ['count' => $desktopGalleryTotal]) }}</span>
                @endif
              </button>
            @empty
              <div class="pdp-gallery-item pdp-gallery-item--featured">
                <img src="{{ system_setting('base.placeholder') ?? asset('image/placeholder.png') }}" width="800" height="800" alt="{{ $product['name'] }}">
              </div>
            @endforelse
          </div>

          <div class="swiper pdp-mobile-gallery" id="swiper-mobile">
            <div class="swiper-wrapper">
              @foreach ($product['images'] as $item)
              <div class="swiper-slide d-flex align-items-center justify-content-center">
                <img src="{{ $loop->first ? $item['preview'] : 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=' }}"
                     @if ($loop->first)
                     srcset="{{ $item['small'] ?? $item['preview'] }} 480w, {{ $item['preview'] }} 800w, {{ $item['large'] ?? $item['popup'] }} 1200w"
                     @else
                     data-product-gallery-src="{{ $item['preview'] }}"
                     data-product-gallery-srcset="{{ $item['small'] ?? $item['preview'] }} 480w, {{ $item['preview'] }} 800w, {{ $item['large'] ?? $item['popup'] }} 1200w"
                     @endif
                     sizes="100vw"
                     fetchpriority="{{ $loop->first ? 'high' : 'auto' }}"
                     loading="{{ $loop->first ? 'eager' : 'lazy' }}"
                     decoding="async"
                     width="{{ system_setting('base.product_image_origin_width', 800) }}" height="{{ system_setting('base.product_image_origin_height', 800) }}" class="img-fluid seo-img" alt="{{ $product['name'] }}">
              </div>
              @endforeach
            </div>
            <div class="swiper-pagination mobile-pagination"></div>
          </div>
        </div>
        @endhookwrapper
      </div>

      <div class="col-12 col-lg-5">
        <div class="product-info product-mb-block" id="product-app">
          <div class="product-purchase-surface">
          @hookwrapper('product.detail.name')
          <div class="product-heading">
            <h1 class="mb-2 product-name">{{ $product['name'] }}</h1>
            <span :class="['product-availability', product.quantity > 0 ? 'is-available' : 'is-unavailable']"><i></i><span v-text="product.quantity > 0 ? '{{ __('shop/products.in_stock') }}' : '{{ __('shop/products.out_stock') }}'">{{ $initialStock > 0 ? __('shop/products.in_stock') : __('shop/products.out_stock') }}</span></span>
          </div>
          @if (!empty($product_reviews))
            <a
              class="product-review-summary"
              href="#customer-reviews"
              aria-label="{{ trans('LongosProductReviews::reviews.rating_link_label', ['rating' => number_format($product_reviews['average'], 1), 'count' => $product_reviews['total']]) }}">
              <span class="longos-review-stars longos-review-stars--average" style="--review-rating: {{ min(100, max(0, ($product_reviews['average'] / 5) * 100)) }}%" aria-hidden="true">★★★★★</span>
              <strong>{{ number_format($product_reviews['average'], 1) }}</strong>
              <span>{{ trans('LongosProductReviews::reviews.read_reviews', ['count' => $product_reviews['total']]) }}</span>
              <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </a>
          @endif
          @endhookwrapper
          @hookwrapper('product.detail.price')
          @if ((system_setting('base.show_price_after_login') and current_customer()) or !system_setting('base.show_price_after_login'))
            <div class="price-wrap d-flex align-items-end">
              <div class="new-price fs-1 lh-1 fw-bold me-2" v-text='product.price_format || @json($initialPrice)'>{{ $initialPrice }}</div>
              @if ($initialOriginValue > 0 && $initialOriginValue != $initialPriceValue)
                <div class="old-price text-muted text-decoration-line-through"
                     v-if="product.price != product.origin_price && product.origin_price !== 0"
                     v-text='product.origin_price_format || @json($initialOriginPrice)'>{{ $initialOriginPrice }}</div>
              @endif
              @hook('product.detail.origin_price.after')
            </div>
            <div class="product-sale-summary" v-if="product.origin_price > product.price && product.origin_price !== 0">
              <strong v-text="discountLabel">{{ __('shop/products.save_percentage', ['percent' => $initialDiscountPercent]) }}</strong>
            </div>
          @else
            <div class="product-price">
              <div class="text-dark fs-6">{{ __('common.before') }} <button type="button" class="price-new fs-6 login-before-show-price">{{ __('common.login') }}</button> {{ __('common.show_price') }}
              </div>
            </div>
          @endif

          @hook('product.detail.price.after')

          @endhookwrapper
          @hook('shop.product.detail.quantity.before')
          @hookwrapper('product.detail.variables')
          @if (is_array($product['variables'] ?? null) && count($product['variables']))
            <div class="product-variants-fallback mb-md-3" data-product-variants-fallback>
              @foreach ($product['variables'] as $fallbackVariable)
                @php
                  $fallbackValues = is_array($fallbackVariable['values'] ?? null)
                    ? $fallbackVariable['values']
                    : [];
                @endphp
                <div class="variable-group">
                  <label class="form-label mb-2">{{ $fallbackVariable['name'] ?? '' }}</label>
                  <select class="form-select product-variant-select" disabled aria-label="{{ $fallbackVariable['name'] ?? __('shop/products.choose_variant') }}">
                    @foreach ($fallbackValues as $fallbackValue)
                      <option>{{ $fallbackValue['name'] ?? '' }}</option>
                    @endforeach
                  </select>
                </div>
              @endforeach
            </div>
          @endif
          <div class="variables-wrap mb-md-3" v-if="source.variables.length">
            <div class="variable-group" v-for="variable, variable_index in source.variables" :key="variable_index">
              <label class="form-label mb-2" :for="`product-variant-${variable_index}`">
                @{{ variable.name }}
                <span class="text-secondary" v-if="selectedVariantsIndex[variable_index] !== undefined && selectedVariantsIndex[variable_index] !== null">
                  : @{{ variable.values[selectedVariantsIndex[variable_index]].name }}
                </span>
              </label>
              <select
                class="form-select product-variant-select"
                :id="`product-variant-${variable_index}`"
                :value="selectedVariantsIndex[variable_index] === undefined || selectedVariantsIndex[variable_index] === null ? '' : selectedVariantsIndex[variable_index]"
                @change="selectVariableValue(variable_index, $event)"
                :aria-label="variable.name">
                <option disabled value="">{{ __('shop/products.choose_variant') }}</option>
                <option v-for="value, value_index in variable.values" :key="value_index" :value="value_index" :disabled="value.disabled">
                  @{{ value.name }}
                </option>
              </select>
            </div>
          </div>
          @endhookwrapper

          @hook('shop.product.detail.product-btns.before')

          @hookwrapper('product.detail.quantity.input')
          <div class="product-quantity-field mb-md-3">
            <label class="product-quantity-field__label" for="product-detail-quantity">{{ __('rma.quantity') }}</label>
            <div class="quantity-wrap product-quantity-stepper" :class="{ 'is-disabled': maxPurchasableQuantity < 1 }" role="group" aria-label="{{ __('rma.quantity') }}" data-product-quantity-stepper data-max="{{ max(0, $initialStock) }}">
              <button class="btn quantity-reduce" type="button" :disabled="!canDecreaseQuantity" aria-controls="product-detail-quantity" aria-label="{{ __('shop/products.decrease_quantity') }}" title="{{ __('shop/products.decrease_quantity') }}" @click.stop.prevent="decreaseQuantity">
                <i class="bi bi-dash-lg" aria-hidden="true"></i>
              </button>
              <input id="product-detail-quantity" ref="quantityInput" type="text" class="form-control" value="1" :disabled="maxPurchasableQuantity < 1" inputmode="numeric" pattern="[0-9]*" autocomplete="off" maxlength="10" name="quantity" role="spinbutton" aria-label="{{ __('rma.quantity') }}" aria-describedby="product-detail-quantity-status" aria-valuemin="1" :aria-valuemax="Math.max(1, maxPurchasableQuantity)" :aria-valuenow="selectedQuantity" @input="handleQuantityInput" @blur="normalizeQuantity" @change="normalizeQuantity" @keydown.up.prevent="increaseQuantity" @keydown.down.prevent="decreaseQuantity">
              <button class="btn quantity-increase" type="button" :disabled="!canIncreaseQuantity" aria-controls="product-detail-quantity" aria-label="{{ __('shop/products.increase_quantity') }}" title="{{ __('shop/products.increase_quantity') }}" @click.stop.prevent="increaseQuantity">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
              </button>
            </div>
            <span id="product-detail-quantity-status" class="visually-hidden" role="status" aria-live="polite" v-text="quantityAnnouncement">{{ __('shop/products.quantity_selected', ['count' => 1]) }}</span>
          </div>
          @endhookwrapper

          <div class="product-btns">
            @hook('product.detail.buy.before')
            <div class="add-cart-btns">
              @hook('shop.product.detail.btns.before')

              @hookwrapper('product.detail.add_to_cart')
              <button
                class="btn btn-outline-dark add-cart product-cta product-cta--secondary fw-bold"
                data-fallback-sku-id="{{ $initialSkuId }}"
                :product-id="product.id"
                :product-price="product.price"
                :disabled="!product.quantity || product.active != 1"
                @click="addCart(false, this)"
              >
                <span class="product-cta__label">{{ __('shop/products.add_to_cart') }}</span>
              </button>
              @endhookwrapper
              @hookwrapper('product.detail.buy_now')
              <button
                class="btn btn-dark ms-md-3 btn-buy-now product-cta product-cta--primary fw-bold"
                data-fallback-sku-id="{{ $initialSkuId }}"
                :disabled="!product.quantity || product.active != 1"
                :product-id="product.id"
                :product-price="product.price"
                @click="addCart(true, this)"
              >
                <span class="product-cta__label">{{ __('shop/products.buy_now') }}</span>
              </button>
              @endhookwrapper

              @hook('shop.product.detail.btns.after')
            </div>
            @hook('product.detail.buy.after')
            @if (!$product['active'])
              <div class="text-danger"><i class="bi bi-exclamation-circle-fill"></i> {{ __('product.has_been_inactive') }}</div>
            @endif
          </div>

          <aside class="product-purchase-assurance" aria-label="{{ __('shop/products.purchase_assurance') }}">
            <ul class="product-assurance-list">
              <li><i class="bi bi-lock" aria-hidden="true"></i><span><strong>{{ __('shop/products.secure_checkout') }}</strong><small>{{ __('shop/products.secure_checkout_description') }}</small></span></li>
              <li><i class="bi bi-truck" aria-hidden="true"></i><span><strong>{{ __('shop/products.tracked_delivery') }}</strong><small>{{ __('shop/products.tracked_delivery_description') }}</small></span></li>
              <li><i class="bi bi-chat-dots" aria-hidden="true"></i><span><strong>{{ __('shop/products.easy_support') }}</strong><small>{{ __('shop/products.easy_support_description') }}</small></span></li>
            </ul>
            <div class="product-payment-methods">
              <span>{{ __('shop/products.accepted_payment_methods') }}</span>
              <ul aria-label="{{ __('shop/products.accepted_payment_methods') }}">
                <li class="is-visa" aria-label="Visa">VISA</li>
                <li class="is-mastercard" aria-label="Mastercard"><i></i><i></i></li>
                <li class="is-paypal" aria-label="PayPal">PayPal</li>
                <li class="is-amex" aria-label="American Express">AMEX</li>
              </ul>
            </div>
          </aside>
          </div>

          @php
            $productMarketingModules = \Beike\Services\DesignService::getPlacementModules('product');
          @endphp
          @if (count($productMarketingModules) || ($design ?? false))
            <div id="product-modules-box" class="product-marketing-modules" aria-label="{{ __('shop/products.purchase_assurance') }}">
              @foreach ($productMarketingModules as $marketingModule)
                {!! $marketingModule['html'] !!}
              @endforeach
            </div>
          @endif

          @hook('product.tab.iframe.before')
          @hookwrapper('shop.product.description')
          <div class="product-information-shell {{ $iframeClass }}">
            <section class="product-description product-mb-block" aria-label="{{ __('shop/products.product_information') }}">
              <div class="nav nav-tabs nav-overflow justify-content-start" role="tablist" aria-label="{{ __('shop/products.product_information') }}">
                @hook('shop.product.description.tabs.before')
                <button class="nav-link active" id="product-description-tab" data-bs-toggle="tab" data-bs-target="#product-description" type="button" role="tab" aria-controls="product-description" aria-selected="true">
                  {{ __('shop/products.product_details') }}
                </button>
                @if ($product['attributes'])
                  <button class="nav-link" id="product-attributes-tab" data-bs-toggle="tab" data-bs-target="#product-attributes" type="button" role="tab" aria-controls="product-attributes" aria-selected="false">
                    {{ __('admin/attribute.index') }}
                  </button>
                @endif
                @hook('product.tab.after.link')
              </div>
              <div class="tab-content">
                @hook('shop.product.description.tabs.content.before')
                <div class="tab-pane fade show active" id="product-description" role="tabpanel" aria-labelledby="product-description-tab" tabindex="0">
                  <div class="rich-text-editor-content">{!! $product['description'] !!}</div>
                </div>
                @if ($product['attributes'])
                  <div class="tab-pane fade" id="product-attributes" role="tabpanel" aria-labelledby="product-attributes-tab" tabindex="0">
                    <div class="table-responsive">
                      <table class="table attribute-table">
                        @foreach ($product['attributes'] as $group)
                          <thead>
                          <tr>
                            <th colspan="2" scope="colgroup">{{ $group['attribute_group_name'] }}</th>
                          </tr>
                          </thead>
                          <tbody>
                          @foreach ($group['attributes'] as $item)
                            <tr>
                              <th scope="row">{{ $item['attribute'] }}</th>
                              <td>{{ $item['attribute_value'] }}</td>
                            </tr>
                          @endforeach
                          </tbody>
                        @endforeach
                      </table>
                    </div>
                  </div>
                @endif
                @hook('product.tab.after.pane')
              </div>
            </section>
          </div>
          @endhookwrapper

          @hook('product.detail.after')
        </div>
      </div>
    </div>

    @if (!request('iframe') && !empty($product_reviews))
      @include('LongosProductReviews::shop.section', [
        'product' => $product,
        'reviews' => $product_reviews,
      ])
    @endif

    @hook('product.detail.content.after', $product)
  </div>

  @if ($relations && !request('iframe'))
    <section class="relations-wrap product-mb-block" aria-labelledby="related-products-title">
      <div class="container position-relative">
        <header class="relations-header">
          <h2 id="related-products-title">{{ __('shop/products.related_products_title') }}</h2>
        </header>
        <div class="product related-products-grid">
          @foreach ($relations as $item)
            @include('shared.related-product', ['product' => $item])
          @endforeach
        </div>
      </div>
    </section>
  @endif

  <dialog class="pdp-media-dialog" data-product-gallery-dialog aria-label="{{ $product['name'] }}">
    <button type="button" class="pdp-media-dialog__close" data-product-gallery-close aria-label="{{ __('common.cancel') }}"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    <button type="button" class="pdp-media-dialog__nav pdp-media-dialog__nav--previous" data-product-gallery-previous aria-label="{{ __('shop/products.previous_image') }}"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
    <div class="pdp-media-dialog__stage">
      <img data-product-gallery-dialog-image src="" alt="{{ $product['name'] }}">
      <span class="pdp-media-dialog__counter" data-product-gallery-counter aria-live="polite"></span>
    </div>
    <button type="button" class="pdp-media-dialog__nav pdp-media-dialog__nav--next" data-product-gallery-next aria-label="{{ __('shop/products.next_image') }}"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
  </dialog>

  @hook('product.detail.footer')
@endsection

@push('add-scripts')
  <script>
    document.addEventListener('DOMContentLoaded', function () {
    @hook('product.detail.script.before')

    let swiperMobile = null;
    let productGalleryDialogIndex = 0;
    let activeProductGalleryImages = [];
    const desktopGalleryMedia = window.matchMedia('(min-width: 992px)');
    const isIframe = bk.getQueryString('iframe', false);
    const productImageOriginWidth = @json(system_setting('base.product_image_origin_width', 800));
    const productImageOriginHeight = @json(system_setting('base.product_image_origin_height', 800));
    const productBaseImages = @json($product['images'] ?? []);
    const desktopProductGalleryLimit = 5;
    const productGalleryPlaceholder = 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=';
    const productGalleryViewAllTemplate = @json(__('shop/products.view_all_images', ['count' => '__COUNT__']));
    const escapeProductAttribute = (value) => String(value || '')
      .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const normalizeProductGalleryImage = (image) => {
      if (!image) return null;

      if (typeof image === 'string') {
        return { small: image, preview: image, large: image, popup: image, thumb: image };
      }

      if (typeof image !== 'object') return null;
      const fallback = image.popup || image.large || image.preview || image.small || image.thumb || '';
      if (!fallback) return null;

      return {
        small: image.small || image.preview || fallback,
        preview: image.preview || image.large || fallback,
        large: image.large || image.popup || image.preview || fallback,
        popup: image.popup || image.large || image.preview || fallback,
        thumb: image.thumb || image.small || image.preview || fallback,
      };
    };
    const composeProductGalleryImages = (...groups) => {
      const images = [];
      const seen = new Set();

      groups.forEach((group) => {
        (Array.isArray(group) ? group : []).forEach((candidate) => {
          const image = normalizeProductGalleryImage(candidate);
          const key = image?.popup || image?.large || image?.preview || image?.small;
          if (!image || !key || seen.has(key)) return;
          seen.add(key);
          images.push(image);
        });
      });

      return images;
    };
    const productImageSrcset = (image = {}) => [
      image.small ? `${image.small} 480w` : '',
      image.preview ? `${image.preview} 800w` : '',
      image.large ? `${image.large} 1200w` : '',
    ].filter(Boolean).join(', ');
    const renderDesktopProductGallery = (images = []) => {
      const gallery = document.querySelector('[data-product-gallery]');
      if (!desktopGalleryMedia.matches || !gallery || !Array.isArray(images) || !images.length) return;

      const fragment = document.createDocumentFragment();
      const productName = document.querySelector('.product-name')?.textContent?.trim() || '';
      images.slice(0, desktopProductGalleryLimit).forEach((image, index, visibleImages) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `pdp-gallery-item${index === 0 ? ' pdp-gallery-item--featured' : ''}`;
        button.dataset.productGalleryOpen = '';
        button.dataset.galleryIndex = String(index);
        button.dataset.galleryImage = image.popup || image.large || image.preview || '';
        const viewAllText = index === visibleImages.length - 1 && images.length > desktopProductGalleryLimit
          ? productGalleryViewAllTemplate.replace('__COUNT__', String(images.length))
          : '';
        button.setAttribute('aria-label', `${productName} – ${index + 1}${viewAllText ? ` · ${viewAllText}` : ''}`);

        const img = document.createElement('img');
        img.src = image.large || image.preview || image.popup || '';
        img.srcset = productImageSrcset(image);
        img.sizes = index === 0
          ? '(min-width: 992px) min(58vw, 820px), 100vw'
          : '(min-width: 992px) min(29vw, 410px), 100vw';
        img.alt = productName;
        img.width = productImageOriginWidth;
        img.height = productImageOriginHeight;
        img.fetchPriority = index === 0 ? 'high' : 'auto';
        img.loading = index === 0 ? 'eager' : 'lazy';
        img.decoding = 'async';

        const zoom = document.createElement('span');
        zoom.className = 'pdp-gallery-item__zoom';
        zoom.setAttribute('aria-hidden', 'true');
        zoom.innerHTML = '<i class="bi bi-arrows-angle-expand"></i>';
        button.append(img, zoom);
        if (viewAllText) {
          const count = document.createElement('span');
          count.className = 'pdp-gallery-item__count';
          count.textContent = viewAllText;
          button.append(count);
        }
        fragment.append(button);
      });
      gallery.replaceChildren(fragment);
    };

    const hydrateMobileProductGalleryAround = (activeIndex = 0, preloadAdjacent = false) => {
      const slides = Array.from(document.querySelectorAll('#swiper-mobile .swiper-slide'));
      if (!slides.length) return;

      const candidates = preloadAdjacent ? [activeIndex, activeIndex + 1, activeIndex - 1] : [activeIndex];
      candidates.forEach((candidate) => {
        if (candidate < 0 || candidate >= slides.length) return;
        const image = slides[candidate]?.querySelector('img[data-product-gallery-src]');
        if (!image) return;

        image.src = image.dataset.productGallerySrc || image.src;
        if (image.dataset.productGallerySrcset) image.srcset = image.dataset.productGallerySrcset;
        delete image.dataset.productGallerySrc;
        delete image.dataset.productGallerySrcset;
      });
    };

    const updateProductGalleryDialog = (requestedIndex = 0) => {
      const dialog = document.querySelector('[data-product-gallery-dialog]');
      const dialogImage = dialog?.querySelector('[data-product-gallery-dialog-image]');
      if (!dialog || !dialogImage || !activeProductGalleryImages.length) return;

      productGalleryDialogIndex = (requestedIndex + activeProductGalleryImages.length) % activeProductGalleryImages.length;
      const image = activeProductGalleryImages[productGalleryDialogIndex];
      const productName = document.querySelector('.product-name')?.textContent?.trim() || '';
      dialogImage.src = image.popup || image.large || image.preview || '';
      dialogImage.alt = `${productName} – ${productGalleryDialogIndex + 1}`;
      const counter = dialog.querySelector('[data-product-gallery-counter]');
      if (counter) counter.textContent = `${productGalleryDialogIndex + 1} / ${activeProductGalleryImages.length}`;
      dialog.classList.toggle('pdp-media-dialog--single', activeProductGalleryImages.length < 2);
    };

    activeProductGalleryImages = composeProductGalleryImages(productBaseImages);

    $(function () {
      const galleryDialog = document.querySelector('[data-product-gallery-dialog]');
      galleryDialog?.addEventListener('click', function (event) {
        if (event.target === galleryDialog) galleryDialog.close();
      });
      galleryDialog?.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowLeft') updateProductGalleryDialog(productGalleryDialogIndex - 1);
        if (event.key === 'ArrowRight') updateProductGalleryDialog(productGalleryDialogIndex + 1);
      });
    });

    // The server-rendered purchase panel is usable before Vue loads. This
    // small fallback keeps add-to-cart and buy-now working if an optional
    // storefront extension breaks Vue on a single product page.
    window.longosProductVueMounted = false;
    document.addEventListener('click', function (event) {
      const galleryTrigger = event.target.closest('[data-product-gallery-open]');
      if (galleryTrigger) {
        const dialog = document.querySelector('[data-product-gallery-dialog]');
        const index = Number.parseInt(galleryTrigger.dataset.galleryIndex || '0', 10);
        if (dialog && activeProductGalleryImages.length) {
          updateProductGalleryDialog(Number.isFinite(index) ? index : 0);
          if (!dialog.open && typeof dialog.showModal === 'function') dialog.showModal();
        }
        return;
      }

      const galleryPrevious = event.target.closest('[data-product-gallery-previous]');
      if (galleryPrevious) {
        updateProductGalleryDialog(productGalleryDialogIndex - 1);
        return;
      }

      const galleryNext = event.target.closest('[data-product-gallery-next]');
      if (galleryNext) {
        updateProductGalleryDialog(productGalleryDialogIndex + 1);
        return;
      }

      const galleryClose = event.target.closest('[data-product-gallery-close]');
      if (galleryClose) {
        galleryClose.closest('dialog')?.close();
        return;
      }

      const quantityButton = event.target.closest('[data-product-quantity-stepper] .quantity-reduce, [data-product-quantity-stepper] .quantity-increase');
      if (quantityButton && !window.longosProductVueMounted) {
        event.preventDefault();
        const stepper = quantityButton.closest('[data-product-quantity-stepper]');
        const input = stepper?.querySelector('input[name="quantity"]');
        if (!input) return;
        const quantityTools = window.LongosProductQuantity;
        const maximum = Math.max(1, Number(stepper.dataset.max) || 1);
        const direction = quantityButton.classList.contains('quantity-increase') ? 1 : -1;
        input.value = String(quantityTools
          ? quantityTools.step(input.value, direction, maximum)
          : Math.max(1, Math.min(maximum, (Number.parseInt(input.value, 10) || 1) + direction)));
        input.dispatchEvent(new Event('input', { bubbles: true }));
        return;
      }

      const button = event.target.closest('#product-app .add-cart, #product-app .btn-buy-now');
      if (!button || window.longosProductVueMounted) return;
      event.preventDefault();

      const skuId = Number(button.dataset.fallbackSkuId || 0);
      if (!skuId || !window.bk || typeof window.bk.addCart !== 'function') {
        window.layer?.msg('This product is temporarily unavailable. Please refresh and try again.');
        return;
      }

      const quantityInput = document.querySelector('#product-app .quantity-wrap input[name="quantity"]');
      const quantityMaximum = Math.max(1, Number(quantityInput?.closest('[data-product-quantity-stepper]')?.dataset.max) || 1);
      const quantity = window.LongosProductQuantity
        ? window.LongosProductQuantity.clamp(quantityInput?.value, quantityMaximum)
        : Math.max(1, Math.min(quantityMaximum, Number.parseInt(quantityInput?.value, 10) || 1));
      const buyNow = button.classList.contains('btn-buy-now');
      window.bk.addCart({sku_id: skuId, quantity: quantity, isBuyNow: buyNow}, button, function () {
        if (buyNow) location.href = @json(shop_route('checkout.index'));
      });
    });

    // Do not turn an optional CDN/cache failure into a blank product page.
    // The server panel and click fallback above remain intentionally usable.
    if (typeof window.Vue !== 'function') {
      window.jQuery?.('.bk-page-loading').fadeOut();
      window.jQuery?.('#product-hide-elements').remove();
    } else {
    let app = new Vue({
      el: '#product-app',

      data: {
        selectedVariantsIndex: [], // 选中的变量索引
        selectedVariantImage: null,
        images: [],
        product: {
          id: 0,
          images: "",
          model: "",
          origin_price: 0,
          origin_price_format: "",
          position: 0,
          price: 0,
          weight: 0,
          price_format: "",
          quantity: 0,
          sku: "",
        },
        quantity: 1,
        source: {
          skus: @json($product['skus'] ?? []),
          weight: @json($product['weight'] ?? ''),
          variables: @json($product['variables'] ?? []),
        },
        request_variant: @json(request('variant')),
        extraCartParams: {},
        @hook('product.detail.vue.data')
      },

      computed: {
        maxPurchasableQuantity() {
          if (!this.product) return 0;
          if (window.LongosProductQuantity) {
            return window.LongosProductQuantity.stockLimit(this.product.quantity, this.product.active);
          }
          if (this.product.active != 1) return 0;
          const stock = Math.floor(Number(this.product.quantity));
          return Number.isFinite(stock) && stock > 0 ? stock : 0;
        },
        selectedQuantity() {
          const value = Number.parseInt(this.quantity, 10) || 1;
          return Math.max(1, Math.min(Math.max(1, this.maxPurchasableQuantity), value));
        },
        canDecreaseQuantity() {
          return this.maxPurchasableQuantity > 0 && this.selectedQuantity > 1;
        },
        canIncreaseQuantity() {
          return this.maxPurchasableQuantity > 0 && this.selectedQuantity < this.maxPurchasableQuantity;
        },
        quantityAnnouncement() {
          return @json(__('shop/products.quantity_selected', ['count' => ':count'])).replace(':count', String(this.selectedQuantity));
        },
        discountLabel() {
          const originPrice = Number(this.product.origin_price);
          const price = Number(this.product.price);
          const percent = originPrice > price && price > 0
            ? Math.max(1, Math.round((1 - (price / originPrice)) * 100))
            : 0;
          return @json(__('shop/products.save_percentage', ['percent' => ':percent'])).replace(':percent', String(percent));
        }
      },

      beforeMount() {
        @hook('product.detail.vue.beforeMount')
      },

      mounted() {
        window.longosProductVueMounted = true;
        document.querySelectorAll('[data-product-variants-fallback]').forEach(function (element) { element.remove(); });
        $('.bk-page-loading').fadeOut();
        $('#product-hide-elements').remove();
        // Product records imported from external stores can have no SKU array
        // or malformed variant data. Do not let that hide the entire purchase
        // panel behind v-cloak.
        this.source.skus = Array.isArray(this.source.skus) ? this.source.skus : [];
        this.source.variables = Array.isArray(this.source.variables) ? this.source.variables : [];
        const skus = JSON.parse(JSON.stringify(this.source.skus));

        // The purchase panel must use a buyable SKU by default even for a
        // product without selectable variants. Imported feeds often put an
        // inactive or exhausted SKU first in their array.
        const firstPurchasableSku = skus.find(sku => sku.active == 1 && Number(sku.quantity) > 0);
        this.product = firstPurchasableSku || skus[0] || this.product;
        this.normalizeQuantity();
        this.images = composeProductGalleryImages(productBaseImages);

        if (this.source.variables.length && this.source.skus.length) {
          // 为 variables 里面每一个 values 的值添加 selected、disabled 字段
          this.source.variables.forEach(variable => {
            variable.values.forEach(value => {
              this.$set(value, 'selected', false)
              this.$set(value, 'disabled', false)
            })
          })

          if (this.request_variant && this.source.skus.find(sku => sku.sku == this.request_variant)) {
            const sku = this.source.skus.find(sku => sku.sku == this.request_variant)
            this.selectedVariantsIndex = JSON.parse(JSON.stringify(sku.variants))
            this.checkedVariants()
            this.getSelectedSku(false);
            this.updateSelectedVariantsStatus()
          } else {
            // Select a real purchasable combination on first paint. Leaving
            // every option blank made the SKU look unavailable and added an
            // unnecessary step before customers could add it to the cart.
            const defaultSku = this.source.skus.find(sku => sku.active == 1 && Number(sku.quantity) > 0) || this.source.skus[0];
            if (defaultSku && Array.isArray(defaultSku.variants)) {
              this.selectedVariantsIndex = JSON.parse(JSON.stringify(defaultSku.variants));
              this.checkedVariants();
              this.getSelectedSku(false);
              this.updateSelectedVariantsStatus();
            }
          }
        } else {
          this.product.weight = this.source.weight;
        }

        this.initSwiper();

        @hook('product.detail.vue.mounted')
      },

      methods: {
        clampQuantity(value) {
          if (window.LongosProductQuantity) {
            return window.LongosProductQuantity.clamp(value, this.maxPurchasableQuantity);
          }
          const parsed = Number.parseInt(value, 10) || 1;
          return Math.max(1, Math.min(Math.max(1, this.maxPurchasableQuantity), parsed));
        },

        setQuantity(value) {
          const quantity = this.clampQuantity(value);
          this.quantity = quantity;
          if (this.$refs.quantityInput) this.$refs.quantityInput.value = String(quantity);
        },

        normalizeQuantity() {
          this.setQuantity(this.quantity);
        },

        handleQuantityInput(event) {
          const digits = window.LongosProductQuantity
            ? window.LongosProductQuantity.digits(event.target.value)
            : String(event.target.value || '').replace(/\D/g, '').replace(/^0+(?=\d)/, '');
          if (!digits) {
            this.quantity = '';
            return;
          }
          const quantity = this.clampQuantity(digits);
          this.quantity = quantity;
          if (String(quantity) !== event.target.value) event.target.value = String(quantity);
        },

        decreaseQuantity() {
          if (!this.canDecreaseQuantity) return;
          this.setQuantity(this.selectedQuantity - 1);
        },

        increaseQuantity() {
          if (!this.canIncreaseQuantity) return;
          this.setQuantity(this.selectedQuantity + 1);
        },

        normalizeVariants(selected, length) {
          return Array.from({ length }, (_, i) => selected?.[i] ?? 0);
        },

        selectVariableValue(variable_index, event) {
          const value_index = Number.parseInt(event?.target?.value, 10);
          const value = this.source.variables[variable_index]?.values?.[value_index];
          if (!Number.isInteger(value_index) || !value || value.disabled) return;
          this.checkedVariableValue(variable_index, value_index, value);
        },

        checkedVariableValue(variable_index, value_index, value) {
          $('.product-image .swiper .swiper-slide').eq(0).addClass('active').siblings().removeClass('active');
          this.source.variables[variable_index].values.forEach((v, i) => {
            v.selected = i == value_index
          })

          // The image attached directly to the option takes precedence over
          // the SKU and product gallery when this option is selected.
          this.selectedVariantImage = normalizeProductGalleryImage(value?.gallery_image || value?.image);
          this.updateSelectedVariantsIndex();
          this.getSelectedSku();
          this.updateSelectedVariantsStatus()
          $('.variables-wrap').removeClass('error');
        },

        // 把对应 selectedVariantsIndex 下标选中 variables -> values 的 selected 字段为 true
        checkedVariants() {
          this.source.variables.forEach((variable, index) => {
            const value = variable.values[this.selectedVariantsIndex[index]];
            if (value) value.selected = true;
          })
          this.selectedVariantImage = this.getSelectedVariantImage();
        },

        getSelectedVariantImage() {
          for (let index = 0; index < this.source.variables.length; index += 1) {
            const valueIndex = this.selectedVariantsIndex[index];
            const value = this.source.variables[index]?.values?.[valueIndex];
            const image = normalizeProductGalleryImage(value?.gallery_image || value?.image);
            if (image) return image;
          }

          return null;
        },

        syncProductImages(sku = null) {
          const selectedImage = this.selectedVariantImage || this.getSelectedVariantImage();
          this.images = composeProductGalleryImages(
            selectedImage ? [selectedImage] : [],
            Array.isArray(sku?.images) ? sku.images : [],
            productBaseImages
          );

          setTimeout(() => this.updateProductImage(), 0);
        },

        getSelectedSku(reload = true) {
          const filledCount = this.selectedVariantsIndex.filter(v => v !== undefined && v !== null).length;

          // 通过 selectedVariantsIndex 的值比对 skus 的 variables
          let sku = this.source.skus.find(sku => sku.variants.toString() == this.selectedVariantsIndex.toString())

          if (filledCount < this.source.variables.length) {
            const selectedVariantsIndexLight = this.normalizeVariants(this.selectedVariantsIndex, this.source.variables.length);
            sku = this.source.skus.find(sku => sku.variants.toString() == selectedVariantsIndexLight.toString());
          }

          if (!sku) {
            if (reload) this.syncProductImages();
            return;
          }

          // The server-rendered base gallery is already responsive and is the
          // mobile LCP candidate. Initialising the default SKU must not replace
          // it with a variant source image before the first paint. Real variant
          // changes still refresh the gallery through the default reload=true.
          if (reload) this.syncProductImages(sku);
          this.product = sku;
          this.normalizeQuantity();

          if (filledCount == this.source.variables.length) {
            window.history.replaceState(null, '', bk.updateQueryStringParameter(window.location.href, 'variant', sku.sku));
          }

          closeVideo()
        },

        addCart(isBuyNow = false) {
          //判断如果是多规格 并且没有选择组合
          const realLength = this.selectedVariantsIndex.filter(v => v !== undefined).length;

          if (this.source.variables.length && realLength < this.source.variables.length) {
            layer.msg('{{ __('shop/products.error_variables') }}');

            $('html, body').animate({scrollTop: 0}, 200);
            $('.variables-wrap').addClass('error');
            return;
          }

          if (!this.product || this.product.active != 1 || Number(this.product.quantity) < 1) {
            layer.msg('{{ __('shop/products.out_stock') }}');
            return;
          }

          this.normalizeQuantity();

          let params = {
            sku_id: this.product.id,
            quantity: this.quantity,
            isBuyNow,
            ...this.extraCartParams // 插件扩展参数
          };

          // 插件扩展方法
          if (typeof this.beforeAddCartHooks === 'function') {
            const beforeAddCartHooks = this.beforeAddCartHooks(params);
            if (beforeAddCartHooks === false) {
              return;
            }
          }

          bk.addCart(params, null, () => {
            const path = @json(shop_route('checkout.index'));

            if (isIframe) {
              let index = parent.layer.getFrameIndex(window.name); //当前iframe层的索引
              parent.bk.getCarts();
              setTimeout(() => {
                parent.layer.close(index);
                if (isBuyNow) {
                  parent.location.href = path;
                } else {
                  parent.$('.btn-right-cart')[0].click()
                }
              }, 400);
            } else {
              if (isBuyNow) {
                location.href = path;
              }
            }
          });
        },

        updateSelectedVariantsIndex() {
          // 获取选中的 variables 内 value的 下标 index 填充到 selectedVariantsIndex 中
          this.source.variables.forEach((variable, index) => {
            variable.values.forEach((value, value_index) => {
              if (value.selected) {
                this.selectedVariantsIndex[index] = value_index
              }
            })
          })
        },

        updateSelectedVariantsStatus() {
          // 取出所有有库存且 active=1 的 SKU 的 variants
          const skus = this.source.skus.filter(sku => sku.active == 1 && sku.quantity > 0).map(sku => sku.variants);

          this.source.variables.forEach((variable, index) => {
            variable.values.forEach((value, value_index) => {
              // 拷贝当前已选择的规格索引
              const selectedVariantsIndex = this.selectedVariantsIndex.slice(0);

              selectedVariantsIndex[index] = value_index;

              const selectedSku = skus.find(sku => {
                return selectedVariantsIndex.every((v, i) => {
                  if (v === undefined || v === null) return true; // 这一维没选，不限制
                  return sku[i] == v; // 已经选择的维度必须匹配
                });
              });

              value.disabled = !selectedSku;
            });
          });
        },

        updateProductImage() {
          if (this.images.length) {
            activeProductGalleryImages = composeProductGalleryImages(this.images);
            renderDesktopProductGallery(this.images);
            if (swiperMobile) {
              swiperMobile.removeAllSlides()
              const slides = this.images.map((image, index) => `
                <div class="swiper-slide ${index == 0 ? 'active' : ''}">
                  <img src="${escapeProductAttribute(index === 0 ? image.preview : productGalleryPlaceholder)}" ${index === 0 ? `srcset="${escapeProductAttribute(productImageSrcset(image))}"` : `data-product-gallery-src="${escapeProductAttribute(image.preview)}" data-product-gallery-srcset="${escapeProductAttribute(productImageSrcset(image))}"`} sizes="100vw" alt="${escapeProductAttribute($('.product-name').text())}" class="img-fluid seo-img" width="${productImageOriginWidth}" height="${productImageOriginHeight}" fetchpriority="${index === 0 ? 'high' : 'auto'}" loading="${index === 0 ? 'eager' : 'lazy'}" decoding="async">
                <\/div>
              `);
              swiperMobile.appendSlide(slides);
              swiperMobile.update();
              swiperMobile.slideTo(0, 0, false);
              hydrateMobileProductGalleryAround(0);
              swiperMobile.pagination?.render?.();
              swiperMobile.pagination?.update?.();
            }
          }
        },

        initSwiper() {
          if (!desktopGalleryMedia.matches && document.querySelector('#swiper-mobile') && typeof Swiper === 'function') {
            swiperMobile = new Swiper("#swiper-mobile", {
              slidesPerView: 1,
              pagination: {
                el: ".mobile-pagination",
                clickable: true,
              },
              keyboard: { enabled: true, onlyInViewport: true },
              a11y: { enabled: true },
              observer: true,
              observeParents: true,
              on: {
                init(swiper) {
                  hydrateMobileProductGalleryAround(swiper.activeIndex || 0);
                },
                slideChange(swiper) {
                  hydrateMobileProductGalleryAround(swiper.activeIndex || 0, true);
                }
              }
            });
          }
        },

        @hook('product.detail.vue.methods')
      },

      @hook('product.detail.vue.hooks')
    });

    const selectedVariantsIndex = app.selectedVariantsIndex;
    const variables = app.source.variables;

    desktopGalleryMedia.addEventListener('change', function (event) {
      if (event.matches) {
        renderDesktopProductGallery(activeProductGalleryImages);
      } else if (!swiperMobile) {
        app.initSwiper();
      }
    });

    const selectedVariants = variables.map((variable, index) => {
      return variable.values[selectedVariantsIndex[index]]
    });

    // 优化详情描述里面的图片加载 -> 懒加载
    const descriptionImagesLazy = () => {
      var $content = $('.rich-text-editor-content');
      if (!$content.length) return;

      var $imgs = $content.find('img');
      $imgs.each(function (index, img) {
        var $img = $(img);

        $img.addClass('lazyload');

        var src = $img.attr('src');
        if (src) {
          $img.attr('data-src', src);
          $img.attr('src', '');
        }
      });
    };
    descriptionImagesLazy();

    @hook('product.detail.script.after')
    }
    });
  </script>
@endpush
