@php
  $isOnSale = $product['price'] < $product['origin_price'] && $product['origin_price'] > 0;
  $saleRate = $isOnSale ? max(1, round((1 - ($product['price'] / $product['origin_price'])) * 100)) : null;
  $isList = request('style_list') === 'list' || ($style_list ?? '') === 'list';
@endphp
<article class="product-wrap christmas-product-card {{ $isList ? 'list' : 'grid' }}">
  <div class="image christmas-product-card__media">
    @hook('product_list.item.image.tag')
    <a href="{{ $product['url'] }}" aria-label="{{ $product['name'] }}">
      @hookwrapper('product_list.item.image')
      <img width="600" height="600" data-sizes="auto" data-src="{{ $product['images'][0] ?? image_origin('') }}" src="{{ image_origin('') }}" alt="{{ $product['name'] }}" class="img-fluid image-before lazyload" decoding="async">
      @if (count($product['images']) > 1)
        <img width="600" height="600" src="{{ $product['images'][1] }}" alt="" class="img-fluid image-after" loading="lazy" decoding="async">
      @endif
      @endhookwrapper
      @if ($saleRate)<span class="christmas-product-card__badge">Save {{ $saleRate }}%</span>@endif
    </a>
    <div class="christmas-product-card__actions">
      @hookwrapper('shared.product.btn.add_cart')
      <button type="button" class="christmas-product-card__cart" product-id="{{ $product['sku_id'] }}" product-price="{{ $product['price'] }}" onclick="bk.addCart({sku_id: '{{ $product['sku_id'] }}'}, this)"><i class="bi bi-bag" aria-hidden="true"></i><span>{{ __('shop/products.add_to_cart') }}</span></button>
      @endhookwrapper
      @hookwrapper('shared.product.btn.quick_view')
      <button type="button" class="christmas-product-card__quick" aria-label="{{ __('common.quick_view') }} {{ $product['name'] }}" onclick="bk.productQuickView({{ $product['id'] }})"><i class="bi bi-eye" aria-hidden="true"></i></button>
      @endhookwrapper
    </div>
  </div>
  <div class="product-bottom-info christmas-product-card__content">
    @hook('product_list.item.name.before')
    <h3 class="product-name"><a href="{{ $product['url'] }}">{{ $product['name_format'] }}</a></h3>
    @hookwrapper('product_list.item.price')
    @if ((system_setting('base.show_price_after_login') and current_customer()) or !system_setting('base.show_price_after_login'))
      <div class="product-price" product-id="{{ $product['sku_id'] }}">
        <span class="price-new">{{ $product['price_format'] }}</span>
        @if ($isOnSale)<span class="price-old">{{ $product['origin_price_format'] }}</span>@endif
      </div>
    @else
      <div class="product-price" product-id="{{ $product['sku_id'] }}"><button type="button" class="login-before-show-price">{{ __('common.login') }}</button> {{ __('common.show_price') }}</div>
    @endif
    @endhookwrapper
  </div>
  @hook('product_list.item.after')
</article>
