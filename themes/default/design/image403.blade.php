<section class="module-item {{ $design ? 'module-item-design' : ''}}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  <div class="module-image-403 banner-magnify-hover module-info  ">
    <div class="{{ $content['module_size'] ?? 'container-fluid' }}">
      @if ($content['title'])
      <div class="module-title">{{ $content['title'] }} <div class="wave-line"></div></div>
      @endif
      @if ($content['sub_title'])
      <div class="module-sub-title">{{ $content['sub_title'] }}</div>
      @endif

      <div class="row g-3 g-lg-4">
        @foreach ($content['images'] as $item)
        <div class="col-12 col-sm-6 col-md-3">
          @if (!empty($item['link']['link']))<a class="image-wrap" href="{{ $item['link']['link'] }}">@else<div class="image-wrap">@endif
          <img src="{{ $item['image'] }}" loading="lazy" decoding="async" alt="{{ $item['image_alt'] ?? ''}}" class="img-fluid seo-img">
          @if ($item['title'])<span class="title">{{ $item['title'] }}</span>@endif
          @if ($item['sub_title'])<span class="sub-title">{{ $item['sub_title'] }}</span>@endif
          @if (!empty($item['link']['link']))</a>@else</div>@endif
        </div>
        @endforeach
      </div>
    </div>
  </div>
</section>
