<section class="module-item section-shopify-main-list-collections {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  <div class="container shopify-collection-directory shopify-collection-directory--desktop-{{ $content['columns_desktop'] }} shopify-collection-directory--mobile-{{ $content['columns_mobile'] }} shopify-collection-directory--ratio-{{ $content['image_ratio'] }}">
    <h2>{{ $content['title'] }}</h2>
    <ul class="shopify-collection-directory__grid" role="list">
      @forelse($content['categories'] as $category)
        <li><a href="{{ $category['url'] }}" aria-label="{{ $category['name'] ?: __('shop/design.collection') }}"><div class="shopify-collection-directory__media">@if($category['image'])<img src="{{ $category['image'] }}" alt="" loading="lazy" decoding="async">@else<div aria-hidden="true"></div>@endif</div><h3>{{ $category['name'] ?: __('shop/design.collection') }}</h3></a></li>
      @empty
        @if($design)<li class="shopify-collection-directory__empty">{{ __('shop/design.empty.shopify_collection_directory') }}</li>@endif
      @endforelse
    </ul>
    @if(($content['pagination']['last'] ?? 1) > 1)
      <nav class="shopify-collection-directory__pagination" aria-label="{{ __('shop/design.collection_pagination') }}"><span>{{ __('shop/design.page_status', ['current' => $content['pagination']['current'], 'last' => $content['pagination']['last']]) }}</span>@if($content['pagination']['previous'])<a href="{{ $content['pagination']['previous'] }}">{{ __('shop/design.previous_page') }}</a>@endif @if($content['pagination']['next'])<a href="{{ $content['pagination']['next'] }}">{{ __('shop/design.next_page') }}</a>@endif</nav>
    @endif
  </div>
</section>
