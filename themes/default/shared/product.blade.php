@php
  $isOnSale = $product['price'] < $product['origin_price'] && $product['origin_price'] > 0;
  $saleRate = $isOnSale ? max(1, round((1 - ($product['price'] / $product['origin_price'])) * 100)) : null;
  $cardImages = array_values($product['images'] ?? []);
  $cardImageSrcsets = array_values($product['image_srcsets'] ?? []);
  $placeholder = image_origin('');
  $buildSrcset = static fn (array $sources): string => collect($sources)
    ->filter(fn ($source) => !empty($source['url']) && !empty($source['width']))
    ->map(fn ($source) => $source['url'] . ' ' . (int) $source['width'] . 'w')
    ->implode(', ');
  $primaryImage = $cardImages[0] ?? $placeholder;
  $primarySrcset = $buildSrcset($cardImageSrcsets[0] ?? []);
  $secondaryImage = $cardImages[1] ?? '';
  $secondarySrcset = $buildSrcset($cardImageSrcsets[1] ?? []);
@endphp
<article class="product-wrap {{ request('style_list') ?? '' }} {{ $style_list ?? '' }}">
  <div class="image">
    @hook('product_list.item.image.tag')

    <a href="{{ $product['url'] }}">
      @hookwrapper('product_list.item.image')
      <img
        width="800"
        height="{{ calculate_height_by_ratio(800) }}"
        data-sizes="auto"
        data-src="{{ $primaryImage }}"
        @if($primarySrcset) data-srcset="{{ $primarySrcset }}" @endif
        src="{{ $placeholder }}"
        alt="{{ $product['name'] }}"
        class="img-fluid image-before lazyload" decoding="async">
        @if ($secondaryImage)
        <img
        width="800"
        height="{{ calculate_height_by_ratio(800) }}"
        src="{{ $secondaryImage }}"
        @if($secondarySrcset) srcset="{{ $secondarySrcset }}" sizes="(max-width: 767px) 50vw, (max-width: 1199px) 33vw, 20vw" @endif
        alt=""
        class="img-fluid image-after" loading="lazy" decoding="async">
        @endif
      @endhookwrapper
      @if ($saleRate)
        <span class="product-badge">-{{ $saleRate }}%</span>
      @endif
    </a>
    @if (!request('style_list') || request('style_list') == 'grid' || ($style_list ?? '' == 'grid'))
      <div class="button-wrap">
        @hookwrapper('shared.product.btn.add_cart')
        <button type="button"
          class="btn btn-dark text-light btn-add-cart"
          product-id="{{ $product['sku_id'] }}"
          product-price="{{ $product['price'] }}"
          onclick="bk.addCart({sku_id: '{{ $product['sku_id'] }}'}, this)">
          <i class="bi bi-cart"></i>
          {{ __('shop/products.add_to_cart') }}
        </button>
        @endhookwrapper
        @hookwrapper('shared.product.btn.quick_view')
        <button type="button"
          class="btn btn-dark text-light btn-quick-view"
          product-id="{{ $product['sku_id'] }}"
          product-price="{{ $product['price'] }}"
          data-bs-toggle="tooltip"
          data-bs-placement="top"
          title="{{ __('common.quick_view') }}"
          aria-label="{{ __('common.quick_view') }} {{ $product['name'] }}"
          onclick="bk.productQuickView({{ $product['id'] }})">
          <i class="bi bi-eye"></i>
        </button>
        @endhookwrapper
        @hookwrapper('shared.product.btn.add_to_favorites')
        <button type="button"
          class="btn btn-dark text-light btn-wishlist"
          product-id="{{ $product['sku_id'] }}"
          product-price="{{ $product['price'] }}"
          data-bs-toggle="tooltip"
          data-bs-placement="top"
          title="{{ __('shop/products.add_to_favorites') }}"
          aria-label="{{ __('shop/products.add_to_favorites') }} {{ $product['name'] }}"
          data-in-wishlist="{{ $product['in_wishlist'] }}"
          onclick="bk.addWishlist('{{ $product['id'] }}', this)">
          <i class="bi bi-heart{{ $product['in_wishlist'] ? '-fill' : '' }}"></i>
        </button>
        @endhookwrapper
      </div>
    @endif
  </div>
  <div class="product-bottom-info">
    @hook('product_list.item.name.before')
    <h3 class="product-name"><a href="{{ $product['url'] }}">{{ $product['name_format'] }}</a></h3>

    @hookwrapper('product_list.item.price')
    @if ((system_setting('base.show_price_after_login') and current_customer()) or !system_setting('base.show_price_after_login'))
      <div class="product-price" product-id="{{ $product['sku_id'] }}">
        <span class="price-new">{{ $product['price_format'] }}</span>
        @if ($product['price'] != $product['origin_price'] && $product['origin_price'] > 0)
          <span class="price-old">{{ $product['origin_price_format'] }}</span>
        @endif
      </div>
    @else
      <div class="product-price" product-id="{{ $product['sku_id'] }}">
        <div class="text-dark fs-6">{{ __('common.before') }} <button type="button" class="price-new fs-6 login-before-show-price">{{ __('common.login') }}</button> {{ __('common.show_price') }}</div>
      </div>
    @endif
    @endhookwrapper

    @if (request('style_list') == 'list' || ($style_list ?? '' == 'list'))
      <div class="button-wrap mt-3">
        <button type="button"
          class="btn btn-dark text-light"
          onclick="bk.addCart({sku_id: '{{ $product['sku_id'] }}'}, this)">
          <i class="bi bi-cart"></i>
          {{ __('shop/products.add_to_cart') }}
        </button>
      </div>

      <div class="mt-2 quick-view-wish">
        <button type="button"
          class="btn btn-link p-0 btn-quick-view text-secondary"
          product-id="{{ $product['sku_id'] }}"
          product-price="{{ $product['price'] }}"
          aria-label="{{ __('common.quick_view') }} {{ $product['name'] }}"
          onclick="bk.productQuickView({{ $product['id'] }})">
          <i class="bi bi-eye"></i>
          {{ __('common.quick_view') }}
        </button>
        <br>
        <button type="button" class="btn btn-link p-0 mt-1 text-secondary btn-wishlist" aria-label="{{ __('shop/products.add_to_favorites') }} {{ $product['name'] }}" data-in-wishlist="{{ $product['in_wishlist'] }}" onclick="bk.addWishlist('{{ $product['id'] }}', this)">
          <i class="bi bi-heart{{ $product['in_wishlist'] ? '-fill' : '' }} me-1"></i> {{ __('shop/products.add_to_favorites') }}
        </button>
      </div>
    @endif
  </div>

  @hook('product_list.item.after')
</article>
