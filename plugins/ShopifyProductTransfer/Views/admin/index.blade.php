@extends('admin::layouts.master')

@section('title', trans('ShopifyProductTransfer::transfer.title'))

@section('content')
  <div class="card">
    <div class="card-header"><h5 class="mb-0">{{ trans('ShopifyProductTransfer::transfer.title') }}</h5></div>
    <div class="card-body">
      @if(!empty($schemaError))
        <div class="alert alert-danger" role="alert">{{ $schemaError }}</div>
      @endif
      @if(session('shopify_import_errors'))
        <div class="alert alert-warning">
          <div class="fw-bold mb-1">{{ trans('ShopifyProductTransfer::transfer.import_warnings') }}</div>
          <ul class="mb-0 ps-3">
            @foreach(array_slice(session('shopify_import_errors'), 0, 20) as $error)<li>{{ $error }}</li>@endforeach
            @if(count(session('shopify_import_errors')) > 20)
              <li>{{ trans('ShopifyProductTransfer::transfer.more_errors', ['count' => count(session('shopify_import_errors')) - 20]) }}</li>
            @endif
          </ul>
        </div>
      @endif
      <p class="text-muted">{{ trans('ShopifyProductTransfer::transfer.intro') }}</p>
      @if(!empty($recoverableImportId))
        <div class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2" role="status" data-import-recovery>
          <span data-import-recovery-message>{{ trans('ShopifyProductTransfer::transfer.import_recovering') }}</span>
          <form method="post" action="{{ admin_route('shopify_product_transfer.process_pending', ['importId' => $recoverableImportId]) }}" class="m-0" data-import-recovery-form>
            @csrf
            <button class="btn btn-sm btn-primary" type="submit">{{ trans('ShopifyProductTransfer::transfer.process_now') }}</button>
          </form>
        </div>
      @endif
      @if($importRuns->isNotEmpty())
        @php
          $hasActiveImports = $importRuns->contains(fn ($run) => in_array($run->status, ['pending', 'running'], true));
        @endphp
        <section class="border rounded-3 p-3 mb-4" aria-labelledby="shopify-import-runs-title" @if($hasActiveImports) aria-live="polite" @endif>
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
            <h6 class="mb-0" id="shopify-import-runs-title">{{ trans('ShopifyProductTransfer::transfer.recent_imports') }}</h6>
            @if($hasActiveImports)
              <span class="small text-muted">{{ trans(!empty($recoverableImportId) ? 'ShopifyProductTransfer::transfer.local_queue_hint' : 'ShopifyProductTransfer::transfer.queue_hint') }}</span>
            @endif
          </div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead><tr><th>{{ trans('ShopifyProductTransfer::transfer.file_name') }}</th><th>{{ trans('ShopifyProductTransfer::transfer.status') }}</th><th style="min-width: 180px">{{ trans('ShopifyProductTransfer::transfer.progress') }}</th></tr></thead>
              <tbody>
                @foreach($importRuns as $run)
                  @php
                    $result = is_array($run->result) ? $run->result : [];
                    $resultDetails = array_merge(
                      is_array($result['errors'] ?? null) ? $result['errors'] : [],
                      is_array($result['warning_details'] ?? null) ? $result['warning_details'] : [],
                    );
                    $percent = $run->total > 0 ? min(100, (int) floor(($run->processed / $run->total) * 100)) : ($run->status === 'completed' ? 100 : 0);
                    $completedWithIssues = $run->status === 'completed' && ((int) ($result['failed'] ?? 0) > 0 || (int) ($result['warnings'] ?? 0) > 0);
                    $statusClass = $run->status === 'failed' ? 'bg-danger' : ($completedWithIssues ? 'bg-warning text-dark' : ($run->status === 'completed' ? 'bg-success' : 'bg-primary'));
                    $statusLabel = $completedWithIssues ? trans('ShopifyProductTransfer::transfer.status_completed_with_issues') : trans('ShopifyProductTransfer::transfer.status_' . $run->status);
                  @endphp
                  <tr>
                    <td>
                      <div class="fw-semibold text-break">{{ $run->original_name }}</div>
                      <div class="small text-muted">{{ $run->created_at?->format('Y-m-d H:i:s') }}</div>
                    </td>
                    <td><span class="badge {{ $statusClass }}">{{ $statusLabel }}</span></td>
                    <td>
                      <div class="progress" style="height: 7px" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}"><div class="progress-bar" style="width: {{ $percent }}%"></div></div>
                      <div class="small mt-1">
                        @if($run->status === 'completed')
                          {{ trans('ShopifyProductTransfer::transfer.import_result', [
                            'created' => max(0, (int) ($result['created'] ?? 0)),
                            'updated' => max(0, (int) ($result['updated'] ?? 0)),
                            'skipped' => max(0, (int) ($result['skipped'] ?? 0)),
                            'failed' => max(0, (int) ($result['failed'] ?? 0)),
                            'warnings' => max(0, (int) ($result['warnings'] ?? 0)),
                            'adjusted_skus' => max(0, (int) ($result['adjusted_skus'] ?? 0)),
                          ]) }}
                        @elseif($run->status === 'failed')
                          <span class="text-danger">{{ $run->error ?: trans('ShopifyProductTransfer::transfer.import_failed') }}</span>
                        @else
                          {{ $run->processed }} / {{ $run->total ?: '—' }}
                        @endif
                      </div>
                      @if($resultDetails !== [])
                        <details class="small mt-1"><summary>{{ trans('ShopifyProductTransfer::transfer.import_warnings') }}</summary><ul class="mb-0 ps-3">@foreach(array_slice($resultDetails, 0, 5) as $error)<li>{{ $error }}</li>@endforeach</ul></details>
                      @endif
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </section>
      @endif
      <form method="post" action="{{ admin_route('shopify_product_transfer.import') }}" enctype="multipart/form-data" class="row g-3" data-shopify-import-form @if(!empty($schemaError)) aria-disabled="true" @endif>
        @csrf
        <div class="col-md-8">
          <label class="form-label" for="shopify-csv-file">{{ trans('ShopifyProductTransfer::transfer.csv_file') }}</label>
          <input class="form-control @error('file') is-invalid @enderror" id="shopify-csv-file" name="file" type="file" accept=".csv,text/csv,text/plain" required aria-describedby="shopify-csv-hint shopify-csv-client-error" data-maximum-bytes="{{ $maximumUploadKilobytes * 1024 }}" @disabled(!empty($schemaError))>
          @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
          <div class="invalid-feedback" id="shopify-csv-client-error" data-file-size-error></div>
          <div class="form-text" id="shopify-csv-hint">{{ trans('ShopifyProductTransfer::transfer.csv_hint', ['size' => $maximumUploadMegabytes]) }}</div>
        </div>
        <div class="col-md-8 form-check ms-2">
          <input class="form-check-input" type="checkbox" name="replace_existing" value="1" id="replace-existing" @checked(old('replace_existing'))>
          <label class="form-check-label" for="replace-existing">{{ trans('ShopifyProductTransfer::transfer.replace_existing') }}</label>
          <div class="form-text">{{ trans('ShopifyProductTransfer::transfer.replace_hint') }}</div>
        </div>
        <div class="col-12"><button class="btn btn-primary" type="submit" data-import-submit @disabled(!empty($schemaError))><i class="bi bi-upload" aria-hidden="true"></i> <span aria-live="polite">{{ trans('ShopifyProductTransfer::transfer.import') }}</span></button></div>
      </form>
      <hr class="my-4">
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary" href="{{ admin_route('shopify_product_transfer.template') }}"><i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i> {{ trans('ShopifyProductTransfer::transfer.download_template') }}</a>
        <a class="btn btn-outline-secondary" href="{{ admin_route('shopify_product_transfer.export') }}"><i class="bi bi-download" aria-hidden="true"></i> {{ trans('ShopifyProductTransfer::transfer.export_all') }}</a>
      </div>
    </div>
  </div>
