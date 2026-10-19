@extends('installer::layouts.master')

@section('template_title')
  {{ trans('installer::installer_messages.environment.template_title') }}
@endsection

@section('title')
  <i class="fa fa-magic fa-fw" aria-hidden="true"></i>
  {!! trans('installer::installer_messages.environment.title') !!}
@endsection

@section('content')
  @php
    $entry_key = 'installer::installer_messages.environment.';
  @endphp
  <div class="install-4">
    <h3 class="mb-5">{{ __('installer::installer_messages.environment.title') }}</h3>

    @if (! is_secure())
      <div class="alert alert-warning mb-4">{{ trans($entry_key . 'https_recommended') }}</div>
    @endif

    <div class="alert alert-info mb-4">{{ trans($entry_key . 'web_installer_tls_notice') }}</div>

    <form method="post" id="environment-form" action="{{ route('installer.environment.save') }}" novalidate class="needs-validation">
      <input type="hidden" name="_token" value="{{ csrf_token() }}">

      <div class="row gx-4 gy-3 mb-5 database-link-wrap">
        <div class="col-12">
          <div class="d-flex align-items-center title-status">
            <h5 class="mb-0">{{ __('installer::installer_messages.environment.database_link') }}</h5>
            <div class="d-none ms-3 database-loading">
              <div class="spinner-border spinner-border-sm text-muted"></div>
              <span class="text-muted">{{ __('installer::installer_messages.environment.ajax_database_parameters') }}</span>
            </div>
            <div class="text-danger d-flex align-items-center ms-3 d-none">
              <i class="bi bi-x-circle-fill fs-5 lh-1 me-1"></i><span></span>
            </div>
            <div class="text-success d-flex align-items-center ms-3 d-none"><i
                class="bi bi-check-circle-fill me-1 fs-4 lh-1"></i>{{ __('installer::installer_messages.environment.ajax_database_success') }}</div>
          </div>
          <hr class="mb-0">
        </div>

        <div class="col-sm-6">
          <label class="form-label">
            {{ trans($entry_key . 'db_connection_label') }}
          </label>
          <select name="database_connection" class="form-select" aria-label="Default select example" id="sql_type">
            <option value="mysql" selected>
              {{ trans($entry_key . 'db_connection_label_mysql') }}</option>
            <option value="pgsql">
              {{ trans($entry_key . 'db_connection_label_pgsql') }}</option>
          </select>
          <span class="invalid-feedback"
                role="alert"></span>
        </div>

        <div class="col-sm-6">
          <label class="form-label">
            {{ trans($entry_key . 'db_host_label') }}
          </label>
          <input class="form-control {{ $errors->has('database_hostname') ? 'is-invalid' : '' }}" name="database_hostname"
            value="{{ old('database_hostname', '127.0.0.1') }}" required
            placeholder="{{ trans($entry_key . 'db_host_placeholder') }}">
          <span class="invalid-feedback"
            role="alert">{{ $errors->has('database_hostname') ? $errors->first('database_hostname') : __('common.error_required', ['name' => trans($entry_key . 'db_host_label')]) }}</span>
        </div>

        <div class="col-sm-6">
          <label class="form-label">
            {{ trans($entry_key . 'db_port_label') }}
          </label>
          <input class="form-control {{ $errors->has('database_port') ? 'is-invalid' : '' }}" name="database_port"
            value="{{ old('database_port', '3306') }}" required
            placeholder="{{ trans($entry_key . 'db_host_placeholder') }}" id="port">
          <span class="invalid-feedback"
            role="alert">{{ $errors->has('database_port') ? $errors->first('database_port') : __('common.error_required', ['name' => trans($entry_key . 'db_port_label')]) }}</span>
        </div>

        <div class="col-sm-6">
          <label class="form-label">
            {{ trans($entry_key . 'db_name_label') }}
          </label>
          <input class="form-control {{ $errors->has('database_name') ? 'is-invalid' : '' }}" name="database_name"
            value="{{ old('database_name', '') }}" required
            placeholder="{{ trans($entry_key . 'db_name_placeholder') }}">
          <span class="invalid-feedback"
            role="alert">{{ $errors->has('database_name') ? $errors->first('database_name') : __('common.error_required', ['name' => trans($entry_key . 'db_name_label')]) }}</span>
        </div>

        <div class="col-sm-6">
          <label class="form-label">
            {{ trans($entry_key . 'db_username_label') }}
          </label>
          <input class="form-control {{ $errors->has('database_username') ? 'is-invalid' : '' }}"
            name="database_username" value="{{ old('database_username', 'root') }}" required
            placeholder="{{ trans($entry_key . 'db_username_placeholder') }}">
          <span class="invalid-feedback"
            role="alert">{{ $errors->has('database_username') ? $errors->first('database_username') : __('common.error_required', ['name' => trans($entry_key . 'db_username_label')]) }}</span>
        </div>

        <div class="col-sm-6">
          <label class="form-label">
            {{ trans($entry_key . 'db_password_label') }}
          </label>
          <input class="form-control {{ $errors->has('database_password') ? 'is-invalid' : '' }}"
            name="database_password" type="password" autocomplete="off"
            placeholder="{{ trans($entry_key . 'db_password_placeholder') }}">
          <span class="invalid-feedback"
            role="alert">{{ $errors->has('database_password') ? $errors->first('database_password') : __('common.error_required', ['name' => trans($entry_key . 'db_password_label')]) }}</span>
        </div>

      </div>

      <div class="row gx-4 gy-3 mb-5 saas-config-wrap">
        <div class="col-12">
          <h5 class="mb-1">{{ trans($entry_key . 'saas_info') }}</h5>
          <div class="text-muted small">{{ trans($entry_key . 'saas_info_help') }}</div>
          <hr class="mb-0">
        </div>

        <div class="col-12">
          <label class="form-label">{{ trans($entry_key . 'saas_database_mode') }}</label>
          <select class="form-select {{ $errors->has('saas_database_mode') ? 'is-invalid' : '' }}"
            id="saas_database_mode" name="saas_database_mode" required>
            <option value="prefix" @selected(old('saas_database_mode', 'prefix') === 'prefix')>{{ trans($entry_key . 'saas_database_mode_prefix') }}</option>
            <option value="database" @selected(old('saas_database_mode') === 'database')>{{ trans($entry_key . 'saas_database_mode_database') }}</option>
          </select>
          <div class="form-text" id="saas-database-mode-help"></div>
          <span class="invalid-feedback" role="alert">{{ $errors->first('saas_database_mode') }}</span>
        </div>

        <div class="col-sm-6">
          <label class="form-label">{{ trans($entry_key . 'saas_base_domain') }}</label>
          <input class="form-control {{ $errors->has('saas_base_domain') ? 'is-invalid' : '' }}"
            name="saas_base_domain" value="{{ old('saas_base_domain', $defaultBaseDomain) }}" required
            placeholder="example.com">
          <div class="form-text">{{ trans($entry_key . 'saas_base_domain_help') }}</div>
          <span class="invalid-feedback" role="alert">{{ $errors->first('saas_base_domain') }}</span>
        </div>

        <div class="col-sm-6 tenant-admin-wrap">
          <label class="form-label">{{ trans($entry_key . 'saas_database_prefix') }}</label>
          <input class="form-control {{ $errors->has('saas_database_prefix') ? 'is-invalid' : '' }}"
            name="saas_database_prefix" value="{{ old('saas_database_prefix', 'longos_') }}" required
            maxlength="32" placeholder="longos_">
          <span class="invalid-feedback" role="alert">{{ $errors->first('saas_database_prefix') }}</span>
        </div>

        <div class="col-sm-6">
          <label class="form-label">{{ trans($entry_key . 'saas_dns_target') }}</label>
          <input class="form-control {{ $errors->has('saas_dns_target') ? 'is-invalid' : '' }}"
            name="saas_dns_target" value="{{ old('saas_dns_target', $defaultDnsTarget) }}"
            placeholder="203.0.113.10">
          <div class="form-text">{{ trans($entry_key . 'saas_dns_target_help', ['domain' => old('saas_base_domain', $defaultBaseDomain) ?: 'example.com']) }}</div>
          <span class="invalid-feedback" role="alert">{{ $errors->first('saas_dns_target') }}</span>
        </div>

        <div class="col-sm-6 tenant-admin-wrap"></div>

        <div class="col-sm-6 tenant-admin-wrap">
          <label class="form-label">{{ trans($entry_key . 'saas_tenant_admin_username') }}</label>
          <input class="form-control {{ $errors->has('saas_tenant_admin_username') ? 'is-invalid' : '' }}"
            name="saas_tenant_admin_username" value="{{ old('saas_tenant_admin_username', 'root') }}"
            autocomplete="username" placeholder="root">
          <span class="invalid-feedback" role="alert">{{ $errors->first('saas_tenant_admin_username') }}</span>
        </div>

        <div class="col-sm-6 tenant-admin-wrap">
          <label class="form-label">{{ trans($entry_key . 'saas_tenant_admin_password') }}</label>
          <input class="form-control {{ $errors->has('saas_tenant_admin_password') ? 'is-invalid' : '' }}"
            name="saas_tenant_admin_password" type="password" autocomplete="off"
            placeholder="{{ trans($entry_key . 'saas_tenant_admin_password') }}">
          <span class="invalid-feedback" role="alert">{{ $errors->first('saas_tenant_admin_password') }}</span>
        </div>

        <div class="col-12 tenant-admin-wrap">
          <div class="alert alert-info mb-0 py-2">{{ trans($entry_key . 'saas_tenant_admin_help') }}</div>
        </div>

        <div class="col-12 prefix-mode-wrap">
          <div class="alert alert-success mb-0 py-2">{{ trans($entry_key . 'saas_prefix_mode_help') }}</div>
        </div>
      </div>

      <div class="row gx-4 gy-3 admin-data-wrap d-none">
        <div class="col-12">
          <h5>{{ __('installer::installer_messages.environment.admin_info') }}</h5>
          <hr class="mb-0">
        </div>
        <div class="col-sm-6">
          <label class="form-label">
            {{ trans($entry_key . 'admin_email') }}
          </label>
          <input class="form-control {{ $errors->has('admin_email') ? 'is-invalid' : '' }}" name="admin_email"
            value="{{ old('admin_email') }}" type="email" autocomplete="email" required placeholder="{{ trans($entry_key . 'admin_email') }}">
          <span class="invalid-feedback"
            role="alert">{{ $errors->has('admin_email') ? $errors->first('admin_email') : __('installer::installer_messages.environment.error_email') }}</span>
        </div>

        <div class="col-sm-6">
          <label class="form-label">
            {{ trans($entry_key . 'admin_password') }}
          </label>
          <input class="form-control {{ $errors->has('admin_password') ? 'is-invalid' : '' }}" name="admin_password"
            type="password" autocomplete="new-password" minlength="8" required
            placeholder="{{ trans($entry_key . 'admin_password') }}">
          <span class="invalid-feedback"
            role="alert">{{ $errors->has('admin_password') ? $errors->first('admin_password') : __('common.error_required', ['name' => trans($entry_key . 'admin_password')]) }}</span>
        </div>

        <div class="col-sm-6">
          <label class="form-label">
            {{ trans($entry_key . 'admin_password_confirmation') }}
          </label>
          <input class="form-control {{ $errors->has('admin_password_confirmation') ? 'is-invalid' : '' }}"
            name="admin_password_confirmation" type="password" autocomplete="new-password" minlength="8" required
            placeholder="{{ trans($entry_key . 'admin_password_confirmation') }}">
          <span class="invalid-feedback"
            role="alert">{{ $errors->has('admin_password_confirmation') ? $errors->first('admin_password_confirmation') : __('common.error_required', ['name' => trans($entry_key . 'admin_password_confirmation')]) }}</span>
        </div>

        <div class="col-sm-12 my-5">
          <div class="d-flex justify-content-end">
            <button class="btn btn-primary d-flex align-items-center" id="submit-button" type="submit">
              <span class="spinner-border spinner-border-sm d-none me-2" role="status" aria-hidden="true"></span>
              {{ trans('installer::installer_messages.environment.install') }}
              <i class="bi bi-arrow-right-short fs-2 lh-1 ms-2"></i>
            </button>
          </div>
        </div>
      </div>
    </form>
  </div>
