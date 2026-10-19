<section class="module-item {{ $design ? 'module-item-design' : ''}}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  <div class="banner-magnify-hover module-info  ">
    <div class="{{ $content['module_size'] ?? 'container-fluid' }}">
      <div class="row g-3 g-lg-4">
        @foreach ($content['images'] as $item)
        <div class="col-12 col-sm-4">
          @if (!empty($item['link']['link']))
            <a class="image-wrap rounded-4 overflow-hidden" href="{{ $item['link']['link'] }}"><img src="{{ $item['image'] }}" loading="lazy" decoding="async" alt="{{ $item['image_alt'] ?? '' }}" class="img-fluid seo-img"></a>
          @else
            <div class="image-wrap rounded-4 overflow-hidden"><img src="{{ $item['image'] }}" loading="lazy" decoding="async" alt="{{ $item['image_alt'] ?? '' }}" class="img-fluid seo-img"></div>
          @endif
        </div>
        @endforeach
      </div>
    </div>
  </div>
</section>
