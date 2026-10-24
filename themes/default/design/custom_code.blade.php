@php
  $height = min(1200, max(160, (int) ($content['height'] ?? 360)));
@endphp
<section class="module-item section-custom-code {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  <div class="module-info {{ $content['module_size'] ?? 'container-fluid' }}">
    @if (!empty($content['document']))
      <iframe class="custom-code-frame" title="{{ $content['title'] ?? 'Custom section' }}" sandbox="" loading="lazy" referrerpolicy="no-referrer" srcdoc="{{ $content['document'] }}" style="height: {{ $height }}px"></iframe>
    @elseif ($design)
      <div class="custom-code-empty">{{ __('shop/design.empty.custom_code') }}</div>
    @endif
  </div>
</section>
