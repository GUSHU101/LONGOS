@extends('layout.master')

@section('body-class', 'page-policy')
@section('title', $policy['title'])
@section('description', \Illuminate\Support\Str::limit(trim(strip_tags($policy['content'])), 160))
@section('og_type', 'article')

@section('content')
  <div class="breadcrumb-wrap policy-breadcrumb">
    <div class="container-fluid">
      <nav aria-label="{{ __('policy.breadcrumb') }}">
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="{{ shop_route('home.index') }}">{{ __('shop/common.home') }}</a></li>
          <li class="breadcrumb-item active" aria-current="page">{{ $policy['title'] }}</li>
        </ol>
      </nav>
    </div>
  </div>

  <div class="container-fluid policy-page-shell">
    <article class="policy-document">
      <header class="policy-document__header">
        <p class="policy-document__eyebrow">{{ __('policy.store_policy') }}</p>
        <h1>{{ $policy['title'] }}</h1>
      </header>
      <div class="policy-document__content rich-text-editor-content">
        {!! $policy['content'] !!}
      </div>
    </article>
  </div>
@endsection
