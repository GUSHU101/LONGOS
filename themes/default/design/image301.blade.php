<section class="module-item {{ $design ? 'module-item-design' : ''}}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  <div class="module-image-banner banner-magnify-hover module-info  ">
    <div class="{{ $content['module_size'] ?? 'container' }}">
      <div class="design-image-301">
        @foreach ($content['images'] as $item)
        @if (!empty($item['link']['link']))
          <a href="{{ $item['link']['link'] }}"><img src="{{ $item['image'] }}" alt="{{ $item['image_alt'] ?? '' }}" class="img-fluid seo-img"></a>
        @else
          <div><img src="{{ $item['image'] }}" alt="{{ $item['image_alt'] ?? '' }}" class="img-fluid seo-img"></div>
        @endif
        @endforeach
      </div>
    </div>
  </div>
</section>
