@extends('layout.master')

@section('body-class', 'page-policy')
@section('title', $policy['title'])
@section('description', \Illuminate\Support\Str::limit(trim(strip_tags($policy['content'])), 160))
@section('og_type', 'article')

@section('content')
  @php
    $identity = \Beike\Services\StorePolicyTemplateService::profile();
    $phoneHref = preg_replace('/[^0-9+]/', '', (string) ($identity['contact_phone'] ?? ''));
  @endphp
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
      <aside class="policy-identity" aria-labelledby="policy-identity-heading">
        <h2 id="policy-identity-heading">{{ __('policy.merchant_identity') }}</h2>
        <dl>
          <div><dt>{{ __('policy.store_name') }}</dt><dd>{{ $identity['business_name'] }}</dd></div>
          <div><dt>{{ __('policy.legal_entity') }}</dt><dd>{{ $identity['legal_name'] }}</dd></div>
          @if (!empty($identity['registration_number']))
            <div><dt>{{ __('policy.registration_number') }}</dt><dd>{{ $identity['registration_number'] }}</dd></div>
          @endif
          <div><dt>{{ __('policy.email') }}</dt><dd><a href="mailto:{{ $identity['contact_email'] }}">{{ $identity['contact_email'] }}</a></dd></div>
          @if (!empty($identity['contact_phone']))
            <div><dt>{{ __('policy.phone') }}</dt><dd><a href="tel:{{ $phoneHref }}">{{ $identity['contact_phone'] }}</a></dd></div>
          @endif
          <div><dt>{{ __('policy.address') }}</dt><dd>{{ $identity['business_address'] }}</dd></div>
        </dl>
      </aside>
    </article>
  </div>
@endsection
