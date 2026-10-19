<section class="module-item section-shopify-main-list-collections {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  <div class="container shopify-collection-directory shopify-collection-directory--desktop-{{ $content['columns_desktop'] }} shopify-collection-directory--mobile-{{ $content['columns_mobile'] }} shopify-collection-directory--ratio-{{ $content['image_ratio'] }}">
    <h2>{{ $content['title'] }}</h2>
    <ul class="shopify-collection-directory__grid" role="list">
      @forelse($content['categories'] as $category)
        <li><a href="{{ $category['url'] }}" aria-label="{{ $category['name'] ?: '商品分类' }}"><div class="shopify-collection-directory__media">@if($category['image'])<img src="{{ $category['image'] }}" alt="" loading="lazy" decoding="async">@else<div aria-hidden="true"></div>@endif</div><h3>{{ $category['name'] ?: '商品分类' }}</h3></a></li>
      @empty
        @if($design)<li class="shopify-collection-directory__empty">暂无已启用商品分类；添加分类后会在这里按所选排序真实展示。</li>@endif
      @endforelse
    </ul>
    @if(($content['pagination']['last'] ?? 1) > 1)
      <nav class="shopify-collection-directory__pagination" aria-label="商品分类分页"><span>第 {{ $content['pagination']['current'] }} / {{ $content['pagination']['last'] }} 页</span>@if($content['pagination']['previous'])<a href="{{ $content['pagination']['previous'] }}">上一页</a>@endif @if($content['pagination']['next'])<a href="{{ $content['pagination']['next'] }}">下一页</a>@endif</nav>
    @endif
  </div>
</section>
