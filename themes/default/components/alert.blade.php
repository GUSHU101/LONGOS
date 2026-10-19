<div {{ $attributes->class(['alert', 'alert-' . $type, 'alert-dismissible']) }} role="{{ $type === 'danger' ? 'alert' : 'status' }}" aria-live="polite">
  @if ($type == 'success')
    <i class="bi bi-check-circle-fill"></i>
  @elseif ($type == 'danger')
    <i class="bi bi-exclamation-circle-fill"></i>
  @endif
  {{ $msg }}
  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('common.cancel') }}"></button>
</div>
