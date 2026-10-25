@extends('admin::layouts.master')

@section('title', __('admin/plugin.plugins_show'))

@section('content-area-class', 'w-max-1200')

@section('page-title-back', admin_route('plugins.index', http_build_query(request()->query())))

@section('head-form-btns', true)

@section('content')
  <div class="card h-min-600">
    <div class="card-body">
      @if (session('success'))
        <x-admin-alert type="success" msg="{{ session('success') }}" class="mt-4"/>
      @endif

      <div class="mb-4">
        <h4 class="mb-2">{{ $plugin->getLocaleName() }}</h4>
        <p class="mb-0 text-secondary">{{ $plugin->getLocaleDescription() }}</p>
      </div>

      @if (! empty($pluginReadmeHtml))
        @push('header')
          <link rel="stylesheet" href="{{ asset('vendor/github-markdown/github-markdown.min.css') }}">
        @endpush
        <ul class="mb-4 plugin-tab-nav" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#paypal-setting-pane" type="button" role="tab" aria-controls="paypal-setting-pane" aria-selected="true">
              <i class="bi bi-gear" aria-hidden="true"></i>
              <span class="ms-1 lh-text-base">{{ __('admin/common.edit') }}</span>
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#paypal-readme-pane" type="button" role="tab" aria-controls="paypal-readme-pane" aria-selected="false">
              <i class="bi bi-book" aria-hidden="true"></i>
              <span class="ms-1 lh-text-base">{{ __('admin/plugin.plugin_readme') }}</span>
            </button>
          </li>
        </ul>
        <div class="tab-content">
          <div class="tab-pane fade show active" id="paypal-setting-pane" role="tabpanel" tabindex="0">
      @endif

      @include('Paypal::admin.config_form')

      @if (! empty($pluginReadmeHtml))
          </div>
          <div class="tab-pane fade markdown-body" id="paypal-readme-pane" role="tabpanel" tabindex="0">
            {!! $pluginReadmeHtml !!}
          </div>
        </div>
      @endif
    </div>
  </div>
@endsection
