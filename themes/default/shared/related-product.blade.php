@php
  $isOnSale = (float) ($product['origin_price'] ?? 0) > (float) ($product['price'] ?? 0) && (float) ($product['price'] ?? 0) > 0;
  $saleRate = $isOnSale ? max(1, (int) round((1 - ($product['price'] / $product['origin_price'])) * 100)) : null;
  $sourceImage = $product['image'] ?? '';
  $imageSmall = $sourceImage ? image_resize($sourceImage, 360, calculate_height_by_ratio(360)) : image_resize('', 360, 360);
  $imageMedium = $sourceImage ? image_resize($sourceImage, 560, calculate_height_by_ratio(560)) : image_resize('', 560, 560);
  $imageLarge = $sourceImage ? image_resize($sourceImage, 800, calculate_height_by_ratio(800)) : image_resize('', 800, 800);
  $priceId = 'related-product-price-' . (int) $product['id'];
@endphp
<article class="related-product-card">
  <a class="related-product-card__media" href="{{ $product['url'] }}" aria-label="{{ $product['name'] }}">
    <img
      src="{{ $imageMedium }}"
      srcset="{{ $imageSmall }} 360w, {{ $imageMedium }} 560w, {{ $imageLarge }} 800w"
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
