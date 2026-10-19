<section class="module-item {{ $design ? 'module-item-design' : ''}}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  <div class="banner-magnify-hover module-info  ">
    <div class="{{ $content['module_size'] ?? 'container-fluid' }}">
      <div class="row g-3 g-lg-4">
        @foreach ($content['images'] as $item)
        <div class="col-12 col-sm-6 col-md-3">
          @if (!empty($item['link']['link']))
            <a class="image-wrap d-flex justify-content-center" href="{{ $item['link']['link'] }}"><img src="{{ $item['image'] }}" loading="lazy" decoding="async" alt="{{ $item['image_alt'] ?? '' }}" class="img-fluid seo-img"></a>
          @else
            <div class="image-wrap d-flex justify-content-center"><img src="{{ $item['image'] }}" loading="lazy" decoding="async" alt="{{ $item['image_alt'] ?? '' }}" class="img-fluid seo-img"></div>
          @endif
        </div>
        @endforeach
      </div>
    </div>
  </div>
</section>
