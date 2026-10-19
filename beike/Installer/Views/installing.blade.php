@extends('installer::layouts.master')

@section('template_title')
  {{ trans('installer::installer_messages.installing.template_title') }}
@endsection

@section('style')
  <style>
    .installation-progress { max-width: 760px; margin: 4vh auto 0; }
    .installation-card { border: 1px solid #e4e9f2; border-radius: 18px; background: #fff; padding: 38px; box-shadow: 0 18px 46px rgba(22, 35, 58, .08); }
    .installation-icon { width: 54px; height: 54px; display: grid; place-items: center; border-radius: 16px; color: #fff; background: linear-gradient(135deg, #5146e5, #1f6df0); box-shadow: 0 12px 25px rgba(31, 109, 240, .24); }
    .installation-progress .progress { height: 10px; border-radius: 999px; background: #edf1f7; }
    .installation-progress .progress-bar { border-radius: inherit; background: linear-gradient(90deg, #5146e5, #1f6df0); transition: width .35s ease; }
    .installation-stage { color: #5f6b7c; min-height: 24px; }
    .installation-note { color: #7a8494; font-size: 14px; line-height: 1.7; }
    @media (max-width: 767.98px) {
      .installation-progress { margin-top: 0; }
      .installation-card { padding: 26px 22px; border-radius: 14px; }
    }
  </style>
@endsection

@section('content')
  <div class="installation-progress">
    <div class="installation-card" aria-live="polite">
      <div class="d-flex align-items-center gap-3 mb-4">
        <div class="installation-icon" aria-hidden="true">
          <span class="spinner-border spinner-border-sm"></span>
        </div>
        <div>
          <h3 class="mb-1">{{ trans('installer::installer_messages.installing.title') }}</h3>
          <div class="installation-stage">{{ $stageMessage }}</div>
        </div>
      </div>

      <div class="d-flex justify-content-between small text-muted mb-2">
        <span>{{ trans('installer::installer_messages.installing.progress') }}</span>
        <span>{{ $progress }}%</span>
      </div>
      <div class="progress mb-4" role="progressbar" aria-label="{{ trans('installer::installer_messages.installing.progress') }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress }}">
        <div class="progress-bar" style="width: {{ $progress }}%"></div>
      </div>

      <p class="installation-note mb-4">{{ trans('installer::installer_messages.installing.keep_open') }}</p>

      <form id="installation-step-form" method="post" action="{{ route('installer.database.run') }}">
        @csrf
        <button class="btn btn-primary" type="submit">
          {{ trans('installer::installer_messages.installing.continue') }}
        </button>
      </form>
    </div>
  </div>
@endsection

@section('scripts')
  @if (! session()->has('errors'))
    <script>
      window.setTimeout(function () {
        const form = document.getElementById('installation-step-form');
        if (!form || form.dataset.submitted === 'true') return;
        form.dataset.submitted = 'true';
        const button = form.querySelector('button');
        if (button) button.disabled = true;
        form.requestSubmit();
      }, 650);
    </script>
  @endif
@endsection
