@php
  $height = min(1200, max(160, (int) ($content['height'] ?? 360)));
@endphp
<section class="module-item section-custom-code {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  <div class="module-info {{ $content['module_size'] ?? 'container-fluid' }}">
    @if (!empty($content['document']))
      <iframe class="custom-code-frame" title="{{ $content['title'] ?? 'Custom section' }}" sandbox="" loading="lazy" referrerpolicy="no-referrer" srcdoc="{{ $content['document'] }}" style="height: {{ $height }}px"></iframe>
    @elseif ($design)
      <div class="custom-code-empty">请添加 HTML 与 CSS，在此处预览隔离的自定义区块。</div>
    @endif
  </div>
</section>
