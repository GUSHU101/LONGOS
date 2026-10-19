@extends('layout.master')
@section('body-class', 'page-categories')
@section('title', $category->description->meta_title ?: site_brand_meta_title() . ' - ' . $category->description->name)
@section('keywords', $category->description->meta_keywords ?: system_setting('base.meta_keywords'))
@section('description', $category->description->meta_description ?: system_setting('base.meta_description'))

@push('header')
  <script src="{{ asset('vendor/scrolltofixed/jquery-scrolltofixed-min.js') }}"></script>
@endpush

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
    <button type="button" class="category-filter-toggle" aria-controls="category-filter-panel" aria-expanded="false">
      <i class="bi bi-sliders2" aria-hidden="true"></i>
      <span>{{ __('common.filter') }}</span>
    </button>
  </div>

  <div class="container-fluid category-page-shell">
    <header class="category-intro {{ $category->image ? '' : 'category-intro--text-only' }}">
      <div class="category-intro__content">
        <p class="category-intro__eyebrow">{{ __('shop/categories.collection') }}</p>
        <h1>{{ $categoryName }}</h1>
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
      <div class="left-column category-filter-overlay" aria-hidden="true">
        <aside class="category-filter-panel" id="category-filter-panel" aria-label="{{ __('shop/categories.filters') }}">
          <div class="category-filter-mobile-header">
            <h2>{{ __('shop/categories.filters') }}</h2>
            <button type="button" class="category-filter-close" aria-label="{{ __('shop/categories.close_filters') }}">
              <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
          </div>
          @include('shared.filter_sidebar_block')
        </aside>
      </div>

      <main class="right-column category-results" id="category-results">
        @hook('category.products.before')
        <div class="filter-value-wrap d-none" aria-label="{{ __('shop/categories.active_filters') }}">
          <div class="filter-value-list">
            @foreach ($filter_data['attr'] as $index => $attr)
              @foreach ($attr['values'] as $value_index => $value)
                @if ($value['selected'])
                <button type="button" class="filter-value-chip" data-attr="{{ $index }}" data-attrval="{{ $value_index }}" aria-label="{{ __('shop/categories.remove_filter') }} {{ $attr['name'] }}: {{ $value['name'] }}">
                  <span>{{ $attr['name'] }}: {{ $value['name'] }}</span>
                  <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
                @endif
              @endforeach
            @endforeach
            <button type="button" class="filter-value-clear delete-all">{{ __('shop/categories.clear_filters') }}</button>
          </div>
        </div>

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
  let filterAttr = @json($filter_data['attr'] ?? []);

  $('.filter-value-wrap .filter-value-chip').click(function(event) {
    let [attr, val] = [$(this).data('attr'),$(this).data('attrval')];
    filterAttr[attr].values[val].selected = false;
    filterProductData();
  });

  $('.delete-all').click(function() {
    deleteFilterAll();
  });

  if ($('.filter-value-wrap .filter-value-chip').length) {
    $('.filter-value-wrap').removeClass('d-none')
  }

  $('.attr-value-check').change(function(event) {
    let [attr, val] = [$(this).data('attr'),$(this).data('attrval')];
    filterAttr[attr].values[val].selected = $(this).is(":checked");
    filterProductData();
  });

  $('.product-tool .form-select, input[name="style_list"]').change(function(event) {
    filterProductData();
  });

  const $categoryFilterOverlay = $('.category-filter-overlay');
  const $categoryFilterPanel = $('#category-filter-panel');
  const $categoryFilterToggle = $('.category-filter-toggle');

  function openCategoryFilters() {
    if (window.innerWidth >= 992) return;
    $categoryFilterOverlay.fadeIn(0).attr('aria-hidden', 'false');
    $categoryFilterPanel.addClass('active');
    $categoryFilterToggle.attr('aria-expanded', 'true');
    $('body').addClass('category-filter-open');
    window.setTimeout(() => $('.category-filter-close').trigger('focus'), 40);
  }

  function closeCategoryFilters(returnFocus = true) {
    if (window.innerWidth >= 992) return;
    $categoryFilterPanel.removeClass('active');
    $categoryFilterToggle.attr('aria-expanded', 'false');
    $('body').removeClass('category-filter-open');
    window.setTimeout(() => {
      $categoryFilterOverlay.fadeOut(50).attr('aria-hidden', 'true');
      if (returnFocus) $categoryFilterToggle.trigger('focus');
    }, 220);
  }

  $categoryFilterToggle.on('click', openCategoryFilters);
  $('.category-filter-close').on('click', () => closeCategoryFilters());
  $categoryFilterOverlay.on('click', function(e) {
    if ($(e.target).closest('#category-filter-panel').length === 0) closeCategoryFilters();
  });
  $(document).on('keydown', function(e) {
    if (e.key === 'Escape' && $categoryFilterPanel.hasClass('active')) closeCategoryFilters();
  });
  $(window).on('resize', function() {
    if (window.innerWidth >= 992) {
      $categoryFilterOverlay.removeAttr('style').attr('aria-hidden', 'false');
      $categoryFilterPanel.removeClass('active');
      $categoryFilterToggle.attr('aria-expanded', 'false');
      $('body').removeClass('category-filter-open');
    } else if (!$categoryFilterPanel.hasClass('active')) {
      $categoryFilterOverlay.attr('aria-hidden', 'true');
    }
  }).triggerHandler('resize');

  function filterProductData() {
    let url = bk.removeURLParameters(window.location.href, 'attr', 'price', 'sort', 'order');
    let [psMin, psMax, pMin, pMax] = [$('.price-select-min').val(), $('.price-select-max').val(), $('.price-min').val(), $('.price-max').val()];
    let order = $('.order-select').val();
    let perpage = $('.perpage-select').val();
    let styleList = $('input[name="style_list"]:checked').val();

    layer.load(2, {shade: [0.3,'#fff'] })

    if (filterAttrChecked(filterAttr)) {
      url = bk.updateQueryStringParameter(url, 'attr', filterAttrChecked(filterAttr));
    }

    if ((psMin != pMin) || (psMax != pMax)) {
      url = bk.updateQueryStringParameter(url, 'price', `${psMin}-${psMax}`);
    }

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

  function filterAttrChecked(data) {
    let filterAtKey = [];
    data.forEach((item) => {
      let checkedAtValues = [];
      item.values.forEach((val) => val.selected ? checkedAtValues.push(val.id) : '')
      if (checkedAtValues.length) {
        filterAtKey.push(`${item.id}:${checkedAtValues.join(',')}`)
      }
    })

    return filterAtKey.join('|')
  }

  function deleteFilterAll() {
    let url = bk.removeURLParameters(window.location.href, 'attr', 'price');
    location = url;
  }
</script>
@endpush
