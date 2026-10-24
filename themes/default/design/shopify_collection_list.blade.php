<section class="module-item section-shopify-collection-list {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" style="--shopify-collection-list-padding-top: {{ (int) ($content['padding_top'] ?? 36) }}px; --shopify-collection-list-padding-bottom: {{ (int) ($content['padding_bottom'] ?? 36) }}px;" @include('design.partials.section_style')>
  <div class="container">
    <div class="shopify-collection-list shopify-collection-list--desktop-{{ $content['columns_desktop'] }} shopify-collection-list--mobile-{{ $content['columns_mobile'] }} shopify-collection-list--ratio-{{ $content['image_ratio'] }} {{ !empty($content['swipe_on_mobile']) ? 'shopify-collection-list--swipe-mobile' : '' }}">
      @if(!empty($content['title']))<h2 class="shopify-collection-list__title shopify-collection-list__title--{{ $content['heading_size'] }}">{{ $content['title'] }}</h2>@endif
      <div class="shopify-collection-list__viewport" @if(!empty($content['swipe_on_mobile'])) tabindex="0" aria-label="Swipe through collections" @endif>
        <ul class="shopify-collection-list__grid">
          @forelse($content['categories'] as $category)
            <li class="shopify-collection-list__item">
              @if(!empty($category['url']))<a class="shopify-collection-list__card" href="{{ $category['url'] }}" aria-label="{{ $category['name'] ?: __('shop/design.collection') }}">
              @else<div class="shopify-collection-list__card">@endif
                <div class="shopify-collection-list__media">
                  @if(!empty($category['image']))<img src="{{ $category['image'] }}" alt="" loading="lazy" decoding="async">@else<div class="shopify-collection-list__placeholder" aria-hidden="true"></div>@endif
                </div>
                <h3>{{ $category['name'] ?: __('shop/design.collection') }}</h3>
              @if(!empty($category['url']))</a>@else</div>@endif
            </li>
          @empty
            @if($design)<li class="shopify-collection-list__empty">{{ __('shop/design.empty.shopify_collections') }}</li>@endif
          @endforelse
        </ul>
      </div>
      @if(!empty($content['show_view_all']) && !empty($content['view_all_url']))<div class="shopify-collection-list__action"><a href="{{ $content['view_all_url'] }}" class="btn btn-primary">{{ __('shop/design.view_all_collections') }}</a></div>@endif
    </div>
  </div>
</section>
