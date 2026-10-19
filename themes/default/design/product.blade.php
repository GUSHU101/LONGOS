@addStyle(asset('vendor/swiper/swiper-bundle.min.css'))
@addScript(asset('vendor/swiper/swiper-bundle.min.js'))

@if($design || !empty($content['products']))
<section class="module-item {{ $design ? 'module-item-design' : ''}}" id="module-{{ $module_id }}" @if(!empty($content['title'])) aria-labelledby="module-heading-{{ $module_id }}" @endif @include('design.partials.section_style')>
  <div class="module-info module-product swiper-style-plus">
    <div class="{{ $content['module_size'] ?? 'container-fluid' }} position-relative">
      @if ($content['title'])
        <h2 class="section-heading" id="module-heading-{{ $module_id }}">{{ $content['title'] }}</h2>
      @endif
        @if ($content['products'])
          <div class="builder-section-grid builder-section-grid--desktop-{{ $content['columns_desktop'] ?? 4 }} builder-section-grid--tablet-{{ $content['columns_tablet'] ?? 3 }} builder-section-grid--mobile-{{ $content['columns_mobile'] ?? 2 }}">
            @foreach ($content['products'] as $product)
            <div class="product-grid">
              @include('shared.product')
            </div>
            @endforeach
          </div>
        @elseif (!$content['products'] and $design)
          <div class="builder-section-grid builder-section-grid--desktop-{{ $content['columns_desktop'] ?? 4 }} builder-section-grid--tablet-{{ $content['columns_tablet'] ?? 3 }} builder-section-grid--mobile-{{ $content['columns_mobile'] ?? 2 }}">
            @for ($s = 0; $s < 4; $s++)
            <div class="product-grid">
              <div class="product-wrap">
                <div class="image"><div class="product-placeholder-image" aria-hidden="true"><img src="{{ asset('image/placeholder.png') }}" class="img-fluid" alt=""></div></div>
                <div class="product-name">请在右侧选择商品</div>
                <div class="product-price">
                  <span class="price-new">66.66</span>
                  <span class="price-lod">99.99</span>
                </div>
              </div>
            </div>
          @endfor
        </div>
        @endif
    </div>
  </div>
</section>
@endif
