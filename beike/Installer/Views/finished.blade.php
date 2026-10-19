@extends('installer::layouts.master')

@section('template_title')
  {{ trans('installer::installer_messages.final.template_title') }}
@endsection

@section('title')
  <i class="fa fa-flag-checkered fa-fw" aria-hidden="true"></i>
  {{ __('installer::installer_messages.final.template_title') }}
@endsection

@section('content')
  <div class="install-2">
		<h3 class="mb-5">{{ __('installer::installer_messages.final.template_title') }}</h3>

		<div class="d-flex justify-content-center flex-column align-items-center">

			<div class="welcome-img mb-5" style="max-width: 360px;"><img src="{{ asset('/' . config('longos.identity.logo', 'image/longos-logo.svg')) }}" alt="{{ platform_brand_name() }}" class="img-fluid"></div>

			<h5 class="text-center mb-4">{{ __('installer::installer_messages.final.finished') }}</h5>

			@if (! empty($saas_ready))
				<div class="alert alert-success text-center mb-3" role="alert">
					<strong>{{ __('installer::installer_messages.final.saas_ready') }}</strong>
				</div>
				<div class="alert alert-info text-center mb-4" role="alert">
					<div class="font-monospace">{{ trans('installer::installer_messages.final.wildcard_dns', ['domain' => $base_domain, 'target' => $dns_target]) }}</div>
					<div class="mt-2">{{ trans('installer::installer_messages.final.wildcard_server', ['domain' => $base_domain]) }}</div>
				</div>
			@endif

			@if (empty($saas_ready) && isset($is_nginx) && $is_nginx)
				<div class="alert alert-warning text-center mb-4" role="alert">
					{!! __('installer::installer_messages.final.nginx_alert') !!}
				</div>
			@endif

			<div class="mb-5 d-flex align-items-center">
				<div>{{ trans('installer::installer_messages.environment.admin_email') }}: {{ $admin_email }}</div>
			</div>

			@if (! empty($database_binding['host']) && ! empty($database_binding['database']))
				<div class="mb-5 text-center text-muted">
					{{ trans('installer::installer_messages.final.database_binding', $database_binding) }}
				</div>
			@endif

			<div class="d-flex justify-content-center">
				<a href="{{ url('/') }}" class="btn btn-lg btn-outline-primary"><i class="bi bi-window-dock me-2"></i> {{ trans('installer::installer_messages.final.to_front') }}</a>
				<a href="{{ url('/'.$admin_path.'/login') }}" class="btn btn-lg btn-primary ms-4"><i class="bi bi-window-sidebar me-2"></i> {{ trans('installer::installer_messages.final.to_admin') }}</a>
			</div>
    </div>
  </div>
@endsection
