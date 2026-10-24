@extends('admin::layouts.master')

@section('title', __('admin/common.settings.seo'))
@section('content-area-class', 'w-max-1200')
@section('page-title-back', admin_route('settings.index'))
@section('head-form-btns', true)

@section('content')
  @php
    $redirectText = old('seo_redirects', \Beike\Services\LegacyRedirectService::toTextarea($redirects));
  @endphp

  @if (session('success'))
    <x-admin-alert type="success" msg="{{ session('success') }}" class="mt-4"/>
  @endif
  @if (session('error'))
    <x-admin-alert type="danger" msg="{{ session('error') }}" class="mt-4"/>
  @endif

  <form action="{{ admin_route('settings.store') }}" class="needs-validation" novalidate method="POST" id="form-app">
    @csrf
    <input type="hidden" name="return_url" value="{{ url()->full() }}">

    <section class="card mt-4" aria-labelledby="seo-redirects-heading">
      <div class="card-header">
        <h5 class="card-title mb-1" id="seo-redirects-heading">{{ __('admin/setting.seo_redirects_title') }}</h5>
        <p class="text-secondary mb-0">{{ __('admin/setting.seo_redirects_help') }}</p>
      </div>
      <div class="card-body">
        <label class="form-label" for="seo-redirects">{{ __('admin/setting.seo_redirects_label') }}</label>
        <textarea class="form-control font-monospace" id="seo-redirects" name="seo_redirects" rows="16"
                  spellcheck="false" placeholder="/old-product => /products/new-product">{{ $redirectText }}</textarea>
        <div class="form-text">{{ __('admin/setting.seo_redirects_format') }}</div>

        <div class="alert alert-info mt-4 mb-0" role="note">
          <strong>{{ __('admin/setting.seo_foundation_title') }}</strong>
          <ul class="mb-0 mt-2">
            <li>{{ __('admin/setting.seo_canonical_status') }}</li>
            <li>{{ __('admin/setting.seo_structured_data_status') }}</li>
            <li>{{ __('admin/setting.seo_redirect_status') }}</li>
          </ul>
        </div>
      </div>
    </section>

    <x-admin::form.row title="">
      <button type="submit" class="btn btn-primary d-none mt-4">{{ __('common.submit') }}</button>
    </x-admin::form.row>
  </form>
@endsection
