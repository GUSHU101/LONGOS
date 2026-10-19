<section class="module-item section-rich-text {{ $design ? 'module-item-design' : ''}}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  <div class="module-info">
    <div class="{{ $content['module_size'] ?? 'container-fluid' }}">
      @if ($content['data'])
      <div class="rich-text-content">{!! $content['data'] !!}</div>
      @elseif (!$content['data'] and $design)
      <div class="text-center p-3 fs-4 text-secondary">{{ __('admin/builder.modules_enter_content') }}</div>
      @endif
    </div>
  </div>
</section>
