@php
  $images = $content['images'] ?? [];
@endphp
<section class="module-item {{ $design ? 'module-item-design' : ''}}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  <div class="module-image-402 banner-magnify-hover module-info  ">
    <div class="{{ $content['module_size'] ?? 'container' }}">
      @if ($content['title'] ?? false)
      <div class="module-title">{{ $content['title'] }}</div>
      @endif
      @if ($content['sub_title'] ?? false)
      <div class="image-402-sub-title mt-n3">{{ $content['sub_title'] }}</div>
      @endif
      <div class="module-image-info d-grid grid-4">
        @foreach (array_slice($images, 0, 4) as $index => $image)
          <div class="image-402-{{ $index + 1 }}">
            <a class="image-wrap" href="{{ $image['link']['link'] }}">
              <img src="{{ $image['image'] }}" class="img-fluid" alt="{{ $image['image_alt'] }}">
              @if ($image['title'])
                <div class="img-name"><span>{{ $image['title'] }}</span></div>
              @endif
            </a>
          </div>
        @endforeach
      </div>
    </div>
  </div>
</section>