@endsection

@push('footer')
<script>
  (function () {
    var form = document.querySelector('[data-shopify-import-form]');
    if (!form) return;
    var fileInput = form.querySelector('input[type="file"]');
    var sizeError = form.querySelector('[data-file-size-error]');
    var button = form.querySelector('[data-import-submit]');
    var formUnavailable = form.getAttribute('aria-disabled') === 'true';
    var defaultLabel = @json(trans('ShopifyProductTransfer::transfer.import'));

    function resetSubmitState() {
      if (!button) return;
      button.disabled = formUnavailable;
      button.removeAttribute('aria-busy');
      button.querySelector('span').textContent = defaultLabel;
    }

    fileInput?.addEventListener('change', function () {
      this.classList.remove('is-invalid');
      if (sizeError) {
        sizeError.textContent = '';
        sizeError.style.display = '';
      }
    });
    window.addEventListener('pageshow', resetSubmitState);

    var recoveryForm = document.querySelector('[data-import-recovery-form]');
    if (recoveryForm) {
      var recoveryMessage = document.querySelector('[data-import-recovery-message]');
      var recoveryButton = recoveryForm.querySelector('button[type="submit"]');
      var recoveryStarted = false;

      function processPendingImport(event) {
        if (event) event.preventDefault();
        if (recoveryStarted) return;
        recoveryStarted = true;
        if (recoveryButton) {
          recoveryButton.disabled = true;
          recoveryButton.setAttribute('aria-busy', 'true');
        }
        fetch(recoveryForm.action, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': @json(csrf_token()),
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: new FormData(recoveryForm)
        }).then(function (response) {
          if (!response.ok) throw new Error('Import recovery request failed.');
          return response.json();
        }).then(function (payload) {
          if (payload && payload.accepted && recoveryMessage) {
            recoveryMessage.textContent = @json(trans('ShopifyProductTransfer::transfer.import_recovery_started'));
          }
          window.setTimeout(function () { window.location.reload(); }, payload && payload.accepted ? 1500 : 100);
        }).catch(function () {
          recoveryStarted = false;
          if (recoveryButton) {
            recoveryButton.disabled = false;
            recoveryButton.removeAttribute('aria-busy');
          }
          if (recoveryMessage) recoveryMessage.textContent = @json(trans('ShopifyProductTransfer::transfer.import_recovery_failed'));
        });
      }

      recoveryForm.addEventListener('submit', processPendingImport);
      window.setTimeout(processPendingImport, 250);
    }

    @if(!empty($hasActiveImports) && empty($recoverableImportId))
    window.setTimeout(function refreshImportProgress() {
      if (document.visibilityState === 'visible') window.location.reload();
      else window.setTimeout(refreshImportProgress, 5000);
    }, 5000);
    @endif

    form.addEventListener('submit', function (event) {
    if (formUnavailable) {
      event.preventDefault();
      return;
    }
    var file = fileInput && fileInput.files ? fileInput.files[0] : null;
    var maximumBytes = fileInput ? Number(fileInput.dataset.maximumBytes || 0) : 0;
    if (file && maximumBytes > 0 && file.size > maximumBytes) {
      event.preventDefault();
      fileInput.classList.add('is-invalid');
      if (sizeError) {
        sizeError.textContent = @json($fileTooLargeMessage);
        sizeError.style.display = 'block';
      }
      fileInput.focus();
      return;
    }
    if (!button) return;
    if (button.disabled) {
      event.preventDefault();
      return;
    }
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.querySelector('span').textContent = @json(trans('ShopifyProductTransfer::transfer.importing'));
    });
  })();
</script>
@endpush
