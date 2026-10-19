@addStyle(asset('vendor/swiper/swiper-bundle.min.css'))
@addScript(asset('vendor/swiper/swiper-bundle.min.js'))

<section class="module-item section-hero {{ $design ? 'module-item-design' : ''}}" id="module-{{ $module_id }}" aria-roledescription="carousel" aria-label="{{ $content['title'] ?? site_brand_name() }}" @include('design.partials.section_style')>
  <div class="module-info   {{ $content['module_size'] ?? 'w-100' }}">
    <div id="slideshow-{{ $module_id }}" class="swiper module-swiper-{{ $module_id }} module-slideshow">
      <div class="swiper-wrapper">
        @foreach($content['images'] as $image)
        <div class="swiper-slide">
          @php
            $slideLink = $image['link']['link'] ?? '';
          @endphp
          @if ($slideLink)
          <a href="{{ $slideLink }}" class="d-flex justify-content-center">
          @else
          <div class="d-flex justify-content-center">
          @endif
            @if (($image['type'] ?? 'image') == 'video')
            <video src="{{ $image['image'] }}" class="img-fluid w-100" controls loop muted></video>
            @else
            <img src="{{ $image['image'] }}" @if ($loop->first) fetchpriority="high" @else loading="lazy" @endif decoding="async" class="img-fluid seo-img" alt="{{ $image['image_alt'] ?? '' }}">
            @endif
          @if ($slideLink)</a>@else</div>@endif
        </div>
        @endforeach
      </div>
      <div class="swiper-pagination slideshow-pagination-{{ $module_id }}"></div>
      <button type="button" class="swiper-button-prev slideshow-btnprev-{{ $module_id }}" aria-label="@lang('pagination.previous')"></button>
      <button type="button" class="swiper-button-next slideshow-btnnext-{{ $module_id }}" aria-label="@lang('pagination.next')"></button>
    </div>
  </div>

  <script>
    function slideshowSwiper() {
      new Swiper ('.module-swiper-{{ $module_id }}', {
        loop: {{ count($content['images']) > 1 ? 'true' : 'false' }},
        // Static by default: visitors control the pace, and reduced-motion
        // users receive the same predictable first-slide experience.
        autoplay: false,
        pauseOnMouseEnter: true,
        clickable :true,

        // 如果需要分页器
        pagination: {
          el: '.slideshow-pagination-{{ $module_id }}',
          clickable :true
        },

        // 如果需要前进后退按钮
        navigation: {
          nextEl: '.slideshow-btnnext-{{ $module_id }}',
          prevEl: '.slideshow-btnprev-{{ $module_id }}',
        },
      })
    }

  @if ($design)
    bk.loadStyle('{{ asset('vendor/swiper/swiper-bundle.min.css') }}');
    bk.loadScript('{{ asset('vendor/swiper/swiper-bundle.min.js') }}', () => {
      slideshowSwiper();
    })
  @else
    slideshowSwiper();
  @endif
  </script>
</section>
