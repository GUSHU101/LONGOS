@extends('layout.master')
@section('body-class', 'page-categories')
@section('title', $category->description->meta_title ?: site_brand_meta_title() . ' - ' . $category->description->name)
@section('keywords', $category->description->meta_keywords ?: system_setting('base.meta_keywords'))
@section('description', $category->description->meta_description ?: system_setting('base.meta_description'))

@section('content')
  @php
    $categoryMarketingModules = \Beike\Services\DesignService::getPlacementModules('category');
    $categoryName = (string) ($category->description->name ?? '');
    $categoryDescription = trim(strip_tags((string) ($category->description->content ?? '')));
  @endphp
  @if (count($categoryMarketingModules) || ($design ?? false))
      <div id="category-modules-box" class="category-marketing-modules" aria-label="Category marketing sections">
      @foreach ($categoryMarketingModules as $marketingModule)
        {!! $marketingModule['html'] !!}
      @endforeach
    </div>
  @endif

  <div class="breadcrumb-filter category-breadcrumb-bar">
    <x-shop-breadcrumb type="category" :value="$category" :is-full="true" />
  </div>

  <div class="container-fluid category-page-shell">
    <header class="category-intro {{ $category->image ? '' : 'category-intro--text-only' }}" data-lgt-root data-lgt-kind="category" data-lgt-entity="{{ (int) $category->id }}">
      <div class="category-intro__content">
        <p class="category-intro__eyebrow">{{ __('shop/categories.collection') }}</p>
        <h1 data-lgt-field="name">{{ $categoryName }}</h1>
        @if ($categoryDescription)
          <p class="category-desc">{{ $categoryDescription }}</p>
        @endif
        <div class="category-intro__meta">
          <span>{{ trans_choice('shop/categories.product_count', $products->total(), ['count' => $products->total()]) }}</span>
        </div>
        @if ($children)
          <nav class="children-wrap category-intro__children" aria-label="{{ __('shop/categories.subcollections') }}">
            @foreach ($children as $item)
              <a href="{{ $item['url'] }}">{{ $item['name'] }}</a>
            @endforeach
          </nav>
        @endif
      </div>
      @if ($category->image)
        <div class="category-avatar">
          <img src="{{ image_origin($category->image) }}" alt="{{ $categoryName }}" class="img-fluid" decoding="async">
        </div>
      @endif
    </header>

    <div class="category-layout">
      <main class="right-column category-results" id="category-results">
        @hook('category.products.before')
        @if (count($products_format))
          @include('shared.filter_bar_block')
          <div class="category-product-grid {{ request('style_list') == 'list' ? 'product-list-wrap is-list-view' : 'is-grid-view'}}">
            @foreach ($products_format as $product)
              <div class="category-product-item product-grid">
                @include('shared.product')
              </div>
            @endforeach
          </div>
          @else
          <x-shop-no-data />
        @endif

        {{ $products->links('shared/pagination/bootstrap-4') }}

        @hook('category.products.after')
      </main>
    </div>
  </div>

@endsection

@push('add-scripts')
<script>
  $('.product-tool .form-select, input[name="style_list"]').change(function() {
    filterProductData();
  });

  function filterProductData() {
    let url = bk.removeURLParameters(window.location.href, 'attr', 'price', 'sort', 'order');
    let order = $('.order-select').val();
    let perpage = $('.perpage-select').val();
    let styleList = $('input[name="style_list"]:checked').val();

    layer.load(2, {shade: [0.3,'#fff'] })

    if (order) {
      let orderKeys = order.split('|');
      url = bk.updateQueryStringParameter(url, 'sort', orderKeys[0]);
      url = bk.updateQueryStringParameter(url, 'order', orderKeys[1]);
    }

    if (perpage) {
      url = bk.updateQueryStringParameter(url, 'per_page', perpage);
    }

    if (styleList) {
     url = bk.updateQueryStringParameter(url, 'style_list', styleList);
    }

    location = url;
  }
</script>
@endpush
