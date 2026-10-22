@extends('layout.master')
@section('body-class', 'page-search')
@section('title', $keyword !== '' ? __('shop/products.search_for', ['keyword' => $keyword]) : __('shop/products.search_title'))

@section('content')
  <x-shop-breadcrumb type="static" value="products.search" :is-full="true" />

  <div class="container search-page-shell">
    <header class="search-page-header">
      <p class="search-page-eyebrow">{{ __('shop/products.search') }}</p>
      <h1>{{ $keyword !== '' ? __('shop/products.search_for', ['keyword' => $keyword]) : __('shop/products.search_title') }}</h1>
      <form class="search-page-form" method="get" action="{{ shop_route('products.search') }}" role="search">
        <label class="visually-hidden" for="search-page-keyword">{{ __('shop/products.search_placeholder') }}</label>
        <i class="bi bi-search" aria-hidden="true"></i>
        <input id="search-page-keyword" name="keyword" type="search" value="{{ $keyword }}" maxlength="120"
               autocomplete="off" enterkeyhint="search" placeholder="{{ __('shop/products.search_placeholder') }}">
        <button type="submit">{{ __('shop/products.search_submit') }}</button>
      </form>
      @if (count($items))
        <p class="search-result-count" role="status">{{ trans_choice('shop/products.results_count', $products->total(), ['count' => $products->total()]) }}</p>
      @endif
    </header>

    <div class="search-product-grid">
      @if (count($items))
        @foreach ($items as $product)
          <div class="search-product-item">@include('shared.product')</div>
        @endforeach
      @else
        <section class="search-empty-state" aria-labelledby="search-empty-title">
          <span class="search-empty-state__icon" aria-hidden="true"><i class="bi bi-search"></i></span>
          <h2 id="search-empty-title">{{ __('shop/products.no_results_title') }}</h2>
          <p>{{ __('shop/products.no_results_help') }}</p>
          <div class="search-empty-state__actions">
            <button type="button" data-search-refocus>{{ __('shop/products.try_another_search') }}</button>
            <a href="{{ shop_route('home.index') }}">{{ __('shop/products.continue_browsing') }}</a>
          </div>
        </section>
      @endif
    </div>

    @if (count($items))
      {{ $products->links('shared/pagination/bootstrap-4') }}
    @elseif (count($recommended))
      <section class="search-recommendations" aria-labelledby="search-recommendations-title">
        <h2 id="search-recommendations-title">{{ __('shop/products.recommended_products') }}</h2>
        <div class="search-product-grid">
          @foreach ($recommended as $product)
            <div class="search-product-item">@include('shared.product')</div>
          @endforeach
        </div>
      </section>
    @endif
  </div>

@endsection

@push('add-scripts')
  <script>
    document.querySelector('[data-search-refocus]')?.addEventListener('click', () => {
      const input = document.getElementById('search-page-keyword');
      input?.focus();
      input?.select();
    });
  </script>
@endpush
