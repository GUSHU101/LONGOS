@extends('admin::layouts.master')

@section('title', __('admin/common.settings.basic'))

@section('content-area-class', 'w-max-1200')

@section('page-title-back', admin_route('settings.index'))

@section('head-form-btns', true)

@section('content')
  @if (session('success'))
  <x-admin-alert type="success" msg="{{ session('success') }}" class="mt-4"/>
  @endif
  @if (session('error'))
  <div class="alert alert-danger">
    {{ session('error') }}
  </div>
  @endif
  <div class="card h-min-600">
    <div class="card-body">
      @hook('admin.setting.basic.content.before')
      <form action="{{ admin_route('settings.store') }}" class="needs-validation" novalidate method="POST" id="form-app">
        @csrf
          <input type="hidden" name="return_url" value="{{ url()->full() }}"/>

          @hook('admin.setting.general.before')
          @hook('admin.setting.store.before')
          <x-admin-form-input name="store_name" title="{{ __('admin/setting.store_name') }}" value="{{ old('store_name', site_brand_name()) }}" required>
            <div class="help-text font-size-12 lh-base">{{ __('admin/setting.store_name_info') }}</div>
          </x-admin-form-input>
          <x-admin-form-input name="meta_title" title="{{ __('admin/setting.meta_title') }}" value="{{ old('meta_title', system_setting('base.meta_title', '')) }}" required />
          <x-admin-form-textarea name="meta_description" title="{{ __('admin/setting.meta_description') }}" value="{{ old('meta_description', system_setting('base.meta_description', '')) }}" />
          <x-admin-form-textarea name="meta_keywords" title="{{ __('admin/setting.meta_keywords') }}" value="{{ old('meta_keywords', system_setting('base.meta_keywords', '')) }}" />
          <x-admin-form-input name="telephone" title="{{ __('admin/setting.telephone') }}" value="{{ old('telephone', system_setting('base.telephone', '')) }}" />
          <x-admin-form-input name="email" title="{{ __('admin/setting.email') }}" value="{{ old('email', system_setting('base.email', '')) }}" >
          <div class="help-text font-size-12 lh-base">{{ __('admin/setting.email_info') }}</div>
          </x-admin-form-input>
          <x-admin-form-switch name="maintenance_mode" title="{{ __('admin/maintenance.heading_title') }}" value="{{ old('maintenance_mode', system_setting('base.maintenance_mode', '0')) }}" />
          <x-admin-form-switch name="app_debug" title="{{ __('admin/setting.app_debug') }}" value="{{ old('app_debug', system_setting('base.app_debug', '0')) }}" />
          <input type="hidden" name="admin_locale" value="zh_cn">
          <x-admin::form.row title="{{ __('admin/setting.admin_primary_language') }}">
            <div class="form-control-plaintext">简体中文</div>
            <div class="help-text font-size-12 lh-base">后台固定使用简体中文，避免后台内容因语言包切换产生不一致。</div>
          </x-admin::form.row>
          <x-admin-form-select title="{{ __('admin/setting.app_timezone') }}" name="app_timezone" :value="old('app_timezone', $timezone)" :options="$timezones" key="value" label="label">
            <div class="help-text font-size-12 lh-base">
              {{ __('admin/setting.app_timezone_help') }}
              {{ __('admin/setting.app_timezone_current', ['time' => $timezone_now, 'timezone' => $timezone]) }}
            </div>
          </x-admin-form-select>
          <x-admin-form-input name="cdn_url" title="{{ __('admin/setting.cdn_url') }}" value="{{ old('cdn_url', system_setting('base.cdn_url', '')) }}"></x-admin-form-input>
          <x-admin-form-textarea name="head_code" title="{{ __('admin/setting.head_code') }}" :value="old('head_code', system_setting('base.head_code', ''))">
            <div class="help-text font-size-12 lh-base">{{ __('admin/setting.head_code_info') }}</div>
          </x-admin-form-textarea>
          @include('admin::pages.setting.partials.brand')
          @hook('admin.setting.general.after')

        <x-admin::form.row title="">
          <button type="submit" class="btn btn-primary d-none mt-4">{{ __('common.submit') }}</button>
        </x-admin::form.row>
      </form>
      @hook('admin.setting.basic.content.after')
    </div>
  </div>
@endsection
