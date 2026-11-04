@extends('admin::layouts.master')

@section('title', __('LongosGlobalTranslate::translate.title'))

@section('content')
<main class="container-fluid py-4" aria-labelledby="lgt-recovery-title">
  <div class="mx-auto" style="max-width: 920px">
    <section class="card border-warning shadow-sm">
      <div class="card-body p-4 p-lg-5">
        <span class="badge text-bg-warning mb-3">{{ __('LongosGlobalTranslate::translate.recovery_badge') }}</span>
        <h1 id="lgt-recovery-title" class="h3 mb-3">{{ __('LongosGlobalTranslate::translate.recovery_title') }}</h1>
        <p class="text-secondary mb-4">{{ __('LongosGlobalTranslate::translate.recovery_description') }}</p>

        @if($schema['runtime_recovery'] ?? false)
          <div class="alert alert-secondary mb-4" role="status">{{ __('LongosGlobalTranslate::translate.recovery_runtime') }}</div>
        @elseif($schema['inspection_failed'])
          <div class="alert alert-secondary mb-4" role="status">{{ __('LongosGlobalTranslate::translate.recovery_connection') }}</div>
        @else
          <div class="alert alert-warning mb-4" role="status">
            <strong>{{ __('LongosGlobalTranslate::translate.recovery_missing') }}</strong>
            <ul class="mb-0 mt-2 ps-3">
              @foreach($schema['missing_tables'] as $table)<li><code>{{ $table }}</code></li>@endforeach
              @foreach($schema['missing_columns'] as $column)<li><code>{{ $column }}</code></li>@endforeach
            </ul>
          </div>
        @endif

        <ol class="mb-4 ps-3 text-secondary">
          <li>{{ __('LongosGlobalTranslate::translate.recovery_step_deploy') }}</li>
          <li>{{ __('LongosGlobalTranslate::translate.recovery_step_wait') }}</li>
          <li>{{ __('LongosGlobalTranslate::translate.recovery_step_reload') }}</li>
        </ol>
        <p class="small text-secondary mb-0">{{ __('LongosGlobalTranslate::translate.recovery_safety') }}</p>
      </div>
    </section>
  </div>
</main>
@endsection
