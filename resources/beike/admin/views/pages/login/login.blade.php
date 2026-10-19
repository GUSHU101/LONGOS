<!DOCTYPE html>
<html lang="en" data-bs-theme="{{ system_setting('base.bd_theme', 'light') }}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="{{ mix('/build/beike/admin/css/bootstrap.css') }}" rel="stylesheet">
  <link href="{{ mix('build/beike/admin/css/app.css') }}" rel="stylesheet">
  @include('admin::shared.branding-styles')
  @hook('admin.login.header.code')
  <title>{{ __('admin/login.plugins_index', ['store_name' => platform_brand_name()]) }}</title>

  <script>
    @if (system_setting('base.bd_theme') == '')
      if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.documentElement.setAttribute('data-bs-theme', 'dark');
      } else {
        document.documentElement.setAttribute('data-bs-theme', 'light');
      }
    @endif
  </script>
</head>
<body class="page-login">
  <div class="d-flex align-items-center vh-100 pt-2 pt-sm-5 pb-4 pb-sm-5">
    <div class="container">
      <div class="card">
        <div class="w-480">
          <div class="card-header mt-3 mb-4">
            <img src="{{ image_origin(admin_brand_logo()) }}" class="img-fluid mb-4" style="max-width: 220px; max-height: 54px;" alt="{{ platform_brand_name() }}">
            <h4 class="fw-bold">{{ __('admin/login.plugins_index', ['store_name' => platform_brand_name()]) }}</h4>
            {{-- <div class="text-muted fw-normal">{{ __('admin/login.plugins_index') }}</div> --}}
          </div>

          <div class="card-body">
            <form action="{{ admin_route('login.store') }}" method="post">
              @csrf

              <div class="form-floating mb-4">
                <input type="text" name="email" class="form-control" id="email-input" value="{{ old('email', $admin_email ?? '') }}" placeholder="{{ __('common.email') }}">
                <label for="email-input">{{ __('common.email') }}</label>
                @error('email')
                  <x-admin::form.error :message="$message" />
                @enderror
              </div>

              <div class="form-floating mb-5">
                <input type="password" name="password" class="form-control" id="password-input" value="{{ old('password', $admin_password ?? '') }}" placeholder="{{ __('shop/login.password') }}">
                <label for="password-input">{{ __('shop/login.password') }}</label>
                @error('password')
                  <x-admin::form.error :message="$message" />
                @enderror
              </div>

              @if (session('error'))
                <div class="alert alert-danger">
                  {{ session('error') }}
                </div>
              @endif

              <div class="d-grid mb-4"><button type="submit" class="btn btn-lg btn-primary">{{ __('admin/login.log_in') }}</button></div>
              <a href="{{ admin_route('forgotten.index') }}" class="text-muted"><i class="bi bi-question-circle"></i> {{ __('admin/login.forgot_password') }}</a>
            </form>
          </div>
        </div>
      </div>

      <div class="text-center text-secondary mt-5">
        @if(!check_license())
        <p class="mb-0">{{ platform_brand_name() }} &copy; {{ date('Y') }} · <a href="{{ config('longos.legal.upstream_url') }}" class="ms-1" target="_blank" rel="noopener">Built on {{ config('longos.legal.upstream_name') }}</a></p>
        @else
        @php
          $footerContent = \Beike\Repositories\FooterRepo::handleFooterData();
          $copyright = $footerContent['bottom']['copyright'][locale()] ?? '';
        @endphp
        <div class="copyright-content d-flex justify-content-center" style="text-align: center;">
          <span class="me-2">v{{ config('beike.version') }}({{ config('beike.build') }})</span>
          <div>{!! $copyright !!}</div>
        </div>
        @endif
      </div>
    </div>
  </div>
</body>
</html>