@endsection

@section('scripts')
  <script type="text/javascript">
    $(function() {
      $('.database-link-wrap select, .saas-config-wrap select').on('change', function() {
          debounce(getDatabaseStatus, 500)
      })

      $('#saas_database_mode').on('change', toggleDatabaseMode)
      toggleDatabaseMode()


      $('.database-link-wrap input, .saas-config-wrap input').on('keyup', function() {
        if (isDatabaseInputVal()) {
          debounce(getDatabaseStatus, 500)
        }
      })

      if ($('input[name="admin_email"]').hasClass('is-invalid')) {
        $('.admin-data-wrap').removeClass('d-none')
      }

      if ($('.content > .alert-danger').length) {
        getDatabaseStatus()
      }

     $("#sql_type").change(function(){
       if ($(this).val() === 'pgsql'){
          $("#port").val(5432);
       } else {
         $("#port").val(3306);
       }
     });




      // $('#submit-button').click(function() {
      //   setTimeout(() => {
      //     $(this).prop('disabled', true).addClass('text-white').find('span').removeClass('d-none')
      //   }, 0);
      // })
    })

    function toggleDatabaseMode() {
      const isolated = $('#saas_database_mode').val() === 'database';
      $('.tenant-admin-wrap').toggleClass('d-none', !isolated);
      $('.prefix-mode-wrap').toggleClass('d-none', isolated);
      $('input[name="saas_tenant_admin_username"]').prop('required', isolated);
      $('#saas-database-mode-help').text(isolated
        ? @json(trans($entry_key . 'saas_database_mode_database_help'))
        : @json(trans($entry_key . 'saas_database_mode_prefix_help'))
      );
    }

    // Example starter JavaScript for disabling form submissions if there are invalid fields
    $(function() {
      var forms = document.querySelectorAll(".needs-validation");

      // Loop over them and prevent submission
      Array.prototype.slice.call(forms).forEach(function(form) {
        form.addEventListener("submit", function(event) {
          if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
          } else {
            $("#submit-button").prop('disabled', true).addClass('text-white').find('span').removeClass('d-none')
          }

          form.classList.add("was-validated");
        }, false);
      });
    });

    function isDatabaseInputVal() {
      let isDate = true;
      $('.database-link-wrap input, .saas-config-wrap input[required]').each((index, el) => {
        if ($(el).val() == '' && $(el).prop('name') != 'database_password') {
          isDate = false;
        }
      })

      return isDate
    }

    let _debounceTimeout = null;

    function debounce(fn, delay = 500) {
      clearTimeout(_debounceTimeout);
      _debounceTimeout = setTimeout(() => {
        fn();
      }, delay);
    }

    function getDatabaseStatus() {
      const self = this;
      $('.database-link-wrap input, .saas-config-wrap input, select').removeClass('is-invalid')
      $('#sql_type').removeClass('is-invalid')

      $.ajax({
        url: 'environment/validate_db',
        type: 'POST',
        data: $('form :input[name!="admin_email"][name!="admin_password"][name!="admin_password_confirmation"]').serialize(),
        beforeSend: function () {
          $('.database-loading').removeClass('d-none')
          $('.title-status .text-success, .title-status .text-danger').addClass('d-none')
        },
        complete: function() {
          $('.database-loading').addClass('d-none');
        },
        success: function(json) {
          $('.database-link-wrap input, .saas-config-wrap input, select').addClass('is-valid')
          $('.title-status .text-success').removeClass('d-none')
          $('.admin-data-wrap').removeClass('d-none')
        },
        error: function(json) {
          json = json.responseJSON;
          var data = Object.keys(json.data);

          data.forEach((e)=> {
            const input = $('[name="' + e + '"]');
            input.addClass('is-invalid').siblings('.invalid-feedback').text(json.data[e])
            if (e === 'database_connection') {
              $('#sql_type').addClass('is-invalid').next('.invalid-feedback').text(json.data[e])
            }
          })

          if (json.data.database_version) {
            $('.title-status .text-danger').removeClass('d-none').find('span').text(json.data.database_version);
          }

          if (json.data.database_other) {
            $('.title-status .text-danger').removeClass('d-none').find('span').text(json.data.database_other);
          }

          $('.admin-data-wrap').addClass('d-none')
        }
      });
    }
  </script>
@endsection
