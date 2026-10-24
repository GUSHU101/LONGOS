@php
  $isOnSale = (float) ($product['origin_price'] ?? 0) > (float) ($product['price'] ?? 0) && (float) ($product['price'] ?? 0) > 0;
  $saleRate = $isOnSale ? max(1, (int) round((1 - ($product['price'] / $product['origin_price'])) * 100)) : null;
  $sourceImage = $product['images'][0] ?? image_origin('');
  $responsiveSources = collect($product['image_srcsets'][0] ?? [])->filter(fn ($source) => !empty($source['url']) && !empty($source['width']));
  $responsiveSrcset = $responsiveSources->map(fn ($source) => $source['url'] . ' ' . (int) $source['width'] . 'w')->implode(', ');
  $imageMedium = $responsiveSources->first(fn ($source) => (int) $source['width'] >= 560)['url'] ?? $sourceImage;
  $priceId = 'related-product-price-' . (int) $product['id'];
@endphp
<article class="related-product-card">
  <a class="related-product-card__media" href="{{ $product['url'] }}">
    <img
      src="{{ $imageMedium }}"
      @if($responsiveSrcset) srcset="{{ $responsiveSrcset }}" @endif
      sizes="(min-width: 1100px) 24vw, (min-width: 768px) 32vw, 46vw"
      width="560" height="605"
      loading="lazy" decoding="async"
      alt="{{ $product['name'] }}">
    @if ($saleRate)
      <span class="related-product-card__badge">-{{ $saleRate }}%</span>
    @endif
  </a>

  <div class="related-product-card__body">
    <h3><a href="{{ $product['url'] }}">{{ $product['name_format'] }}</a></h3>
    @hookwrapper('product_list.item.price')
    @if ((system_setting('base.show_price_after_login') and current_customer()) or !system_setting('base.show_price_after_login'))
      <div class="related-product-card__price" id="{{ $priceId }}">
        <strong>{{ $product['price_format'] }}</strong>
        @if ($isOnSale)<del>{{ $product['origin_price_format'] }}</del>@endif
      </div>
    @else
      <div class="related-product-card__price" id="{{ $priceId }}">
        {{ __('common.before') }} <button type="button" class="price-new login-before-show-price">{{ __('common.login') }}</button> {{ __('common.show_price') }}
      </div>
    @endif
    @endhookwrapper
  </div>
</article>
