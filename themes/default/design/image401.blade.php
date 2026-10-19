@php
  $images = $content['images'] ?? [];
@endphp
<section class="module-item {{ $design ? 'module-item-design' : ''}}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  <div class="module-image-plus banner-magnify-hover module-info  ">
    <div class="{{ $content['module_size'] ?? 'container-fluid' }}">
      <div class="row g-3 g-lg-4">
        <div class="col-md-6 col-12 mb-3 mb-md-0">
          @if (isset($images[0]))<a class="image-wrap" href="{{ $images[0]['link']['link'] }}"><img src="{{ $images[0]['image'] }}" alt="{{ $images[0]['image_alt'] }}" class="img-fluid seo-img"></a>@endif
        </div>
        <div class="col-md-6 col-12">
          <div class="module-image-plus-top">
            @if (isset($images[1]))<a class="image-wrap" href="{{ $images[1]['link']['link'] }}"><img src="{{ $images[1]['image'] }}" alt="{{ $images[1]['image_alt'] }}" class="img-fluid seo-img"></a>@endif
            @if (isset($images[2]))<a href="{{ $images[2]['link']['link'] }}" class="right image-wrap"><img src="{{ $images[2]['image'] }}" alt="{{ $images[2]['image_alt'] }}" class="img-fluid seo-img"></a>@endif
          </div>
          <div class="module-image-plus-bottom">@if (isset($images[3]))<a class="image-wrap" href="{{ $images[3]['link']['link'] }}"><img src="{{ $images[3]['image'] }}" alt="{{ $images[3]['image_alt'] }}" class="img-fluid seo-img"></a>@endif</div>
        </div>
      </div>
    </div>
  </div>
</section>
