<section class="module-item section-shopify-main-collection-banner {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  <div class="container">
    <div class="shopify-main-collection-banner {{ !empty($content['show_collection_image']) && !empty($content['collection_content']['image']) ? 'shopify-main-collection-banner--with-image' : '' }}">
      <div class="shopify-main-collection-banner__copy">
        @if(!empty($content['collection_content']['name']))<h1 class="shopify-main-collection-banner__title">{{ $content['collection_content']['name'] }}</h1>@endif
        @if(!empty($content['show_collection_description']) && !empty($content['collection_content']['html']))<div class="shopify-main-collection-banner__description">{!! $content['collection_content']['html'] !!}</div>@endif
        @if($design && empty($content['collection_content']['name']))<div class="shopify-main-collection-banner__empty">{{ __('shop/design.empty.shopify_collection_banner') }}</div>@endif
      </div>
      @if(!empty($content['show_collection_image']) && !empty($content['collection_content']['image']))<div class="shopify-main-collection-banner__media"><img src="{{ $content['collection_content']['image'] }}" alt="{{ $content['collection_content']['name'] }}" loading="eager" decoding="async"></div>@endif
    </div>
  </div>
</section>
