@extends('admin::layouts.master')

@section('title', __('admin/plugin.customization_title'))

@section('page-title-back', admin_route('plugins.index'))

@section('content-area-class', 'w-max-1000')

@section('content')
  <div class="card overflow-hidden">
    <div class="card-body p-4 p-lg-5">
      <div class="row align-items-center g-5">
        <div class="col-lg-7">
          <span class="badge text-bg-primary mb-3">LONGOS</span>
          <h2 class="fw-bold mb-3">{{ __('admin/plugin.customization_title') }}</h2>
          <p class="text-secondary fs-6 mb-4">{{ __('admin/plugin.customization_intro') }}</p>
          <div class="row g-3 mb-4">
            <div class="col-sm-6"><div class="border rounded-3 p-3 h-100"><i class="bi bi-puzzle fs-4 text-primary"></i><div class="fw-semibold mt-2">{{ __('admin/plugin.customization_feature') }}</div><small class="text-secondary">{{ __('admin/plugin.customization_feature_text') }}</small></div></div>
            <div class="col-sm-6"><div class="border rounded-3 p-3 h-100"><i class="bi bi-arrow-repeat fs-4 text-primary"></i><div class="fw-semibold mt-2">{{ __('admin/plugin.customization_integration') }}</div><small class="text-secondary">{{ __('admin/plugin.customization_integration_text') }}</small></div></div>
            <div class="col-sm-6"><div class="border rounded-3 p-3 h-100"><i class="bi bi-palette fs-4 text-primary"></i><div class="fw-semibold mt-2">{{ __('admin/plugin.customization_design') }}</div><small class="text-secondary">{{ __('admin/plugin.customization_design_text') }}</small></div></div>
            <div class="col-sm-6"><div class="border rounded-3 p-3 h-100"><i class="bi bi-shield-check fs-4 text-primary"></i><div class="fw-semibold mt-2">{{ __('admin/plugin.customization_maintenance') }}</div><small class="text-secondary">{{ __('admin/plugin.customization_maintenance_text') }}</small></div></div>
          </div>
          <div class="alert alert-light border mb-0"><i class="bi bi-wechat text-success me-2"></i>{{ __('admin/plugin.customization_contact_hint') }}</div>
        </div>
        <div class="col-lg-5 text-center">
          <div class="border rounded-4 p-3 bg-white shadow-sm d-inline-block">
            <img src="{{ asset('image/longos-plugin-customization-wechat.jpg') }}" class="img-fluid rounded-3" style="max-width: 330px;" alt="{{ __('admin/plugin.customization_contact') }}">
          </div>
          <h5 class="mt-3 mb-1">{{ __('admin/plugin.customization_contact') }}</h5>
          <p class="text-secondary mb-0">LONG-L</p>
        </div>
      </div>
    </div>
  </div>
@endsection
