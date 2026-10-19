@php
  $image = $content['images'][0] ?? null;
@endphp
<section class="module-item {{ $design ? 'module-item-design' : ''}}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  <div class="module-image-banner module-info   d-flex justify-content-center">
    <div class="{{ $content['module_size'] ?? 'container-fluid' }} d-flex justify-content-center">
      @if (is_array($image))
        @if (!empty($image['link']['link']))
          <a href="{{ $image['link']['link'] }}"><img src="{{ $image['image'] }}" fetchpriority="high" decoding="async" alt="{{ $image['image_alt'] ?? '' }}" class="img-fluid seo-img"></a>
        @else
          <div><img src="{{ $image['image'] }}" fetchpriority="high" decoding="async" alt="{{ $image['image_alt'] ?? '' }}" class="img-fluid seo-img"></div>
        @endif
      @endif
    </div>
  </div>
</section>
