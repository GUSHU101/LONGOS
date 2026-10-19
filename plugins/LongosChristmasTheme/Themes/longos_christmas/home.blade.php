@extends('layout.master')
@section('body-class', 'page-home christmas-home')

@section('content')
@php
  $christmasHeroCodes = ['img_text_slideshow', 'img_text_slideshow_2', 'slideshow', 'shopify-slideshow', 'shopify-image-banner'];
  $hasBuilderHero = collect($modules)->contains(static fn ($module) => in_array($module['code'] ?? '', $christmasHeroCodes, true));
  $assurancesRendered = false;
@endphp
<div class="christmas-home-content">
  @if ($hasBuilderHero)
    <h1 class="visually-hidden">{{ site_brand_name() }}</h1>
  @endif
  @unless ($hasBuilderHero)
    <section class="christmas-hero" aria-labelledby="christmas-hero-title">
      <div class="christmas-shell christmas-hero__grid">
        <div class="christmas-hero__content">
          <span class="christmas-eyebrow">{{ trans('LongosChristmasTheme::theme.season') }}</span>
          <h1 id="christmas-hero-title">{{ trans('LongosChristmasTheme::theme.hero_title') }}</h1>
          <p>{{ trans('LongosChristmasTheme::theme.hero_copy') }}</p>
          <div class="christmas-hero__actions">
            <a class="christmas-button christmas-button--gold" href="{{ shop_route('latest_products') }}">{{ trans('LongosChristmasTheme::theme.shop_collection') }}</a>
            <a class="christmas-text-link" href="#home-modules-box">{{ trans('LongosChristmasTheme::theme.browse_navigation') }}<i class="bi bi-arrow-down" aria-hidden="true"></i></a>
          </div>
        </div>
        <div class="christmas-hero__scene" aria-hidden="true">
          <span class="christmas-hero__moon"></span>
          <span class="christmas-hero__tree christmas-hero__tree--back"></span>
          <span class="christmas-hero__tree christmas-hero__tree--front"></span>
          <span class="christmas-hero__gift christmas-hero__gift--small"></span>
          <span class="christmas-hero__gift christmas-hero__gift--large"></span>
        </div>
      </div>
    </section>
    @include('shared.assurances')
    @php $assurancesRendered = true; @endphp
  @endunless

  <div class="modules-box christmas-modules" id="home-modules-box">
    @hook('home.modules.before')
    @foreach($modules as $module)
      {!! $module['html'] ?? '' !!}
      @if (!$assurancesRendered && in_array($module['code'] ?? '', $christmasHeroCodes, true))
        @include('shared.assurances')
        @php $assurancesRendered = true; @endphp
      @endif
    @endforeach
    @if (($design ?? false) && empty($modules))
      <div class="builder-empty-home" data-builder-empty-state>
        <strong>首页还没有模块</strong>
        <span>从左侧组件库添加模块；保存草稿后可在此预览，发布后顾客才会看到。</span>
      </div>
    @endif
    @hook('home.modules.after')
  </div>
</div>
@endsection

@if ($design ?? false)
  @push('add-scripts')
  <script>
    const christmasHiddenModuleIds = @json(collect($modules)->filter(fn ($module) => ($module['visible'] ?? true) === false)->pluck('module_id')->values());
    document.addEventListener('click', function(event) { event.preventDefault(); });
    document.addEventListener('DOMContentLoaded', function() {
      christmasHiddenModuleIds.forEach(function(id) { document.getElementById('module-' + id)?.classList.add('module-item-hidden'); });
    });
  </script>
  @endpush
@endif
