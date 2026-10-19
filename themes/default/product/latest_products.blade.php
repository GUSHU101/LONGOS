@extends('layout.master')

@section('title', __('shop/products.latest_products'))

@section('content')
  <div class="breadcrumb-wrap">
    <div class="container-fluid">
      <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="{{ shop_route('home.index') }}">@lang('shop/common.home')</a></li>
          <li class="breadcrumb-item active" aria-current="page">@lang('shop/products.latest_products')</li>
        </ol>
      </nav>
    </div>
  </div>
  <div class="container-fluid pb-5">
    <header class="py-4 py-lg-5 text-center"><h1 class="h2 mb-0">@lang('shop/products.latest_products')</h1></header>
    <div class="row g-3 g-lg-4">
      @forelse ($items as $product)
        <div class="col-6 col-md-4 col-lg-3 col-xl-20">@include('shared.product')</div>
      @empty
        <div class="col-12"><p class="text-center text-muted py-5">@lang('shop/products.no_products')</p></div>
      @endforelse
    </div>
    <div class="mt-4">{{ $products->links('shared/pagination/seo_url') }}</div>
  </div>
@endsection
